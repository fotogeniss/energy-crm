/* Energy CRM — leads: the pipeline before a contract exists.
 *
 * Sends the user two ways — to a contract's detail once a lead has been
 * converted, and to the form when it is time to convert one. */

import { api, esc, fetch, H, toast, viewEl } from '@energy-crm/util';
import { fmtDate } from '@energy-crm/format';
import { go, openDetail } from '@energy-crm/navigate';
import { openDialog } from '@energy-crm/dialog';
import { storedForAi } from '@energy-crm/ai-docs';
import { qrPosterHtml } from '@energy-crm/qr-poster';

var leadsState = { stage: '', q: '', editing: null, showForm: false };
export function loadLeads() {
	var view = viewEl('leads');
	var qs = '?stage=' + encodeURIComponent(leadsState.stage) + '&q=' + encodeURIComponent(leadsState.q);
	fetch(api('/leads') + qs, { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (!d || !d.ok) { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα.</div></div>'; return; }
			renderLeads(view, d);
		})
		.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα δικτύου.</div></div>'; });
}
function leadCbInput(v) {
	if (!v) return '';
	// 'YYYY-MM-DD HH:MM:SS' -> 'YYYY-MM-DDTHH:MM'
	return v.replace(' ', 'T').slice(0, 16);
}
function renderLeads(view, d) {
	var stages = d.stages || {}, sources = d.sources || {}, counts = d.counts || {};
	var sChips = [['', 'Όλα']].concat(Object.keys(stages).map(function (k) { return [k, stages[k]]; }))
		.map(function (e) {
			var n = e[0] === '' ? '' : (counts[e[0]] ? ' (' + counts[e[0]] + ')' : '');
			return '<button type="button" class="ecrm-chip2' + (leadsState.stage === e[0] ? ' is-on' : '') + '" data-lstage="' + esc(e[0]) + '">' + esc(e[1]) + n + '</button>';
		}).join('');

	var ed = leadsState.editing || {};
	var opts = function (map, sel) { return '<option value="">—</option>' + Object.keys(map).map(function (k) { return '<option value="' + esc(k) + '"' + (sel === k ? ' selected' : '') + '>' + esc(map[k]) + '</option>'; }).join(''); };
	var energyMap = { power: 'Ρεύμα', gas: 'Αέριο', mobile: 'Κινητή' };

	var form = !leadsState.showForm ? '' :
		'<div class="ecrm-card ecrm-leadform">' +
			'<div class="ecrm-leadform__grid">' +
				'<label class="ecrm-field"><span class="ecrm-field__label">Όνομα *</span><input class="ecrm-input" data-lf="name" value="' + esc(ed.name || '') + '"></label>' +
				'<label class="ecrm-field"><span class="ecrm-field__label">Τηλέφωνο</span><input class="ecrm-input" data-lf="phone" value="' + esc(ed.phone || '') + '"></label>' +
				'<label class="ecrm-field"><span class="ecrm-field__label">Email</span><input class="ecrm-input" data-lf="email" value="' + esc(ed.email || '') + '"></label>' +
				'<label class="ecrm-field"><span class="ecrm-field__label">Πηγή</span><select class="ecrm-input" data-lf="source">' + opts(sources, ed.source) + '</select></label>' +
				'<label class="ecrm-field"><span class="ecrm-field__label">Ενδιαφέρον για</span><select class="ecrm-input" data-lf="energy_type">' + opts(energyMap, ed.energy_type) + '</select></label>' +
				'<label class="ecrm-field"><span class="ecrm-field__label">Στάδιο</span><select class="ecrm-input" data-lf="stage">' + Object.keys(stages).map(function (k) { return '<option value="' + k + '"' + ((ed.stage || 'new') === k ? ' selected' : '') + '>' + esc(stages[k]) + '</option>'; }).join('') + '</select></label>' +
				'<label class="ecrm-field"><span class="ecrm-field__label">Επανάκληση</span><input type="datetime-local" class="ecrm-input" data-lf="callback_at" value="' + esc(leadCbInput(ed.callback_at)) + '"></label>' +
				'<label class="ecrm-field ecrm-field--wide"><span class="ecrm-field__label">Σημείωση ενδιαφέροντος</span><input class="ecrm-input" data-lf="interest" value="' + esc(ed.interest || '') + '" placeholder="π.χ. αλλαγή παρόχου ρεύματος, 2 παροχές"></label>' +
				'<label class="ecrm-field ecrm-field--wide"><span class="ecrm-field__label">Σημειώσεις</span><textarea class="ecrm-textarea" data-lf="notes" rows="2">' + esc(ed.notes || '') + '</textarea></label>' +
			'</div>' +
			'<div class="ecrm-leadform__bar"><button type="button" class="ecrm-btn ecrm-btn--primary" data-lsave>' + (ed.id ? 'Αποθήκευση' : 'Προσθήκη lead') + '</button>' +
				'<button type="button" class="ecrm-btn ecrm-btn--ghost" data-lcancel>Άκυρο</button></div>' +
		'</div>';

	var now = Date.now();
	var cards = (d.leads || []).map(function (l) {
		var cb = '';
		if (l.callback_at) {
			// ΧΩΡΙΣ 'Z'. Το callback_at είναι ΤΟΠΙΚΗ ώρα, όπως όλες οι
			// αποθηκευμένες. Με το 'Z' διαβαζόταν ως UTC, άρα το «ληξιπρόθεσμο»
			// άργουνε 3 ώρες — η κάρτα έδειχνε τη σωστή ώρα (fmtDate) και
			// ταυτόχρονα δεν κοκκίνιζε. Παρτίδα (84), CHANGELOG.
			var due = new Date(l.callback_at.replace(' ', 'T')).getTime();
			var overdue = due <= now && l.stage !== 'won' && l.stage !== 'lost';
			cb = '<span class="ecrm-leadcb' + (overdue ? ' is-over' : '') + '"><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h4l2 5-2.5 1.5a12 12 0 005 5L15 13l5 2v4a1 1 0 01-1 1A16 16 0 014 5a1 1 0 011-1z"/></svg> ' + esc(fmtDate(l.callback_at)) + '</span>';
		}
		var tel = l.phone ? '<a class="ecrm-leadtel" href="tel:' + esc(l.phone) + '">' + esc(l.phone) + '</a>' : '';
		var conv = (l.stage === 'won' && l.contract_id) ?
			'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-lopen="' + l.contract_id + '">Άνοιγμα σύμβασης</button>' :
			'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-lconv="' + l.id + '"><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg> Μετατροπή σε σύμβαση</button>';
		return '<div class="ecrm-leadcard ecrm-stage-' + esc(l.stage) + '">' +
			'<div class="ecrm-leadcard__top"><div><strong>' + esc(l.name) + '</strong> ' + (l.energy_type ? '<span class="ecrm-muted">· ' + esc(energyMap[l.energy_type] || l.energy_type) + '</span>' : '') +
				'<div class="ecrm-leadmeta">' + tel + (l.source_label ? ' <span class="ecrm-muted">· ' + esc(l.source_label) + '</span>' : '') + '</div></div>' +
				'<span class="ecrm-leadstage">' + esc(l.stage_label) + '</span></div>' +
			(l.interest ? '<div class="ecrm-leadint">' + esc(l.interest) + '</div>' : '') +
			'<div class="ecrm-leadcard__bar">' + cb +
				'<span class="ecrm-leadcard__actions">' + conv +
					'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-ledit="' + l.id + '">Επεξεργασία</button>' +
					'<button type="button" class="ecrm-iconbtn" data-ldel="' + l.id + '" title="Διαγραφή" aria-label="Διαγραφή"><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V5a1 1 0 011-1h4a1 1 0 011 1v2M6 7l1 13a1 1 0 001 1h8a1 1 0 001-1l1-13"/></svg></button>' +
				'</span></div>' +
		'</div>';
	}).join('');
	if (!(d.leads || []).length) cards = '<div class="ecrm-card"><div class="ecrm-empty">Δεν υπάρχουν υποψήφιοι πελάτες' + (leadsState.stage ? ' σε αυτό το στάδιο' : ' ακόμα') + '.</div></div>';

	if (isSignature()) {
		view.innerHTML = sigLeadsHTML(d, form);
	} else view.innerHTML =
		'<header class="ecrm-head ecrm-head--row"><div><div class="ecrm-eyebrow">Πριν τη σύμβαση</div><h2 class="ecrm-title">Leads</h2>' +
		'<p class="ecrm-sub">Υποψήφιοι πελάτες & επανακλήσεις</p></div>' +
		'<div class="ecrm-head__acts">' +
		'<button type="button" class="ecrm-btn ecrm-btn--ghost" data-mylink><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/></svg> Ο σύνδεσμός μου</button>' +
		'<button type="button" class="ecrm-btn ecrm-btn--primary" data-lnew><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg> Νέο Lead</button>' +
		'</div></header>' +
		'<div class="ecrm-mylink" data-mylinkbox hidden></div>' +
		'<div class="ecrm-leadfilters"><div class="ecrm-search"><input type="search" class="ecrm-input" placeholder="Αναζήτηση ονόματος, τηλεφώνου, ενδιαφέροντος…" value="' + esc(leadsState.q) + '" data-lq></div></div>' +
		'<div class="ecrm-kbfilter ecrm-leadstages">' + sChips + '</div>' +
		form +
		'<div class="ecrm-leadlist">' + cards + '</div>';

	// wiring
	view.querySelector('[data-lnew]').addEventListener('click', function () { leadsState.editing = {}; leadsState.showForm = true; renderLeads(view, d); });

	/* «Ο σύνδεσμός μου»: ο σύνδεσμος που στέλνει ο πωλητής στον πελάτη για να
	 * ανεβάσει μόνος του λογαριασμό και ταυτότητα. Ένας ανά πωλητή, μόνιμος,
	 * ανακλήσιμος. Ζητιέται από τον server μόνο όταν πατηθεί το κουμπί —
	 * αλλιώς κάθε φόρτωση της οθόνης θα παρήγαγε κλειδί σε πωλητές που δεν
	 * πρόκειται να τον χρησιμοποιήσουν ποτέ. */
	var linkBox = view.querySelector('[data-mylinkbox]');
	var linkOwner = { name: '', company: '' };
	function paintLink(url) {
		if (!url) { linkBox.hidden = true; return; }
		linkBox.innerHTML =
			'<div class="ecrm-mylink__hd">Στείλε αυτόν τον σύνδεσμο στον πελάτη</div>' +
			'<div class="ecrm-mylink__row"><code class="ecrm-mylink__url">' + esc(url) + '</code>' +
			'<button type="button" class="ecrm-btn ecrm-btn--sm ecrm-btn--primary" data-lcopy>Αντιγραφή</button>' +
			'<button type="button" class="ecrm-btn ecrm-btn--sm" data-lposter>QR code</button></div>' +
			'<div class="ecrm-mylink__note">Ο πελάτης γράφει όνομα και κινητό και ανεβάζει τα έγγραφά του. ' +
			'Εμφανίζεται εδώ ως υποψήφιος, με τα αρχεία μέσα. ' +
			'<button type="button" class="ecrm-linkbtn" data-lrevoke>Ακύρωση αυτού του συνδέσμου</button></div>';
		linkBox.hidden = false;
		linkBox.querySelector('[data-lposter]').addEventListener('click', function () {
			if (!openQrPoster(url, linkOwner)) { toast('Ο browser μπλόκαρε το νέο παράθυρο. Επίτρεψέ το και ξαναπάτα.', false); }
		});
		linkBox.querySelector('[data-lcopy]').addEventListener('click', function () {
			var ok = false;
			try { ok = document.execCommand && window.getSelection && (function () {
				var r = document.createRange();
				r.selectNodeContents(linkBox.querySelector('.ecrm-mylink__url'));
				var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
				var done = document.execCommand('copy'); s.removeAllRanges(); return done;
			})(); } catch (e) { ok = false; }
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(url).then(function () { toast('Ο σύνδεσμος αντιγράφηκε.'); },
					function () { toast(ok ? 'Ο σύνδεσμος αντιγράφηκε.' : 'Αντίγραψέ τον με το χέρι.', ok); });
			} else {
				toast(ok ? 'Ο σύνδεσμος αντιγράφηκε.' : 'Αντίγραψέ τον με το χέρι.', ok);
			}
		});
		linkBox.querySelector('[data-lrevoke]').addEventListener('click', function () {
			if (!window.confirm('Ο παλιός σύνδεσμος θα πάψει να δουλεύει αμέσως, σε όποιον τον έχεις ήδη στείλει και στις τυπωμένες αφίσες με QR. Να συνεχίσω;')) { return; }
			fetch(api('/intake/link/revoke'), { method: 'POST', headers: H() })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!res || !res.ok) { toast('Αποτυχία ακύρωσης.', false); return; }
					toast('Ο σύνδεσμος ανανεώθηκε.');
					paintLink(res.url);
				})
				.catch(function () { toast('Σφάλμα δικτύου.', false); });
		});
	}
	view.querySelector('[data-mylink]').addEventListener('click', function () {
		if (!linkBox.hidden) { linkBox.hidden = true; return; }
		fetch(api('/intake/link'), { headers: H() })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (!res || !res.ok || !res.url) { toast('Δεν ήταν δυνατή η δημιουργία συνδέσμου.', false); return; }
				linkOwner = { name: res.name || '', company: res.company || '' };
				paintLink(res.url);
			})
			.catch(function () { toast('Σφάλμα δικτύου.', false); });
	});
	var lq = view.querySelector('[data-lq]');
	lq.addEventListener('input', function () { leadsState.q = this.value; clearTimeout(leadsT); leadsT = setTimeout(loadLeads, 300); });
	if (leadsState.q) { lq.focus(); lq.setSelectionRange(lq.value.length, lq.value.length); }
	view.querySelectorAll('[data-lstage]').forEach(function (b) { b.addEventListener('click', function () { leadsState.stage = this.getAttribute('data-lstage'); loadLeads(); }); });

	if (leadsState.showForm) {
		view.querySelector('[data-lcancel]').addEventListener('click', function () { leadsState.showForm = false; leadsState.editing = null; renderLeads(view, d); });
		view.querySelector('[data-lsave]').addEventListener('click', function () {
			var body = {};
			view.querySelectorAll('[data-lf]').forEach(function (el) { body[el.getAttribute('data-lf')] = el.value; });
			if (!body.name || !body.name.trim()) { toast('Το όνομα είναι υποχρεωτικό.', false); return; }
			var url = api('/leads') + (leadsState.editing && leadsState.editing.id ? '/' + leadsState.editing.id : '');
			fetch(url, { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H()), body: JSON.stringify(body) })
				.then(function (r) { return r.json(); })
				.then(function (res) { if (res && res.ok) { toast('Αποθηκεύτηκε.'); leadsState.showForm = false; leadsState.editing = null; loadLeads(); } else { toast((res && res.error) || 'Αποτυχία.', false); } })
				.catch(function () { toast('Σφάλμα δικτύου.', false); });
		});
	}

	view.querySelectorAll('[data-ledit]').forEach(function (b) {
		b.addEventListener('click', function () {
			var id = +this.getAttribute('data-ledit');
			var lead = (d.leads || []).filter(function (x) { return x.id === id; })[0];
			if (lead) { leadsState.editing = lead; leadsState.showForm = true; renderLeads(view, d); window.scrollTo({ top: 0, behavior: 'smooth' }); }
		});
	});
	view.querySelectorAll('[data-ldel]').forEach(function (b) {
		b.addEventListener('click', function () {
			if (!confirm('Διαγραφή lead;')) return;
			fetch(api('/leads/' + this.getAttribute('data-ldel')), { method: 'DELETE', headers: H() })
				.then(function (r) { return r.json(); })
				.then(function (res) { if (res && res.ok) loadLeads(); else toast((res && res.error) || 'Αποτυχία διαγραφής.', false); })
				.catch(function () { toast('Σφάλμα δικτύου.', false); });
		});
	});
	view.querySelectorAll('[data-lconv]').forEach(function (b) {
		b.addEventListener('click', function () {
			var bb = this;
			var id = +this.getAttribute('data-lconv');
			var lead = (d.leads || []).filter(function (x) { return x.id === id; })[0] || {};

			// Το κουμπί κλειδώνει ΤΩΡΑ και ξεκλειδώνει μόνο αν η ροή σταματήσει
			// -- άκυρο, Escape, ή αποτυχία δημιουργίας. Σε επιτυχία δεν
			// ξεκλειδώνει ποτέ: η οθόνη έχει ήδη φύγει στην καρτέλα.
			bb.disabled = true;

			openHandoff(id, lead, function () { bb.disabled = false; });
		});
	});
	view.querySelectorAll('[data-lopen]').forEach(function (b) {
		b.addEventListener('click', function () { var id = +this.getAttribute('data-lopen'); go('contracts'); setTimeout(function () { openDetail(id); }, 60); });
	});
	if (isSignature()) { wireSigStages(view, d); }
}

/* ── Η παράδοση: από τον σύνδεσμο του πελάτη σε αίτηση ────────────────────
 *
 * ## Γιατί άλλαξε η σειρά
 *
 * Ως τις 28/08 η ροή ήταν «ρώτα → φτιάξε → διάβασε»: ένα native confirm()
 * χωρίς καμία πληροφορία, μετά η σύμβαση, και μόνο στο τέλος το AI. Ο πωλητής
 * έλεγε «ναι» για κάτι που δεν είχε δει. Τώρα είναι «διάβασε → δείξε → ρώτα →
 * φτιάξε». Μακέτα docs/UI-INTAKE-HANDOFF.html, εγκεκριμένη, CHANGELOG (172).
 *
 * ## Ο κανόνας που δεν επιτρέπεται να σπάσει
 *
 * Η ΑΠΟΤΥΧΙΑ ΤΟΥ AI ΔΕΝ ΜΠΛΟΚΑΡΕΙ ΠΟΤΕ ΤΗ ΔΗΜΙΟΥΡΓΙΑ. Με την παλιά σειρά αυτό
 * ήταν δωρεάν — η σύμβαση υπήρχε ήδη όταν έτρεχε το AI. Με τη νέα, η αποτυχία
 * έρχεται ΠΡΙΝ, οπότε πρέπει να γραφτεί ρητά: κάθε σφάλμα εξαγωγής καταλήγει
 * σε `null` δεδομένα και σε διάλογο που ΑΝΟΙΓΕΙ ΚΑΝΟΝΙΚΑ, με άλλο κείμενο
 * κουμπιού. Αν αυτό σπάσει, η αλλαγή σειράς είναι παλινδρόμηση.
 *
 * ## Γιατί δύο κλήσεις εξαγωγής θα ήταν λάθος
 *
 * Το `/extract` με `lead_id` ΔΙΑΒΑΖΕΙ και δεν γράφει (δεν υπάρχει ακόμα
 * αίτηση). Μετά τη δημιουργία, το ίδιο JSON στέλνεται πίσω με `data` — ο
 * εξαγωγέας δεν ξανακαλείται. Ο `ECRM_Extractor` δεν έχει cache: δεύτερη
 * ανάγνωση των ΙΔΙΩΝ αρχείων θα πλήρωνε το μοντέλο δεύτερη φορά σε κάθε
 * αίτηση. CHANGELOG (171).
 */

/* Ετικέτες μόνο για τα πεδία που ΔΕΙΧΝΕΙ αυτός ο διάλογος.
 *
 * Ναι, υπάρχει ήδη χάρτης στο `ECRM_Audit::label()`. Δεν έρχεται από εκεί:
 * είναι PHP, και μια διαδρομή που θα σέρβιρε οκτώ συμβολοσειρές θα ήταν ένα
 * ολόκληρο round-trip για κείμενο που δεν αλλάζει ποτέ. Αυτό ΔΕΝ είναι
 * αντίγραφο του χάρτη -- είναι η λίστα προβολής αυτής της οθόνης, με ρητή
 * σειρά, και μεγαλώνει μόνο αν αλλάξει η οθόνη. */
var HANDOFF_LABEL = {
	first_name: 'Όνομα', last_name: 'Επίθετο', father_name: 'Πατρώνυμο',
	company_name: 'Επωνυμία', afm: 'ΑΦΜ', doy: 'ΔΟΥ', adt: 'ΑΔΤ',
	birth_date: 'Ημ. γέννησης', region: 'Νομός', city: 'Πόλη',
	street: 'Οδός', street_no: 'Αριθμός', postal_code: 'ΤΚ',
	phone: 'Τηλέφωνο', mobile: 'Κινητό', email: 'Email',
	supply_number: 'Αριθμός παροχής', meter_number: 'Μετρητής',
	invoice_code: 'Τιμολόγιο',
};

/* Πόσα δείχνονται πριν το «και N ακόμα». Πέντε: ο σκοπός είναι «αναγνωρίζω
 * τον πελάτη μου και το AI δούλεψε», όχι έλεγχος γραμμή-γραμμή. Δέκα πεδία σε
 * κινητό δεν διαβάζονται. Απόφαση της μακέτας, §4. */
var HANDOFF_SHOWN = 5;

function handoffValue(data, key) {
	var v = data && data[key];
	return (v === null || v === undefined) ? '' : String(v).trim();
}

/* Οι σειρές του πίνακα, με σταθερή σειρά προτεραιότητας.
 *
 * Συγχωνευμένες όπου ο πωλητής τις διαβάζει ως ένα πράγμα: όνομα+επίθετο,
 * οδός+αριθμός, ΤΚ+πόλη. Δύο γραμμές για «Ακαδημίας» και «42» δεν βοηθούν
 * κανέναν να αναγνωρίσει τον πελάτη του. */
function handoffRows(data) {
	var rows = [], used = [];
	function take() { for (var i = 0; i < arguments.length; i++) { used.push(arguments[i]); } }

	var name = [handoffValue(data, 'first_name'), handoffValue(data, 'last_name')].filter(Boolean).join(' ');
	if (!name) { name = handoffValue(data, 'company_name'); }
	if (name) { rows.push(['Όνομα', name]); }
	take('first_name', 'last_name', 'company_name');

	if (handoffValue(data, 'afm')) { rows.push(['ΑΦΜ', handoffValue(data, 'afm')]); }
	take('afm');

	if (handoffValue(data, 'supply_number')) { rows.push(['Αρ. παροχής', handoffValue(data, 'supply_number')]); }
	take('supply_number');

	var street = [handoffValue(data, 'street'), handoffValue(data, 'street_no')].filter(Boolean).join(' ');
	if (street) { rows.push(['Οδός', street]); }
	take('street', 'street_no');

	var where = [handoffValue(data, 'postal_code'), handoffValue(data, 'city')].filter(Boolean).join(' · ');
	if (where) { rows.push(['ΤΚ / Πόλη', where]); }
	take('postal_code', 'city');

	// Ό,τι απέμεινε γεμάτο: μετριέται και ονομάζεται, δεν δείχνεται.
	var rest = Object.keys(HANDOFF_LABEL).filter(function (k) {
		return used.indexOf(k) === -1 && handoffValue(data, k) !== '';
	}).map(function (k) { return HANDOFF_LABEL[k]; });

	return { rows: rows.slice(0, HANDOFF_SHOWN), rest: rest, total: rows.length + rest.length };
}

/* Το μπλοκ του AI. Τρεις καταστάσεις, καμία τους «άδεια οθόνη». */
function handoffAiBlock(data, hadDocuments) {
	var f = data ? handoffRows(data) : null;

	if (!f || !f.total) {
		return '<div class="ecrm-handoff ecrm-handoff--empty">' +
			'<div class="ecrm-handoff__hd"><span class="ecrm-handoff__dot ecrm-handoff__dot--off">' +
			(hadDocuments ? 'AI' : '—') + '</span> ' +
			(hadDocuments ? 'Δεν κατάφερα να διαβάσω τα έγγραφα' : 'Δεν στάλθηκαν έγγραφα') + '</div>' +
			'<div class="ecrm-handoff__empty">' +
			(hadDocuments
				? 'Θα τα βρεις στην αίτηση για να τα δεις μόνος σου. Τα πεδία μένουν κενά.'
				: 'Θα δημιουργηθεί αίτηση με ό,τι συμπλήρωσε ο πελάτης στη φόρμα.') +
			'</div></div>';
	}

	var kv = f.rows.map(function (r) {
		return '<dt>' + esc(r[0]) + '</dt><dd>' + esc(r[1]) + '</dd>';
	}).join('');

	var more = f.rest.length
		? '<div class="ecrm-handoff__more">και ' + f.rest.length + ' ακόμα — ' + esc(f.rest.join(', ')) + '</div>'
		: '';

	return '<div class="ecrm-handoff">' +
		'<div class="ecrm-handoff__hd"><span class="ecrm-handoff__dot">AI</span> Βρήκα αυτά τα στοιχεία' +
		'<span class="ecrm-handoff__n">' + f.total + (f.total === 1 ? ' πεδίο' : ' πεδία') + '</span></div>' +
		'<dl class="ecrm-handoff__kv">' + kv + '</dl>' + more +
	'</div>';
}

/* Το μπλοκ της συνήθειας -- ή ΤΙΠΟΤΑ.
 *
 * Νέος πωλητής δεν βλέπει «δεν έχω αρκετά δεδομένα». Δεν χρωστά εξήγηση στο
 * σύστημα, και μια απολογία σε κάθε αίτηση είναι θόρυβος. Ίδιο κατώφλι με την
 * (166): ο server στέλνει `usual: null` κάτω από MIN_TIMES. */
function handoffUsualBlock(usual, catalogue) {
	if (!usual || !usual.provider_id) { return ''; }

	var pname = '';
	(catalogue.providers || []).forEach(function (p) {
		if (parseInt(p.id, 10) === parseInt(usual.provider_id, 10)) { pname = p.name; }
	});
	if (!pname) { return ''; }

	var prog = '';
	if (usual.program_id) {
		(catalogue.programs || []).forEach(function (pr) {
			if (parseInt(pr.id, 10) === parseInt(usual.program_id, 10)) { prog = pr.name; }
		});
	}

	var kind = { power: 'Ηλεκτρισμός', gas: 'Φυσικό Αέριο', mobile: 'Κινητή Τηλεφωνία' }[usual.energy_type] || '';

	return '<div class="ecrm-handoff ecrm-handoff--usual">' +
		'<div class="ecrm-handoff__hd"><span class="ecrm-handoff__dot ecrm-handoff__dot--ok">✓</span> Η συνήθειά σου</div>' +
		'<div class="ecrm-handoff__usual">Συνήθως βάζεις <strong>' + esc(pname) + '</strong>' +
			(kind ? ' — ' + esc(kind) : '') + (prog ? ', <strong>' + esc(prog) + '</strong>' : '') + '</div>' +
		'<div class="ecrm-handoff__meta">σε ' + parseInt(usual.times, 10) +
			' από τις τελευταίες ' + parseInt(usual.of, 10) + ' αιτήσεις σου</div>' +
	'</div>';
}

/* Διαβάζει τα έγγραφα του lead ΧΩΡΙΣ να γράψει τίποτα.
 *
 * Κάθε αποτυχία -- δίκτυο, 404 χωρίς έγγραφα, 503 ουρά, 502 μοντέλο --
 * καταλήγει στο ίδιο: `{ data: null, hadDocuments: … }`. Ο διάλογος ανοίγει
 * ούτως ή άλλως. Δες τον κανόνα στην κορυφή. */
function handoffRead(leadId) {
	// Τα PDF που έστειλε ο πελάτης κόβονται εδώ πριν πάνε στο AI. Αν δεν
	// υπάρχει PDF ή κάτι αποτύχει, πάει το lead_id και ο server τα διαβάζει
	// ολόκληρα, όπως πριν -- με το ίδιο 404 όταν δεν υπάρχουν έγγραφα.
	return storedForAi({ lead_id: leadId }).then(function (stored) {
		var fd = new FormData();
		if (stored && stored.length) {
			stored.forEach(function (item) { fd.append('files[]', item.file); fd.append('kinds[]', item.kind); });
		} else {
			fd.append('lead_id', String(leadId));
		}
		return fetch(api('/extract'), { method: 'POST', headers: H(), body: fd });
	})
		.then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); })
		.then(function (res) {
			// 404 εδώ σημαίνει ένα και μόνο πράγμα: ο πελάτης δεν έστειλε
			// αναγνώσιμα έγγραφα. Ο έλεγχος εμβέλειας απαντά ΤΟ ΙΔΙΟ 404 --
			// αλλά αυτό δεν συμβαίνει εδώ, το lead είναι από τη δική του λίστα.
			if (res.status === 404) { return { data: null, hadDocuments: false }; }
			if (res.body && res.body.ok && res.body.data) { return { data: res.body.data, hadDocuments: true }; }
			return { data: null, hadDocuments: true };
		})
		.catch(function () { return { data: null, hadDocuments: true }; });
}

/* Ο κατάλογος, για τα ΟΝΟΜΑΤΑ παρόχου και προγράμματος.
 *
 * Το `usual` ταξιδεύει με ids -- η (166) αποφάσισε ρητά να μη στέλνονται
 * ονόματα, γιατί η φόρμα έχει ήδη τον κατάλογο. Εδώ δεν τον έχουμε, οπότε
 * ζητιέται. Αποτυχία = καμία συνήθεια στην οθόνη, τίποτα άλλο. */
function handoffCatalogue() {
	return fetch(api('/providers'), { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) { return d || {}; })
		.catch(function () { return {}; });
}

/* Τα «μπόνους» γραψίματα, ΜΕΤΑ τη δημιουργία. Κανένα δεν μπορεί να την
 * ακυρώσει: καθένα καταπίνει τα σφάλματά του και επιστρέφει πάντα. */
function handoffApplyData(contractId, data) {
	var fd = new FormData();
	fd.append('contract_id', String(contractId));
	fd.append('apply', '1');
	fd.append('data', JSON.stringify(data));

	return fetch(api('/extract'), { method: 'POST', headers: H(), body: fd })
		.then(function (r) { return r.json(); })
		.then(function (res) { return (res && res.applied) ? res.applied.length : 0; })
		.catch(function () { return 0; });
}

function handoffApplyUsual(contractId, usual) {
	var body = {
		contract_id: contractId,
		provider_id: usual.provider_id,
		energy_type: usual.energy_type,
	};
	if (usual.program_id) { body.program_id = usual.program_id; }

	return fetch(api('/contracts'), {
		method: 'POST',
		headers: Object.assign({ 'Content-Type': 'application/json' }, H()),
		body: JSON.stringify(body),
	})
		.then(function (r) { return r.json(); })
		.then(function (res) { return !!(res && res.ok); })
		.catch(function () { return false; });
}

/* Η δημιουργία, και μόνο μετά τα μπόνους. */
function handoffConvert(leadId, data, usual, onFailure) {
	toast('Δημιουργείται η αίτηση…');

	fetch(api('/leads/' + leadId + '/convert'), { method: 'POST', headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (res) {
			if (!res || !res.ok || !res.contract_id) {
				onFailure();
				toast((res && res.error) || 'Αποτυχία.', false);
				return;
			}

			var cid = res.contract_id;

			/* ΣΕΙΡΙΑΚΑ, όχι Promise.all. Και τα δύο γράφουν στην ΙΔΙΑ γραμμή
			 * `contracts`: το πρώτο τα πεδία που διάβασε το AI, το δεύτερο
			 * πάροχο/είδος/πρόγραμμα. Παράλληλα, το δεύτερο θα διάβαζε την
			 * υπάρχουσα γραμμή πριν προλάβει να γράψει το πρώτο -- και η
			 * σειρά που θα κέρδιζε θα άλλαζε από τρέξιμο σε τρέξιμο. Δύο
			 * γρήγορα ερωτήματα βάσης σε σειρά κοστίζουν λιγότερο από ένα
			 * σφάλμα που εμφανίζεται μία στις δέκα φορές. */
			var step = data ? handoffApplyData(cid, data) : Promise.resolve(0);

			step.then(function (written) {
				var next = (usual && usual.provider_id)
					? handoffApplyUsual(cid, usual)
					: Promise.resolve(false);

				return next.then(function () { return written; });
			}).then(function (written) {
				toast(written
					? 'Η αίτηση δημιουργήθηκε — συμπληρώθηκαν ' + written + ' πεδία. Έλεγξέ τα.'
					: 'Η αίτηση δημιουργήθηκε.');
				go('contracts');
				setTimeout(function () { openDetail(cid); }, 60);
			});
		})
		.catch(function () { onFailure(); toast('Σφάλμα δικτύου.', false); });
}

/* Η στιγμή ολόκληρη. */
function openHandoff(leadId, lead, onAbort) {
	toast('Διαβάζονται τα έγγραφα του πελάτη…');

	Promise.all([handoffRead(leadId), handoffCatalogue()]).then(function (out) {
		var read = out[0], catalogue = out[1];
		var found = !!(read.data && handoffRows(read.data).total);
		var usual = catalogue.usual || null;

		var subtitle = [];
		if (lead.phone) { subtitle.push(' · ' + lead.phone); }

		var confirmed = false;

		var dlg = openDialog({
			eyebrow: 'Από τον σύνδεσμό σου',
			title: read.hadDocuments ? 'Ο πελάτης έστειλε τον λογαριασμό' : 'Ο πελάτης έστειλε στοιχεία',
			lead: [{ b: lead.name || 'Υποψήφιος' }].concat(subtitle),
			body: handoffAiBlock(read.data, read.hadDocuments) + handoffUsualBlock(usual, catalogue),
			cancel: 'Άκυρο',
			// Το κείμενο του κουμπιού είναι ΤΟ ΜΟΝΟ που αλλάζει όταν αποτύχει το
			// AI. Ο πωλητής ξέρει τι τον περιμένει, και προχωράει ούτως ή άλλως.
			confirm: found ? 'Ναι, συνέχισε' : 'Συνέχισε χειροκίνητα',
			onConfirm: function (el, close, btn) {
				confirmed = true;
				btn.disabled = true;
				close();
				handoffConvert(leadId, read.data, usual, onAbort);
			},
			// Κλείσιμο με ×, Escape ή πέπλο: το κουμπί της κάρτας ξεκλειδώνει.
			onClose: function () { if (!confirmed) { onAbort(); } },
		});

		return dlg;
	});
}

/* (344) Η αφίσα με το QR, σε νέο παράθυρο για εκτύπωση. Ο σύνδεσμος παίρνει
 * `via=qr`, ώστε ο υποψήφιος να γραφτεί «Από το QR μου». */
function openQrPoster(url, owner) {
	var target = url + (url.indexOf('?') >= 0 ? '&' : '?') + 'via=qr';
	var page = URL.createObjectURL(new Blob([qrPosterHtml(target, owner || {})], { type: 'text/html' }));
	var win = window.open(page, '_blank');
	// Το παράθυρο έχει ήδη φορτώσει τη σελίδα· το blob δεν χρειάζεται μετά.
	setTimeout(function () { URL.revokeObjectURL(page); }, 60000);
	return !!win;
}

// ---------------------------------------------------------------------
// (359) «Signature»: Υποψήφιοι — «Η επόμενη κλήση»
// Mockup docs/UI-LEADS-FRESH.html, κατεύθυνση Α (εγκρίθηκε 11/11).
//
// Η οθόνη απαντά πρώτα στο «ποιον παίρνω τώρα»: ροή σταδίων με αριθμούς
// (φίλτρο), σκούρα κάρτα με τον επόμενο και το γιατί, το «Σήμερα» δίπλα, και
// από κάτω οι υπόλοιποι σε ομάδες. Ίδια δεδομένα, ίδια data-* (ledit, lconv,
// lopen, ldel, lstage, lq, lnew, mylink), ίδια καλωδίωση στο renderLeads().
// Το στάδιο αλλάζει από το κουμπάκι της γραμμής· η αποθήκευση στέλνει ΟΛΟ
// τον υποψήφιο, γιατί το POST /leads/{id} γράφει όλα τα πεδία
// (LeadsController::fieldsFrom) και ένα σκέτο {stage} θα έσβηνε τα υπόλοιπα.
// ---------------------------------------------------------------------

function isSignature() {
	var app = document.getElementById('ecrm-app');
	return !!app && app.getAttribute('data-ui') === 'signature';
}

var SIG_SRC_ICON = {
	site: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
	qr: '<rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><path d="M14 14h2v2h-2zM18 18h2v2h-2z"/>',
	link: '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>',
	phone: '<path d="M5 4h4l2 5-2.5 1.5a12 12 0 0 0 5 5L15 13l5 2v4a1 1 0 0 1-1 1A16 16 0 0 1 4 5a1 1 0 0 1 1-1z"/>'
};
var SIG_TEL = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h4l2 5-2.5 1.5a12 12 0 0 0 5 5L15 13l5 2v4a1 1 0 0 1-1 1A16 16 0 0 1 4 5a1 1 0 0 1 1-1z"/></svg>';
var SIG_ENERGY = { power: 'Ρεύμα', gas: 'Αέριο', mobile: 'Κινητή' };

function sigLeadTint(name) {
	var h = 0, s = String(name || '');
	for (var i = 0; i < s.length; i++) { h = (h * 31 + s.charCodeAt(i)) % 360; }
	return h;
}

function sigLeadInitials(name) {
	var parts = String(name || '').trim().split(/\s+/).filter(Boolean);
	return ((parts[0] || '').charAt(0) + (parts.length > 1 ? parts[parts.length - 1].charAt(0) : '')).toUpperCase() || '·';
}

function sigSrc(l) {
	if (!l.source_label) return '';
	var icon = SIG_SRC_ICON[l.source] || '';
	return '<span class="sig-lsrc">' + (icon ? '<svg viewBox="0 0 24 24" aria-hidden="true">' + icon + '</svg>' : '') + esc(l.source_label) + '</span>';
}

function sigDigits(p) { return String(p || '').replace(/[^0-9]/g, '').slice(-10); }

/* 'YYYY-MM-DD HH:MM:SS' τοπική -> { day: 'YYYY-MM-DD', hm: 'HH:MM', ms } */
function sigWhen(v) {
	if (!v) return null;
	var day = v.slice(0, 10), hm = v.slice(11, 16);
	return { day: day, hm: hm, ms: new Date(v.replace(' ', 'T')).getTime() };
}

/* «Ρεύμα · <ενδιαφέρον>», χωρίς να λέει το είδος δύο φορές όταν το γράφει
 * ήδη το ενδιαφέρον («Αέριο · 9 kWh/μέρα»). */
function sigWhat(l) {
	var kind = l.energy_type ? SIG_ENERGY[l.energy_type] || l.energy_type : '';
	var interest = String(l.interest || '');
	if (kind && interest.indexOf(kind) === 0) kind = '';
	return [kind, interest].filter(Boolean);
}

function sigToday() {
	var n = new Date();
	var p = function (x) { return (x < 10 ? '0' : '') + x; };
	return n.getFullYear() + '-' + p(n.getMonth() + 1) + '-' + p(n.getDate());
}

function sigLeadsHTML(d, form) {
	var stages = d.stages || {}, counts = d.counts || {};
	var now = Date.now(), today = sigToday();
	var all = (d.leads || []).slice();

	// Ο ίδιος άνθρωπος δύο φορές (ίδιο τηλέφωνο): μία γραμμή, και οι υπόλοιποι
	// ως «+1 αίτημα» από κάτω της, με το δικό τους κουμπί διαγραφής.
	var byPhone = {}, rows = [];
	all.forEach(function (l) {
		var key = sigDigits(l.phone);
		if (key && byPhone[key]) { byPhone[key].dupes.push(l); return; }
		var row = { lead: l, dupes: [] };
		if (key) byPhone[key] = row;
		rows.push(row);
	});

	var active = function (l) { return l.stage !== 'won' && l.stage !== 'lost'; };
	rows.forEach(function (r) { r.when = sigWhen(r.lead.callback_at); });

	var late = [], todayRows = [], fresh = [], open = [], won = [], lost = [];
	rows.forEach(function (r) {
		var l = r.lead;
		if (l.stage === 'won') { won.push(r); return; }
		if (l.stage === 'lost') { lost.push(r); return; }
		if (r.when && r.when.ms <= now) { late.push(r); return; }
		if (r.when && r.when.day === today) { todayRows.push(r); return; }
		if (l.stage === 'new' && !r.when) { fresh.push(r); return; }
		open.push(r);
	});
	var byTime = function (a, b) { return a.when.ms - b.when.ms; };
	late.sort(byTime); todayRows.sort(byTime);

	// Ο επόμενος: πρώτα ο πιο παλιός εκπρόθεσμος, μετά ο πιο πρόσφατος νέος,
	// μετά η επόμενη επανάκληση της μέρας.
	var next = late[0] || fresh[0] || todayRows[0] || null;
	var why = '';
	if (next) {
		if (next === late[0]) why = 'Η επανάκληση ήταν για τις ' + next.when.hm + (next.when.day !== today ? ' στις ' + fmtDate(next.when.day) : '') + ' και δεν έχει γίνει ακόμα.';
		else if (next === fresh[0]) {
			var src = String(next.lead.source_label || '');
			why = (/^Από /.test(src) ? 'Ήρθε α' + src.slice(1) : 'Νέος υποψήφιος' + (src ? ' (' + src + ')' : '')) + ' και δεν έχει γίνει επαφή.';
		}
		else why = 'Επανάκληση σήμερα στις ' + next.when.hm + '.';
		if (next.dupes.length) why += ' Ζήτησε ' + (next.dupes.length + 1) + ' φορές.';
	}

	var flowKeys = ['new', 'contacted', 'callback', 'qualified', 'won'];
	var wonN = counts.won || 0, lostN = counts.lost || 0;
	var rate = wonN + lostN ? Math.round(wonN / (wonN + lostN) * 100) : null;
	var flow = '<div class="sig-lflow">' + flowKeys.map(function (k) {
		var label = stages[k] || k;
		if (k === 'won' && rate !== null) label += ' · ' + rate + '%';
		return '<button type="button" class="sig-lflow__s' + (k === 'won' ? ' is-won' : '') + (leadsState.stage === k ? ' is-on' : '') + '" data-lstage="' + esc(leadsState.stage === k ? '' : k) + '">' +
			'<b>' + esc(String(counts[k] || 0)) + '</b><span>' + esc(label) + '</span></button>';
	}).join('') + '</div>';

	var hero = '';
	if (next && !leadsState.stage) {
		var l = next.lead;
		var facts = sigWhat(l);
		hero = '<div class="sig-lnext">' +
			'<small>Επόμενη κλήση</small><h3>' + esc(l.name) + '</h3><p>' + esc(why) + '</p>' +
			(facts.length ? '<div class="sig-lnext__facts">' + facts.map(function (f) { return '<span>' + esc(f) + '</span>'; }).join('') + '</div>' : '') +
			'<div class="sig-lnext__acts">' +
				(l.phone ? '<a class="ecrm-btn ecrm-btn--primary" href="' + esc('tel:' + l.phone) + '">' + SIG_TEL + ' Κάλεσε ' + esc(l.phone) + '</a>' : '') +
				'<button type="button" class="ecrm-btn ecrm-btn--ghost" data-lconv="' + esc(String(l.id)) + '">Μετατροπή σε σύμβαση</button>' +
				// (359b) «Αργότερα» = μετάθεση, όχι φόρμα: άνοιγε την επεξεργασία και
				// η «Αποθήκευση» γύριζε στο ίδιο σημείο, με τον ίδιο άνθρωπο ξανά
				// πρώτο (ιδιοκτήτης 11/11). Τώρα βάζει επανάκληση με ένα πάτημα.
				'<details class="sig-lsnooze"><summary class="ecrm-btn ecrm-btn--ghost">Αργότερα</summary><div class="sig-lsnooze__menu">' +
					'<button type="button" data-lsnooze="' + esc(String(l.id)) + '" data-lsnooze-in="60">Σε 1 ώρα</button>' +
					'<button type="button" data-lsnooze="' + esc(String(l.id)) + '" data-lsnooze-in="180">Σε 3 ώρες</button>' +
					'<button type="button" data-lsnooze="' + esc(String(l.id)) + '" data-lsnooze-in="tomorrow">Αύριο 10:00</button>' +
					'<button type="button" data-ledit="' + esc(String(l.id)) + '">Άλλη ώρα…</button>' +
				'</div></details>' +
			'</div></div>';
	}

	var slots = rows.filter(function (r) { return active(r.lead) && r.when && (r.when.day === today || r.when.ms <= now); })
		.sort(byTime).slice(0, 6);
	var todayCard = !leadsState.stage ? '<div class="sig-ltoday"><h4>Σήμερα</h4>' + (slots.length ? slots.map(function (r) {
		var isLate = r.when.ms <= now;
		return '<div class="sig-lslot"><time class="' + (isLate ? 'is-late' : '') + '">' + esc(r.when.day === today ? r.when.hm : fmtDate(r.when.day)) + '</time>' +
			'<span><b>' + esc(r.lead.name) + '</b><em>' + esc(isLate ? 'επανάκληση, εκπρόθεσμη' : 'επανάκληση') + '</em></span>' +
			(r.lead.phone ? '<a class="sig-lcall" href="' + esc('tel:' + r.lead.phone) + '" aria-label="' + esc('Κάλεσε ' + r.lead.name) + '">' + SIG_TEL + '</a>' : '') + '</div>';
	}).join('') : '<p class="sig-ltoday__none">Καμία επανάκληση για σήμερα.</p>') + '</div>' : '';

	function row(r) {
		var l = r.lead, id = esc(String(l.id));
		var stageSel = l.stage === 'won' && l.contract_id
			? '<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-lopen="' + esc(String(l.contract_id)) + '">Άνοιγμα σύμβασης</button>'
			: '<select class="sig-lstage is-' + esc(l.stage) + '" data-lstagepick="' + id + '" aria-label="Στάδιο">' + Object.keys(stages).map(function (k) {
				var lbl = stages[k] + (k === 'callback' && r.when ? ' ' + (r.when.day === today ? r.when.hm : fmtDate(r.when.day)) : '');
				return '<option value="' + esc(k) + '"' + (k === l.stage ? ' selected' : '') + '>' + esc(lbl) + '</option>';
			}).join('') + '</select>';
		var what = sigWhat(l).join(' · ');
		var dupes = r.dupes.map(function (x) {
			return '<div class="sig-ldupe">Ίδιο τηλέφωνο, ξεχωριστό αίτημα' + (x.interest ? ': ' + esc(x.interest) : '') +
				' <button type="button" class="sig-ldupe__del" data-ldel="' + esc(String(x.id)) + '">Διαγραφή διπλού</button></div>';
		}).join('');
		return '<div class="sig-lrow">' +
			'<span class="sig-lrow__av" style="--h:' + sigLeadTint(l.name) + '">' + esc(sigLeadInitials(l.name)) + '</span>' +
			'<span class="sig-lrow__who"><b>' + esc(l.name) + (r.dupes.length ? ' <em class="sig-ldupe__n">' + esc((r.dupes.length + 1) + ' αιτήματα') + '</em>' : '') + '</b>' +
				'<small>' + sigSrc(l) + (l.phone ? '<a href="' + esc('tel:' + l.phone) + '">' + esc(l.phone) + '</a>' : '') + '</small></span>' +
			'<span class="sig-lrow__what">' + esc(what || '—') + '</span>' +
			stageSel +
			'<details class="sig-lmore"><summary aria-label="Περισσότερα">⋯</summary><div class="sig-lmore__menu">' +
				'<button type="button" data-ledit="' + id + '">Επεξεργασία</button>' +
				(l.stage === 'won' && l.contract_id ? '' : '<button type="button" data-lconv="' + id + '">Μετατροπή σε σύμβαση</button>') +
				'<button type="button" class="is-danger" data-ldel="' + id + '">Διαγραφή</button>' +
			'</div></details>' +
			(dupes ? '<div class="sig-lrow__dupes">' + dupes + '</div>' : '') +
		'</div>';
	}

	function group(title, tone, list) {
		if (!list.length) return '';
		return '<section class="sig-lgroup"><h5><i class="is-' + tone + '"></i>' + esc(title) + ' <small>' + esc(String(list.length)) + '</small></h5>' +
			'<div class="sig-llist">' + list.map(row).join('') + '</div></section>';
	}

	var groups = group('Εκπρόθεσμοι', 'rose', late) + group('Σήμερα', 'amber', todayRows) +
		group('Νέοι, χωρίς επαφή', 'blue', fresh) + group('Σε εξέλιξη', 'teal', open) +
		group('Κερδισμένοι', 'green', won) + group('Χαμένοι', 'grey', lost);
	if (!rows.length) {
		groups = '<div class="sig-tempty"><b>' + esc(leadsState.stage || leadsState.q ? 'Κανένας υποψήφιος εδώ.' : 'Δεν έχεις υποψήφιους ακόμα.') + '</b>' +
			'<span>Πρόσθεσε έναν, ή στείλε τον σύνδεσμό σου σε πελάτη.</span></div>';
	}

	var inFlight = rows.filter(function (r) { return active(r.lead); }).length;
	var dueToday = late.length + todayRows.length;
	var sub = inFlight + (inFlight === 1 ? ' σε εξέλιξη' : ' σε εξέλιξη') + (dueToday ? ', ' + dueToday + (dueToday === 1 ? ' κλήση σήμερα' : ' κλήσεις σήμερα') : '');

	return '<header class="sig-cuhead"><div><h2>Υποψήφιοι</h2><p>' + esc(sub) + '</p></div>' +
		'<div class="sig-lhead__acts">' +
			'<button type="button" class="ecrm-btn ecrm-btn--ghost" data-mylink><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/></svg> Ο σύνδεσμός μου</button>' +
			'<button type="button" class="ecrm-btn ecrm-btn--primary" data-lnew><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg> Νέος υποψήφιος</button>' +
		'</div></header>' +
		'<div class="ecrm-mylink" data-mylinkbox hidden></div>' +
		form +
		flow +
		(hero || todayCard ? '<div class="sig-lhero' + (hero ? '' : ' is-solo') + '">' + hero + todayCard + '</div>' : '') +
		'<div class="sig-lsearch"><input type="search" class="ecrm-input" placeholder="Αναζήτηση ονόματος, τηλεφώνου, ενδιαφέροντος…" value="' + esc(leadsState.q) + '" data-lq></div>' +
		groups;
}

/* Αποθήκευση ενός υποψήφιου με αλλαγές: ΟΛΟΣ πίσω, γιατί το POST
 * /leads/{id} γράφει όλα τα πεδία (LeadsController::fieldsFrom). */
function sigSaveLead(l, patch, done, fail) {
	var body = {
		name: l.name || '', phone: l.phone || '', email: l.email || '', source: l.source || '',
		energy_type: l.energy_type || '', interest: l.interest || '', notes: l.notes || '',
		callback_at: leadCbInput(l.callback_at), lost_reason: l.lost_reason || '', stage: l.stage || 'new'
	};
	Object.keys(patch).forEach(function (k) { body[k] = patch[k]; });
	fetch(api('/leads/' + l.id), { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H()), body: JSON.stringify(body) })
		.then(function (r) { return r.json(); })
		.then(function (res) {
			if (res && res.ok) { done(); loadLeads(); }
			else { fail(); toast((res && res.error) || 'Αποτυχία.', false); }
		})
		.catch(function () { fail(); toast('Σφάλμα δικτύου.', false); });
}

/* «YYYY-MM-DDTHH:MM» τοπική ώρα, όπως το datetime-local της φόρμας. */
function sigLocalInput(t) {
	var p = function (x) { return (x < 10 ? '0' : '') + x; };
	return t.getFullYear() + '-' + p(t.getMonth() + 1) + '-' + p(t.getDate()) + 'T' + p(t.getHours()) + ':' + p(t.getMinutes());
}

/* Αλλαγή σταδίου από τη γραμμή, και «Αργότερα» από την κάρτα της επόμενης κλήσης. */
function wireSigStages(view, d) {
	var find = function (id) { return (d.leads || []).filter(function (x) { return x.id === id; })[0]; };
	view.querySelectorAll('[data-lstagepick]').forEach(function (sel) {
		sel.addEventListener('change', function () {
			var l = find(+this.getAttribute('data-lstagepick'));
			if (!l) return;
			sel.disabled = true;
			sigSaveLead(l, { stage: this.value }, function () { toast('Το στάδιο άλλαξε.'); }, function () { sel.disabled = false; });
		});
	});
	view.querySelectorAll('[data-lsnooze]').forEach(function (b) {
		b.addEventListener('click', function () {
			var l = find(+this.getAttribute('data-lsnooze'));
			if (!l) return;
			var when = this.getAttribute('data-lsnooze-in');
			var t = new Date();
			if (when === 'tomorrow') { t.setDate(t.getDate() + 1); t.setHours(10, 0, 0, 0); }
			else { t = new Date(t.getTime() + parseInt(when, 10) * 60000); t.setSeconds(0, 0); }
			var label = this.textContent;
			b.disabled = true;
			sigSaveLead(l, { callback_at: sigLocalInput(t), stage: 'callback' },
				function () { toast('Επανάκληση: ' + label.toLowerCase() + '.'); },
				function () { b.disabled = false; });
		});
	});
	view.querySelectorAll('.sig-lsnooze').forEach(function (m) {
		m.addEventListener('click', function (e) { if (e.target.closest('button')) m.removeAttribute('open'); });
	});
	view.querySelectorAll('.sig-lmore').forEach(function (m) {
		m.addEventListener('click', function (e) { if (e.target.closest('button')) m.removeAttribute('open'); });
	});
}
