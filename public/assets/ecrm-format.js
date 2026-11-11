/* Energy CRM — turning stored values into what a person reads.
 *
 * Pure functions, every one: same input, same output, no DOM, no network, no
 * state. That is the whole entry requirement for this module, and it is what
 * makes them safe to pull out of the app shell ahead of the views that use
 * them — nothing here can behave differently for having moved.
 *
 * Anything that touches the document, the clipboard or the network stays in
 * the view that owns it.
 */

/** Greek uppercase without the accents, which uppercase Greek does not take. */
export function up(str) {
	try {
		return String(str).toLocaleUpperCase('el').normalize('NFD').replace(/[\u0300-\u036f]/g, '');
	} catch (e) {
		return String(str).toUpperCase();
	}
}

export function energyLabel(t) {
	return t === 'gas' ? 'Φυσικό Αέριο' : (t === 'mobile' ? 'Κινητή Τηλεφωνία' : 'Ηλεκτρισμός');
}

/**
 * Οι αποθηκευμένες ώρες είναι ΤΟΠΙΚΕΣ, χωρίς σήμανση ζώνης — και διαβάζονται ως
 * τοπικές.
 *
 * ## Τι άλλαξε στις 22/08/2026 και γιατί
 *
 * Εδώ έγραφε `+ 'Z'`, με σχόλιο που δήλωνε «stored timestamps are UTC». Ήταν
 * λάθος για τις μισές στήλες, και **μετρήθηκε**:
 *
 *     mysql = 13:48:33   ← ό,τι γράφει η βάση (created_at, ON UPDATE, NOW())
 *     wp    = 10:48:33   ← ό,τι γράφει η PHP  (current_time('mysql'))
 *
 * Η MySQL έγραφε ώρα Αθήνας, το WordPress UTC, επειδή **το site δεν είχε ποτέ
 * οριστεί ζώνη** (`timezone_string` κενό, `gmt_offset` 0). Το `'Z'` πρόσθετε τη
 * διαφορά **δεύτερη φορά** σε ό,τι είχε γράψει η βάση: το `timeAgo()` έβγαζε
 * αρνητικό και τύπωνε «μόλις τώρα» για ό,τι είχε γίνει τις τελευταίες τρεις
 * ώρες, και το `fmtDate()` έδειχνε την **επόμενη μέρα** για ό,τι είχε γίνει
 * μετά τις 21:00.
 *
 * Με τη ζώνη του site ορισμένη σε Europe/Athens, **όλα** τα αποθηκευμένα είναι
 * πλέον ώρα Αθήνας — και ο browser του συνεργάτη είναι κι αυτός Αθήνα. Η σωστή
 * ανάγνωση είναι η τοπική.
 *
 * Το `.replace(' ', 'T')` **μένει**: το «2026-08-22 13:48» με κενό δεν είναι
 * ISO και το parse του είναι στην κρίση του browser — ο Safari έχει επιστρέψει
 * Invalid Date γι' αυτό. Με το `T` και χωρίς ζώνη, η προδιαγραφή λέει ρητά
 * **τοπική ώρα**.
 */
export function fmtDate(iso) {
	if (!iso) return '';

	var d = new Date(iso.replace(' ', 'T'));
	var p = function (n) { return (n < 10 ? '0' : '') + n; };

	return p(d.getDate()) + '/' + p(d.getMonth() + 1) + '/' + d.getFullYear();
}

/** Ίδια ανάγνωση με τη fmtDate: τοπική, χωρίς 'Z'. */
export function timeAgo(iso) {
	if (!iso) return '';

	var d = new Date(iso.replace(' ', 'T')), s = (Date.now() - d.getTime()) / 1000;

	if (s < 60) return 'μόλις τώρα';
	if (s < 3600) return Math.floor(s / 60) + 'λ πριν';
	if (s < 86400) return Math.floor(s / 3600) + 'ω πριν';

	return Math.floor(s / 86400) + 'μ πριν';
}

/** (353) Πόσα λεπτά πέρασαν. Ίδια ανάγνωση με τη timeAgo: τοπική, χωρίς 'Z'. Άκυρο → null. */
export function minutesAgo(iso) {
	if (!iso) return null;

	var t = new Date(String(iso).replace(' ', 'T')).getTime();

	return isNaN(t) ? null : Math.max(0, Math.floor((Date.now() - t) / 60000));
}

/** Two letters for the avatar circle. */
export function initials(name) {
	var parts = (name || '').trim().split(/\s+/).filter(Boolean);
	var s = (parts[0] ? parts[0][0] : '') + (parts[1] ? parts[1][0] : '');

	return (s || (name || '?')[0] || '?').toUpperCase();
}

/**
 * A stable hue from a name, so the same customer keeps the same colour
 * everywhere without anyone storing a colour.
 */
export function tint(str) {
	var h = 0;
	str = str || '';

	for (var i = 0; i < str.length; i++) {
		h = (h * 31 + str.charCodeAt(i)) % 360;
	}

	return h;
}

/**
 * Inline SVG, so an icon costs no request and inherits currentColor.
 *
 * The paths are ours and contain no interpolation — the only reason this
 * returns markup rather than text.
 */
export function svgIcon(name) {
	var p = {
		phone: '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3.1-8.6A2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.3 1.8.6 2.7a2 2 0 0 1-.5 2.1L8 9.6a16 16 0 0 0 6 6l1.1-1.1a2 2 0 0 1 2.1-.5c.9.3 1.8.5 2.7.6a2 2 0 0 1 1.7 2z"/>',
		viber: '<path d="M12 3c4.5 0 8 3.2 8 7.4 0 4.2-3.5 7.4-8 7.4-.7 0-1.4-.1-2-.2L5 19l1-3.2C4.7 14.5 4 12.6 4 10.4 4 6.2 7.5 3 12 3z"/><path d="M9.5 8c.6 1.8 2 3.2 3.8 3.8"/>',
		mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/>',
		edit: '<path d="M11 4H5a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h13a2 2 0 0 0 2-2v-6"/><path d="M18.5 2.5a2.1 2.1 0 0 1 3 3L12 15l-4 1 1-4z"/>',
		trash: '<path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>'
	};

	return '<svg class="ecrm-bico" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
		+ 'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
		+ (p[name] || '') + '</svg>';
}

/* (353h) Οι πέντε σταθμοί μιας αίτησης, για τη λωρίδα της «Signature» καρτέλας.
 *
 * Πρόχειρο → Χαρτιά → Υπογραφή → Πάροχος → Ενεργή. Δεν είναι νέα κατάσταση:
 * διαβάζει την κατάσταση ΚΑΙ τα γεγονότα που ήδη ταξιδεύουν με την αίτηση.
 * «Χαρτιά» και «Υπογραφή» κρίνονται από τα ίδια τα στοιχεία (checklist,
 * signed_at, signatures), όχι από το όνομα της κατάστασης: μια αίτηση που
 * υπογράφηκε αλλά της λείπει λογαριασμός δείχνει ✓ στην υπογραφή και ● στα
 * χαρτιά, γιατί αυτό ακριβώς την κρατά. Ο server παραμένει ο φύλακας των
 * μεταβάσεων (ContractStatus::allowedNext()) -- εδώ μόνο ζωγραφίζεται.
 *
 * state: 'ok' πέρασε, 'now' εδώ είναι, 'warn' εδώ με εκκρεμότητα,
 * 'stop' σταμάτησε εδώ (απόρριψη/ακύρωση), 'next' δεν έφτασε ακόμα. */
var STAGE_RANK = {
	draft: 0, presale: 1, registration: 1, awaiting_signature: 1,
	to_finalisation: 3, signature_complete: 3, awaiting_sim: 3, sim_delivered: 3,
	pending_issue: 3, recheck: 3, debt: 3, for_review: 3,
	finalisation: 4, thalis_confirmed: 4, active: 5
};
var STAGE_WARN = { pending_issue: 1, recheck: 1, debt: 1, for_review: 1 };
var STAGE_STOP = { cancelled: 1, thalis_rejected: 1, mnp_reject: 1 };

export function contractStages(c) {
	c = c || {};
	var st = String(c.status || 'draft');
	var rank = STAGE_RANK[st] !== undefined ? STAGE_RANK[st] : 0;
	var ck = c.doc_checklist && c.doc_checklist.items && c.doc_checklist.items.length ? c.doc_checklist : null;
	var expired = (c.doc_expired || []).length > 0;
	var docsOk = ck ? (!!ck.complete && !expired) : (c.files || []).length > 0;
	var sg = c.signatures;
	var sigOk = sg && sg.required && sg.required.length > 1
		? (sg.collected || []).length >= sg.required.length
		: !!c.signed_at;

	var ok = [
		st !== 'draft',
		docsOk || rank >= 5,
		sigOk || rank >= 3,
		rank >= 4,
		st === 'active'
	];
	var now = ok.indexOf(false);
	var labels = ['Πρόχειρο', 'Χαρτιά', 'Υπογραφή', 'Πάροχος', 'Ενεργή'];
	var keys = ['draft', 'docs', 'sign', 'provider', 'active'];

	return labels.map(function (label, i) {
		var state = ok[i] ? 'ok' : 'next';
		if (i === now) {
			state = STAGE_STOP[st] ? 'stop' : (STAGE_WARN[st] ? 'warn' : 'now');
		}
		return { key: keys[i], label: label, state: state };
	});
}

/* (353j) Σε ποια ομάδα της λίστας «Εργασίες» μπαίνει μια εργασία.
 *
 * Το due_at είναι ΤΟΠΙΚΗ ώρα του site («2026-11-11 14:30:00»). Συγκρίνεται ως
 * ημερομηνία-κείμενο με το «σήμερα» του `now`, χωρίς ζώνη ώρας -- ίδια ανάγνωση
 * με fmtDate(). Το «εκπρόθεσμη» δεν το κρίνουμε εδώ: το στέλνει ο server
 * (t.overdue) και μία πηγή αρκεί.
 *
 * Επιστρέφει: 'done' | 'overdue' | 'today' | 'tomorrow' | 'week' | 'later' | 'none'. */
export function dueBucket(t, now) {
	t = t || {};
	if (t.status === 'done') return 'done';
	if (t.overdue) return 'overdue';

	var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(t.due_at || ''));
	if (!m) return 'none';

	now = now || new Date();
	var today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
	var due = new Date(+m[1], +m[2] - 1, +m[3]);
	var days = Math.round((due - today) / 86400000);

	if (days <= 0) return 'today';
	if (days === 1) return 'tomorrow';
	if (days < 7) return 'week';

	return 'later';
}

/** (353j) «14:30» ή «12/11 14:30» από τοπικό due_at, χωρίς να περάσει από Date. */
export function dueClock(iso, withDate) {
	var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(String(iso || ''));
	if (!m) return '';

	return (withDate ? m[3] + '/' + m[2] + ' ' : '') + m[4] + ':' + m[5];
}
