/* Ο πρώτος αυτόματος έλεγχος JS αυτού του plugin.
 *
 * Υπάρχει για ΕΝΑ πράγμα πάνω απ' όλα: να αποδείξει ότι ο wizard των τεσσάρων
 * βημάτων ΔΕΝ αλλάζει το payload. Το collect() διαβάζει όλα τα .ecrm-input του
 * root ανεξάρτητα από ορατότητα, οπότε το σώμα του POST πρέπει να είναι
 * πανομοιότυπο από όποιο βήμα κι αν πατηθεί «Αποθήκευση». Αυτό δεν το
 * συμπεραίνουμε διαβάζοντας — το τρέχουμε και το συγκρίνουμε.
 *
 * Τρέξε το με:  npm install  &&  npm test
 */
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

const html = fs.readFileSync(path.join(__dirname, 'form.html'), 'utf8');
const js = fs.readFileSync(path.join(__dirname, 'form.js'), 'utf8');

const dom = new JSDOM(
	'<!doctype html><html><body><div class="ecrm" data-standalone>' + html + '</div></body></html>',
	{ runScripts: 'outside-only', pretendToBeVisual: true }
);
const w = dom.window;
const posts = [];

w.ECRM = { rest: 'http://x/wp-json/ecrm/v1', nonce: 'n' };
w.fetch = function (url, opts) {
	if (opts && opts.method === 'POST' && /\/contracts$/.test(url)) {
		posts.push(JSON.parse(opts.body));
		// ok:false επίτηδες: κρατά το state ακίνητο ώστε δύο διαδοχικές
		// αποθηκεύσεις να είναι συγκρίσιμες (το doSave γράφει contract_id
		// μόνο σε επιτυχία).
		return Promise.resolve({ json: function () { return Promise.resolve({ ok: false, error: 'stub' }); } });
	}
	const body = { ok: true, rows: [], programs: [], entries: [], matches: [], fields: {} };
	if (/\/providers/.test(url)) {
		body.providers = [{ id: 7, name: 'Protergia', energy_types: 'power,gas', logo_url: '' }];
		body.programs = [{ id: 3, provider_id: 7, energy_type: 'power', name: 'Value 24μηνο', code: 'V24' }];
	}
	return Promise.resolve({ json: function () { return Promise.resolve(body); } });
};
w.confirm = function () { return true; };
w.alert = function () {};
w.scrollTo = function () {};
w.HTMLElement.prototype.scrollIntoView = function () {};

let fails = 0, passes = 0;
function ok(name, cond, extra) {
	if (cond) { passes++; console.log('  ✓ ' + name); }
	else { fails++; console.log('  ✗ ' + name + (extra ? '  → ' + extra : '')); }
}
function tick() { return new Promise(function (r) { setTimeout(r, 0); }); }

w.eval(js);
const root = w.document.querySelector('.ecrm-form');
w.ECRMForm.init(root);

const q = function (s) { return root.querySelector(s); };
const qa = function (s) { return Array.prototype.slice.call(root.querySelectorAll(s)); };
const step = function () {
	return qa('[data-wstep]').filter(function (p) { return !p.hidden; })
		.map(function (p) { return p.getAttribute('data-wstep'); }).join(',');
};

(async function () {
	await tick(); await tick(); await tick();

	console.log('\n1. αρχική κατάσταση');
	ok('φαίνεται ΑΚΡΙΒΩΣ ένα βήμα, και είναι το 1ο', step() === '1', 'ορατά: ' + step());
	ok('«Πίσω» κρυμμένο στο βήμα 1', q('[data-wprev]').hidden);
	ok('«Συνέχεια» ορατό', !q('[data-wnext]').hidden);
	ok('«Οριστικοποίηση» κρυμμένη εκτός τελευταίου', q('[data-finalize]').hidden);
	ok('«Προσωρινή Αποθήκευση» ορατή', !q('[data-save-draft]').hidden);
	ok('βήματα 2-4 κλειδωμένα', qa('[data-wgo]').slice(1).every(function (b) { return b.disabled; }));

	console.log('\n2. ο μοναδικός φράχτης: χωρίς πάροχο δεν προχωράς');
	w.__toasts.length = 0;
	q('[data-wnext]').click();
	ok('έμεινε στο βήμα 1', step() === '1', 'ορατά: ' + step());
	ok('και είπε γιατί', w.__toasts.length === 1 && /πάροχο/.test(w.__toasts[0].msg), JSON.stringify(w.__toasts));

	console.log('\n3. με πάροχο, προχωράει');
	const prov = q('.ecrm-provider');
	ok('ο πάροχος ζωγραφίστηκε', !!prov);
	prov.click();
	await tick();
	w.__toasts.length = 0;
	q('[data-wnext]').click();
	ok('πήγε στο βήμα 2', step() === '2', 'ορατά: ' + step());
	ok('χωρίς καμία άρνηση', w.__toasts.length === 0, JSON.stringify(w.__toasts));
	ok('«Πίσω» εμφανίστηκε', !q('[data-wprev]').hidden);
	ok('το βήμα 2 ξεκλείδωσε στη μπάρα', !qa('[data-wgo]')[1].disabled);
	ok('το βήμα 4 ΠΑΡΑΜΕΝΕΙ κλειδωμένο', qa('[data-wgo]')[3].disabled);
	ok('το βήμα 1 σημάνθηκε is-done', qa('[data-wgo]')[0].classList.contains('is-done'));

	console.log('\n4. συμπλήρωση πεδίων στο βήμα 3');
	q('[data-wnext]').click();
	ok('βήμα 3', step() === '3');
	root.querySelector('[name="afm"]').value = '094014201';
	root.querySelector('[name="term_months"]').value = '24';
	root.querySelector('[data-notes]').value = 'δοκιμή';

	console.log('\n5. ΤΟ ΚΡΙΣΙΜΟ: το payload δεν εξαρτάται από το βήμα');
	q('[data-save-draft]').click();
	await tick(); await tick();
	const atStep3 = posts[posts.length - 1];
	ok('έγινε POST από το βήμα 3', !!atStep3);

	q('[data-wnext]').click();
	ok('βήμα 4', step() === '4');
	ok('«Οριστικοποίηση» τώρα ορατή', !q('[data-finalize]').hidden);
	ok('«Συνέχεια» κρύφτηκε', q('[data-wnext]').hidden);
	q('[data-save-draft]').click();
	await tick(); await tick();
	const atStep4 = posts[posts.length - 1];
	ok('ΙΔΙΟ payload στο βήμα 3 και στο 4', JSON.stringify(atStep3) === JSON.stringify(atStep4),
		'\n      3: ' + JSON.stringify(atStep3) + '\n      4: ' + JSON.stringify(atStep4));

	qa('[data-wgo]')[0].click();
	ok('γύρισε στο βήμα 1', step() === '1');
	q('[data-save-draft]').click();
	await tick(); await tick();
	const atStep1 = posts[posts.length - 1];
	ok('ΙΔΙΟ payload και από το βήμα 1', JSON.stringify(atStep1) === JSON.stringify(atStep4),
		'\n      1: ' + JSON.stringify(atStep1));
	ok('και το payload περιέχει όσα γράφτηκαν στο βήμα 3',
		!!atStep1 && atStep1.afm === '094014201' && atStep1.term_months === '24' && atStep1.notes === 'δοκιμή',
		JSON.stringify(atStep1));

	console.log('\n6. reset: πίσω στο 1 και ξανακλείδωμα');
	w.ECRMForm.reset();
	ok('βήμα 1', step() === '1');
	ok('τα 2-4 ξανακλείδωσαν', qa('[data-wgo]').slice(1).every(function (b) { return b.disabled; }));

	/* Το ΑΦΜ κρίνει ΠΟΥ ανοίγει η επεξεργασία, και είναι ο ίδιος φύλακας που
	   κρίνει αν θα τρέξει η αυτόματη εξαγωγή. Γεμάτο ΑΦΜ σημαίνει «τα έγγραφα
	   διαβάστηκαν ήδη», άρα το βήμα 2 (Έγγραφα & AI) δεν έχει τι να προσφέρει
	   και ο συνεργάτης προσγειώνεται κατευθείαν στα στοιχεία. Κενό ΑΦΜ σημαίνει
	   το αντίθετο, και τότε ξεκινά από την αρχή όπως πάντα.

	   Και τα δύο ελέγχονται εδώ: ένα σήμα με δύο αποτελέσματα δεν αποδεικνύεται
	   με τη μία του πλευρά. */
	console.log('\n7. επεξεργασία: ξεκλειδώνουν και τα τέσσερα');
	w.ECRMForm.edit({
		id: 42, customer_id: 5, status: 'draft', provider_id: 7, program_id: 3,
		energy_type: 'power', category: 'home', customer_type: 'individual', code: 'CR-1', afm: '094014201'
	});
	await tick();
	ok('με ΑΦΜ, ανοίγει κατευθείαν στο βήμα 3', step() === '3', 'ορατά: ' + step());
	ok('και τα τέσσερα είναι κλικαριστά', qa('[data-wgo]').every(function (b) { return !b.disabled; }));
	qa('[data-wgo]')[3].click();
	ok('πηδάει κατευθείαν στο 4', step() === '4');

	console.log('\n8. επεξεργασία χωρίς ΑΦΜ: ξεκινά από το βήμα 1');
	// reset() πρώτα, όπως κάνει και το openEdit() της εφαρμογής: η setField()
	// αγνοεί κενή τιμή, οπότε χωρίς καθάρισμα θα έμενε στο DOM το ΑΦΜ του (7)
	// και ο έλεγχος θα μετρούσε την προηγούμενη αίτηση.
	w.ECRMForm.reset();
	w.ECRMForm.edit({
		id: 43, customer_id: 6, status: 'draft', provider_id: 7, program_id: 3,
		energy_type: 'power', category: 'home', customer_type: 'individual', code: 'CR-2', afm: ''
	});
	await tick();
	ok('χωρίς ΑΦΜ, ξεκινά στο βήμα 1', step() === '1', 'ορατά: ' + step());
	ok('αλλά και πάλι ξεκλείδωτα και τα τέσσερα', qa('[data-wgo]').every(function (b) { return !b.disabled; }));

	console.log('\n' + (fails ? '✗ ΑΠΟΤΥΧΙΕΣ: ' + fails : '✓ ΟΛΑ ΠΕΡΑΣΑΝ') + '  (' + passes + ' έλεγχοι)');
	process.exit(fails ? 1 : 0);
})();
