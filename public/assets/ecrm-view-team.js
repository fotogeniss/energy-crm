/* Energy CRM — team: the members at each role, and adding one. */

import { api, banner, checkedProviderIds, esc, failureText, fetch, H, postJson, providerChipsHtml, toast, viewEl, wireProviderChips } from '@energy-crm/util';
import { initials, tint } from '@energy-crm/format';
import { openPartner } from '@energy-crm/navigate';

export function loadTeam(flash) {
	var view = viewEl('team');
	fetch(api('/team'), { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) { renderTeam(view, d, flash); })
		.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα φόρτωσης.</div></div>'; });
}
function renderTeam(view, d, flash) {
	// Ο Καταχωρητής συγχωνεύτηκε στον Πωλητή (Roles::matrix() v6, 25/08) --
	// το /team επιστρέφει πλέον μόνο Πωλητές (οι Συνεργάτες φιλτράρονται ήδη
	// στο TeamController::index() και ζουν στο /network), οπότε τα δύο tabs
	// δεν χώριζαν πια τίποτα. Ένας πίνακας, χωρίς tabs -- δες
	// docs/UI-TEAM-MERGE-PROMOTE.html (§1.8, εγκρίθηκε 25/08).
	var members = d.members || [];

	// Η στήλη «Τηλέφωνο» τύπωνε ΠΑΝΤΑ παύλα: δεν υπάρχει τηλέφωνο μέλους
	// πουθενά στη βάση, οπότε ήταν στήλη που δεν μπορούσε να δείξει τίποτα —
	// «διακοσμητικό ψέμα», ίδια οικογένεια με την κάρτα «ΑΙ βοήθεια» του §6β.
	// Στη θέση της μπαίνει το m.contracts, που το /team ΗΔΗ έστελνε και που
	// αυτή η οθόνη πετούσε (το ecrm-view-network.js το χρησιμοποιεί κανονικά).
	// Δεδομένο που υπήρχε και αγνοούνταν, πλάι σε στήλη που δεν είχε τι να πει.
	var rows = members.map(function (m) {
		// Η προαγωγή είναι μη αναστρέψιμη με ένα κλικ (αλλάζει ρόλο, όχι
		// κατάσταση) -- εμφανίζεται μόνο σε ενεργά μέλη, ίδιο σύνορο με το
		// mockup. Ο ίδιος confirm() που ήδη χρησιμοποιεί η «Αφαίρεση» παρακάτω,
		// με το ίδιο μήνυμα που εγκρίθηκε στο mockup.
		var promoteBtn = m.active
			? '<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-promote="' + m.id + '" data-name="' + esc(m.name) + '">Προαγωγή σε Συνεργάτη</button> '
			: '';

		return '<tr class="ecrm-rowlink" data-member="' + m.id + '">' +
			'<td><span class="ecrm-cell-cust"><span class="ecrm-cell-mark ecrm-cell-mark--cust" style="--h:' + tint(m.name) + '">' + esc(initials(m.name)) + '</span><span>' + esc(m.name) + '</span></span></td>' +
			'<td class="ecrm-col-sec">' + esc(m.email) + '</td>' +
			'<td class="ecrm-tlnum">' + (m.contracts || 0) + '</td>' +
			'<td>' + (m.active ? '<span class="ecrm-badge ecrm-badge--active">Ενεργός</span>' : '<span class="ecrm-badge ecrm-badge--cancelled">Ανενεργός</span>') + '</td>' +
			'<td>' + promoteBtn +
			'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-toggle="' + m.id + '">' + (m.active ? 'Απενεργοποίηση' : 'Ενεργοποίηση') + '</button> ' +
			'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-remove="' + m.id + '">Αφαίρεση</button></td>' +
			'</tr>';
	}).join('');

	var table = members.length
		? '<div class="ecrm-tablewrap"><table class="ecrm-table"><thead><tr><th>Ονοματεπώνυμο</th><th class="ecrm-col-sec">Email</th><th>Συμβάσεις</th><th>Κατάσταση</th><th>Ενέργειες</th></tr></thead><tbody>' + rows + '</tbody></table></div>'
		: '<div class="ecrm-emptybox"><div class="ecrm-emptybox__txt">Κανένα μέλος στην ομάδα ακόμα.</div></div>';

	var canManage = d.can_manage;
	var addForm = canManage ? (
		'<div class="ecrm-card ecrm-addform" data-addwrap hidden><div class="ecrm-step">Νέο μέλος · Πωλητής</div>' +
		'<div class="ecrm-grid">' +
		'<label class="ecrm-field"><span class="ecrm-field__label">Ονοματεπώνυμο</span><input class="ecrm-input" data-f="name"></label>' +
		'<label class="ecrm-field"><span class="ecrm-field__label">Email</span><input class="ecrm-input" type="email" data-f="email"></label>' +
		'</div>' +
		// Παρόχοι που θα βλέπει (279): φορτώνονται με το πρώτο άνοιγμα της
		// φόρμας, όχι εδώ -- δεν αξίζει δεύτερο αίτημα στην κάθε φόρτωση της
		// οθόνης για κάτι που ο manager μπορεί να μην ανοίξει καθόλου.
		'<div class="ecrm-step" style="margin-top:14px" data-newprov-step hidden>Πάροχοι που θα βλέπει ' +
		'<span><a href="#" class="ecrm-plink" data-pall>Ολοι</a><a href="#" class="ecrm-plink" data-pnone>Κανένας</a></span></div>' +
		'<div data-newprov-chips></div>' +
		'<div class="ecrm-hint" data-newprov-hint hidden>Βλέπεις μόνο όσους παρόχους έχεις κι εσύ.</div>' +
		'<button type="button" class="ecrm-btn ecrm-btn--primary ecrm-btn--sm" data-add-member>+ Προσθήκη</button>' +
		'<div class="ecrm-ai-status" data-member-msg></div></div>'
	) : '';

	view.innerHTML =
		'<header class="ecrm-head"><h2 class="ecrm-title">Η ομάδα μου</h2><p class="ecrm-sub">Διαχείριση πωλητών του γραφείου σου.</p></header>' +
		// Το αποτέλεσμα της δημιουργίας μέλους, πάνω από τη λίστα που μόλις
		// ξαναφορτώθηκε -- αλλιώς το username και το αν έφυγε η πρόσκληση
		// χάνονταν μαζί με την παλιά φόρμα.
		(flash ? '<div class="ecrm-import-banner is-ok">' + esc(flash) + '</div>' : '') +
		'<div class="ecrm-card">' +
		'<div class="ecrm-listhead"><span class="ecrm-listhead__count">' + members.length + ' πωλητές</span>' +
		(canManage ? '<span><button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-show-bulk>Πάροχοι ομάδας</button> ' +
			'<button type="button" class="ecrm-btn ecrm-btn--primary ecrm-btn--sm" data-show-add>+ Νέος Πωλητής</button></span>' : '') + '</div>' +
		table + '</div>' + addForm +
		(canManage ? '<div class="ecrm-card" data-bulkwrap hidden></div>' : '');

	// Παρόχοι (279): φορτώνονται μία φορά ανά άνοιγμα οθόνης, μοιρασμένοι
	// ανάμεσα στη φόρμα «Νέο μέλος» και το «Πάροχοι ομάδας» -- το GET
	// /team/providers είναι το ίδιο ερώτημα και για τα δύο.
	var providersPromise = null;
	function loadProviders() {
		if (!providersPromise) {
			providersPromise = fetch(api('/team/providers'), { headers: H() }).then(function (r) { return r.json(); });
		}
		return providersPromise;
	}

	var showAdd = view.querySelector('[data-show-add]');
	if (showAdd) showAdd.addEventListener('click', function () {
		var w = view.querySelector('[data-addwrap]');
		if (!w) return;
		w.hidden = !w.hidden;
		if (w.hidden) return;
		w.scrollIntoView({ behavior: 'smooth' });
		var chipsBox = w.querySelector('[data-newprov-chips]');
		if (chipsBox && !chipsBox.getAttribute('data-loaded')) {
			chipsBox.innerHTML = '<div class="ecrm-empty">Φόρτωση…</div>';
			loadProviders().then(function (d) {
				if (!d || !d.ok) { chipsBox.innerHTML = '<div class="ecrm-empty">Δεν φόρτωσαν οι πάροχοι.</div>'; return; }
				var providers = d.providers || [];
				// Απόφαση ιδιοκτήτη 21/09: στη δημιουργία, όλοι όσους βλέπει ο
				// manager τσεκαρισμένοι εξ αρχής -- ο ίδιος διαλέγει ποιους θα
				// αφαιρέσει, όχι ποιους θα προσθέσει.
				var ids = providers.map(function (p) { return parseInt(p.id, 10); });
				chipsBox.innerHTML = providerChipsHtml(providers, ids, false);
				chipsBox.setAttribute('data-loaded', '1');
				wireProviderChips(w);
				var step = w.querySelector('[data-newprov-step]'); if (step) step.hidden = false;
				var hint = w.querySelector('[data-newprov-hint]'); if (hint) hint.hidden = false;
			}).catch(function () { chipsBox.innerHTML = '<div class="ecrm-empty">Δεν φόρτωσαν οι πάροχοι.</div>'; });
		}
	});

	var showBulk = view.querySelector('[data-show-bulk]');
	if (showBulk) showBulk.addEventListener('click', function () {
		var w = view.querySelector('[data-bulkwrap]');
		if (!w) return;
		w.hidden = !w.hidden;
		if (w.hidden) return;
		w.scrollIntoView({ behavior: 'smooth' });
		renderBulk(w, loadProviders);
	});

	// Η γραμμή ανοίγει την καρτέλα, ΕΚΤΟΣ αν πατήθηκε κουμπί μέσα της: τα
	// «Απενεργοποίηση» και «Αφαίρεση» κάθονται στην ίδια γραμμή, και χωρίς
	// αυτόν τον έλεγχο κάθε τους πάτημα θα άνοιγε και την καρτέλα από κάτω.
	view.querySelectorAll('[data-member]').forEach(function (row) {
		row.addEventListener('click', function (ev) {
			if (ev.target.closest('button')) { return; }
			openPartner(this.getAttribute('data-member'));
		});
	});
	view.querySelectorAll('[data-toggle]').forEach(function (b) { b.addEventListener('click', function () { teamOp(this.getAttribute('data-toggle'), 'toggle'); }); });
	view.querySelectorAll('[data-remove]').forEach(function (b) { b.addEventListener('click', function () { if (confirm('Αφαίρεση μέλους από την ομάδα;')) teamOp(this.getAttribute('data-remove'), 'remove'); }); });
	view.querySelectorAll('[data-promote]').forEach(function (b) {
		b.addEventListener('click', function () {
			var name = this.getAttribute('data-name');
			var msg = name + ' θα αποκτήσει δικαίωμα να προσκαλεί δικά της/του μέλη και θα βλέπει τη δική ' +
				'της/του ομάδα από κάτω. Παραμένει στη δική σου ομάδα -- δεν φεύγει πουθενά.\n\n' +
				'Προαγωγή σε Συνεργάτη;';
			if (confirm(msg)) teamOp(this.getAttribute('data-promote'), 'promote');
		});
	});
	var addBtn = view.querySelector('[data-add-member]');
	if (addBtn) addBtn.addEventListener('click', function () { addMember(view, this); });
}
function teamOp(id, op) {
	fetch(api('/team/' + id), { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H()), body: JSON.stringify({ op: op }) })
		.then(function (r) { return r.json(); })
		.then(function (res) {
			if (res && res.ok) {
				// Η προαγωγή βγάζει το μέλος από αυτή τη λίστα (πάει στο
				// /network, όπως κάθε Συνεργάτης) -- ίδιο μήνυμα μοτίβο με τα
				// άλλα δύο, χωρίς ξεχωριστό κλάδο για το toast.
				toast(op === 'promote' ? 'Έγινε Συνεργάτης.' : 'Έγινε.');
				loadTeam();
			} else {
				toast((res && res.error) || 'Αποτυχία.', false);
			}
		})
		.catch(function () { toast('Σφάλμα δικτύου.', false); });
}
function addMember(view, btn) {
	var get = function (f) { var el = view.querySelector('[data-f="' + f + '"]'); return el ? el.value.trim() : ''; };
	var chipsWrap = view.querySelector('[data-addwrap]');
	var out = view.querySelector('[data-member-msg]');
	var payload = { name: get('name'), email: get('email'), role: 'ecrm_seller' };
	// Αν οι παρόχοι δεν πρόλαβαν καν να φορτώσουν (η φόρμα ανοίχτηκε και
	// πατήθηκε αμέσως), δεν στέλνουμε provider_ids καθόλου -- ο server βάζει
	// τότε όλους όσους έχει ο δημιουργός, το ίδιο προεπιλεγμένο αποτέλεσμα.
	if (chipsWrap && chipsWrap.querySelector('[data-newprov-chips] [data-pchip]')) {
		payload.provider_ids = checkedProviderIds(chipsWrap);
		// Ο server το αρνείται ούτως ή άλλως («Διάλεξε τουλάχιστον έναν
		// πάροχο.») -- εδώ απλώς γλιτώνουμε το ταξίδι.
		if (!payload.provider_ids.length) { banner(out, 'Διάλεξε τουλάχιστον έναν πάροχο.', false); return; }
	}
	if (!payload.name || !payload.email) { banner(out, 'Συμπλήρωσε όνομα και email.', false); return; }

	// Κάθε αποτέλεσμα γράφεται ΜΕΣΑ στη φόρμα και μένει εκεί. Ως τώρα ένα λάθος
	// ήταν toast κάτω-κάτω στην οθόνη για 4 δευτερόλεπτα, και η επιτυχία
	// γραφόταν στη φόρμα και σβηνόταν αμέσως από το loadTeam() που την
	// ξαναζωγράφιζε: και στις δύο περιπτώσεις ο manager έβλεπε «δεν έγινε
	// τίποτα» (αναφορά ιδιοκτήτη 21/09).
	var label = btn.textContent;
	btn.disabled = true; btn.textContent = 'Δημιουργία…';
	if (out) out.innerHTML = '';
	postJson(api('/team'), payload)
		.then(function (res) {
			var d = res.d;
			if (!d || !d.ok) { banner(out, failureText(res), false); return; }
			// invited:false δεν σημαίνει αποτυχία -- ο λογαριασμός υπάρχει κανονικά,
			// απλά δεν έφυγε το email (π.χ. SMTP δεν έχει ρυθμιστεί ακόμα στην
			// παραγωγή). Ο manager πρέπει να το μάθει ρητά, όχι να υποθέσει ότι
			// στάλθηκε -- βλ. docs/UI-TEAM-INVITE.html (§1.8, εγκρίθηκε).
			loadTeam(d.invited
				? 'Δημιουργήθηκε ο/η ' + payload.name + '. Username: ' + d.username + '. Στάλθηκε email πρόσκλησης με σύνδεσμο ορισμού κωδικού (ισχύει 24 ώρες).'
				: 'Δημιουργήθηκε ο λογαριασμός ' + d.username + ', αλλά το email πρόσκλησης ΔΕΝ στάλθηκε. Δώσε του το username και πες του να πατήσει «Ξέχασα τον κωδικό» στην οθόνη σύνδεσης.');
		})
		.catch(function () { banner(out, 'Σφάλμα δικτύου -- δεν έφτασε στον server. Ξαναδοκίμασε.', false); })
		.finally(function () { btn.disabled = false; btn.textContent = label; });
}

/**
 * «Πάροχοι ομάδας» (279, §3 της μακέτας): ένας πάροχος ανά γραμμή, πόσοι από
 * τους ΑΜΕΣΟΥΣ υφισταμένους τον έχουν, «Σε όλους» / «Αφαίρεση από όλους».
 * Μοιράζεται το ίδιο GET /team/providers με τη φόρμα «Νέο μέλος» (loadProviders,
 * cache-αρισμένο στον καλούντα) -- όχι δεύτερο αίτημα για το ίδιο δεδομένο.
 */
function renderBulk(wrap, loadProviders) {
	wrap.innerHTML = '<div class="ecrm-step">Πάροχοι ομάδας</div><div class="ecrm-empty">Φόρτωση…</div>';
	loadProviders().then(function (d) {
		if (!d || !d.ok) { wrap.innerHTML = '<div class="ecrm-empty">Δεν φόρτωσαν οι πάροχοι.</div>'; return; }
		var members = d.members || 0;
		var rows = (d.providers || []).map(function (p) {
			var has = p.has || 0;
			var pct = members ? Math.round((has / members) * 100) : 0;
			var grantBtn = has < members
				? '<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-bulk-op="grant" data-bulk-id="' + p.id + '">Σε όλους</button>'
				: '';
			var revokeBtn = has > 0
				? '<button type="button" class="ecrm-btn ecrm-btn--danger ecrm-btn--sm" data-bulk-op="revoke" data-bulk-id="' + p.id + '" data-bulk-name="' + esc(p.name) + '">Αφαίρεση από όλους</button>'
				: '';
			return '<tr><td>' + esc(p.name) + '</td>' +
				'<td><span class="ecrm-ptbl__cnt"><b>' + has + '</b> από ' + members + '</span>' +
				'<div class="ecrm-ptbl__bar"><i style="width:' + pct + '%"></i></div></td>' +
				'<td class="ecrm-ptbl__act">' + grantBtn + ' ' + revokeBtn + '</td></tr>';
		}).join('');

		wrap.innerHTML = '<div class="ecrm-step">Πάροχοι ομάδας</div>' +
			(rows
				? '<div class="ecrm-tablewrap"><table class="ecrm-ptbl"><thead><tr><th>Πάροχος</th><th>Το έχουν</th><th></th></tr></thead><tbody>' + rows + '</tbody></table></div>'
				: '<div class="ecrm-empty">Δεν έχεις κανέναν πάροχο ακόμα.</div>') +
			'<div class="ecrm-hint">Μόνο οι άμεσοι υφιστάμενοί σου. Οι υποσυνεργάτες σου αποφασίζουν οι ίδιοι για τους δικούς τους.</div>';

		wrap.querySelectorAll('[data-bulk-op]').forEach(function (b) {
			b.addEventListener('click', function () {
				var op = this.getAttribute('data-bulk-op');
				var pid = this.getAttribute('data-bulk-id');
				if (op === 'revoke') {
					var name = this.getAttribute('data-bulk-name');
					var msg = 'Αφαιρείς ' + name + ' από όλους τους άμεσους υφισταμένους σου. Όσοι έχουν δική ' +
						'τους ομάδα, το χάνουν κι εκείνοι από κάτω τους. Οι αιτήσεις που έχουν ήδη γίνει μένουν ' +
						'όπως είναι.\n\nΣυνέχεια;';
					if (!confirm(msg)) return;
				}
				bulkOp(wrap, pid, op);
			});
		});
	}).catch(function () { wrap.innerHTML = '<div class="ecrm-empty">Δεν φόρτωσαν οι πάροχοι.</div>'; });
}

function bulkOp(wrap, providerId, op) {
	fetch(api('/team/providers'), {
		method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H()),
		body: JSON.stringify({ provider_id: providerId, op: op })
	})
		.then(function (r) { return r.json(); })
		.then(function (res) {
			if (!res || !res.ok) { toast((res && res.error) || 'Αποτυχία.', false); return; }
			toast(res.below > 0 ? 'Έγινε -- επηρέασε και ' + res.below + ' από κάτω.' : 'Έγινε.');
			// Ξαναζητά φρέσκο /team/providers -- η κρυφή μνήμη του καλούντος
			// θα έδειχνε τα παλιά "has" νούμερα.
			renderBulk(wrap, function () {
				return fetch(api('/team/providers'), { headers: H() }).then(function (r) { return r.json(); });
			});
		})
		.catch(function () { toast('Σφάλμα δικτύου.', false); });
}
