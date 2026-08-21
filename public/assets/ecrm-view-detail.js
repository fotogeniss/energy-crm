/* Energy CRM — one contract, in full: fields, documents, history, actions.
 *
 * The heaviest screen in the app and the one an agent spends the most time
 * on. It owns no state — everything it shows comes from a single fetch, and
 * every button either navigates away or posts and re-fetches.
 *
 * copyText() and downloadBinary() came with it rather than going to the
 * shared module. Both have exactly one caller, here, and a helper with one
 * user is not shared — it is misplaced. */

import { api, esc, fetch, H, rejectedNote, toast, viewEl } from '@energy-crm/util';
import { energyLabel, fmtDate, svgIcon, timeAgo } from '@energy-crm/format';
import { go, openEdit } from '@energy-crm/navigate';
import { confirmTyped, openDialog } from '@energy-crm/dialog';

function copyText(text) {
	text = String(text == null ? '' : text);
	if (navigator.clipboard && window.isSecureContext) {
		return navigator.clipboard.writeText(text).then(function () { return true; }, function () { return legacyCopy(text); });
	}
	return Promise.resolve(legacyCopy(text));
}
function legacyCopy(text) {
	try {
		var ta = document.createElement('textarea');
		ta.value = text;
		ta.setAttribute('readonly', '');
		ta.style.position = 'fixed';
		ta.style.top = '-1000px';
		ta.style.opacity = '0';
		document.body.appendChild(ta);
		ta.select();
		ta.setSelectionRange(0, ta.value.length);
		var ok = document.execCommand('copy');
		document.body.removeChild(ta);
		return ok;
	} catch (e) { return false; }
}
export function openDetail(id) {
	go('contract-detail');
	var view = viewEl('contract-detail');
	view.innerHTML = '<div class="ecrm-loading">Φόρτωση…</div>';
	fetch(api('/contracts/' + id), { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) { if (!d || !d.ok) { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">' + esc((d && d.error) || 'Σφάλμα.') + '</div></div>'; return; } renderDetail(view, d); })
		.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα φόρτωσης.</div></div>'; });
}
function field(label, val) {
	return '<div class="ecrm-dl"><dt>' + esc(label) + '</dt><dd>' + (val ? esc(val) : '—') + '</dd></div>';
}
function filesCard(c) {
	var files = c.files || [];
	var kindLabel = c.doc_kinds || { id_card: 'Ταυτότητα', provider_bill: 'Λογαριασμός', other: 'Έγγραφο' };

	// Required-docs checklist
	var checklist = '';
	if (c.doc_checklist && c.doc_checklist.items && c.doc_checklist.items.length) {
		var ck = c.doc_checklist;
		var rows = ck.items.map(function (it) {
			return '<li class="ecrm-check ' + (it.ok ? 'is-ok' : 'is-missing') + '">' +
				'<span class="ecrm-check__mark">' + (it.ok ? '✓' : '&#9675;') + '</span>' +
				'<span>' + esc(it.label) + '</span></li>';
		}).join('');
		var banner = ck.complete
			? '<div class="ecrm-check__note is-ok">Όλα τα δικαιολογητικά παρόντα.</div>'
			: '<div class="ecrm-check__note is-missing">Λείπουν δικαιολογητικά — δεν μπορεί να δρομολογηθεί/ενεργοποιηθεί.</div>';
		checklist = '<div class="ecrm-step">Απαιτούμενα δικαιολογητικά</div><ul class="ecrm-checklist">' + rows + '</ul>' + banner;
	}

	var list;
	if (!files.length) {
		list = '<div class="ecrm-empty">Δεν έχουν επισυναφθεί έγγραφα.</div>';
	} else {
		list = '<div class="ecrm-files">' + files.map(function (f) {
			var thumb = f.is_image && f.url ? '<img src="' + esc(f.url) + '" alt="">' : '<span class="ecrm-file__ext">' + (f.mime === 'application/pdf' ? 'PDF' : 'DOC') + '</span>';
			return '<a class="ecrm-file" href="' + esc(f.url || '#') + '" target="_blank" rel="noopener">' +
				'<span class="ecrm-file__thumb">' + thumb + '</span>' +
				'<span class="ecrm-file__meta"><span class="ecrm-file__name">' + esc(f.filename || 'έγγραφο') + '</span>' +
				'<span class="ecrm-file__kind">' + esc(kindLabel[f.doc_kind] || 'Έγγραφο') + '</span></span></a>';
		}).join('') + '</div>';
	}

	// Inline upload control
	var kindOpts = Object.keys(kindLabel).map(function (k) {
		return '<option value="' + esc(k) + '">' + esc(kindLabel[k]) + '</option>';
	}).join('');
	var upload = '<div class="ecrm-docup" data-docup="' + c.id + '">' +
		'<select class="ecrm-input ecrm-docup__kind" data-docup-kind>' + kindOpts + '</select>' +
		'<input type="file" multiple accept="image/*,application/pdf" data-docup-file>' +
		'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-docup-go>Προσθήκη</button>' +
		'<span class="ecrm-docup__msg" data-docup-msg></span></div>';

	return '<div class="ecrm-card">' + checklist + '<div class="ecrm-step">Έγγραφα</div>' + list + upload + '</div>';
}
/* Η ώρα υπογραφής, ΧΩΡΙΣ να περάσει από Date.
 *
 * Το `signed_at` γράφεται με `current_time('mysql')`, δηλαδή είναι ΤΟΠΙΚΗ ώρα
 * του site. Οι `fmtDate()`/`timeAgo()` του format.js προσθέτουν 'Z' και τη
 * διαβάζουν ως UTC — γι' αυτό γράφουν «Same UTC caveat» πάνω τους. Σε σχετική
 * ένδειξη («2ω πριν») η μετατόπιση περνά απαρατήρητη· σε πεδίο AUDIT θα έδειχνε
 * ώρα +3 από την πραγματική, που είναι χειρότερο από το να μη δείχνει τίποτα.
 *
 * Η συμβολοσειρά ΕΙΝΑΙ ήδη η ώρα που θέλουμε να δείξουμε. Την κόβουμε, δεν την
 * ερμηνεύουμε: μηδέν ζώνη ώρας, ίδιο αποτέλεσμα σε όποια χώρα κι αν κάθεται ο
 * browser του συνεργάτη. */
function signStamp(sql) {
	var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(String(sql || ''));

	return m ? m[3] + '/' + m[2] + '/' + m[1] + ' ' + m[4] + ':' + m[5] : '';
}

/* Το IP του πελάτη, μασκαρισμένο — απόφαση ιδιοκτήτη 2026-08-21.
 *
 * Κρατά τα δύο πρώτα τμήματα και σβήνει τα υπόλοιπα, όπως το δείχνει το ίδιο το
 * UX kit (`85.72.xxx.xxx`). Δέχεται και IPv6, γιατί ο `RequestIp::current()`
 * περνά τις τιμές από `FILTER_VALIDATE_IP` και δεν υπόσχεται IPv4.
 *
 * ΠΡΟΣΟΧΗ, και είναι γραμμένο εδώ ώστε να μη διαβαστεί ως εγγύηση: η μάσκα
 * ΔΕΝ κρύβει το IP από την οθόνη. Το `class-ecrm-tracking.php` το γράφει
 * ολόκληρο μέσα στο `message` του `status_change`, που τυπώνεται αυτούσιο στο
 * «Ιστορικό ροής» της ίδιας καρτέλας. Είναι διακριτικότητα στην κύρια θέση, όχι
 * απόκρυψη. Αν ζητηθεί πραγματική απόκρυψη, το σημείο είναι το μήνυμα. */
function maskIp(ip) {
	ip = String(ip == null ? '' : ip).trim();

	if (!ip) return '';

	if (ip.indexOf(':') >= 0) {
		return ip.split(':').slice(0, 2).join(':') + ':\u2022\u2022\u2022';
	}

	var p = ip.split('.');

	return p.length === 4 ? p[0] + '.' + p[1] + '.\u2022\u2022\u2022.\u2022\u2022\u2022' : '\u2022\u2022\u2022';
}

function renderDetail(view, d) {
	var c = d.contract, statuses = d.statuses || {}, acts = d.activation_types || {};
	var name = c.company_name || ((c.first_name || '') + ' ' + (c.last_name || '')).trim() || '—';
	var energy = energyLabel(c.energy_type);

	// Only the current status plus what ContractStatus::allowedNext() actually
	// permits from here — not all twelve, always. The server was already the
	// real gate (409 on anything else); this just stops the screen offering
	// moves it is guaranteed to refuse. d.allowed_next missing (older cached
	// response) falls back to every status, same as before this change.
	var allowedSet = {};
	allowedSet[c.status] = true;
	(d.allowed_next || Object.keys(statuses)).forEach(function (s) { allowedSet[s] = true; });
	// Η τρέχουσα κατάσταση φεύγει από τη λίστα και γίνεται αφετηρία: το πάνελ
	// δείχνει «πού είμαι → πού μπορώ να πάω» αντί για πλέγμα ισότιμων κουμπιών.
	// Το allowed_next το δίνει ο server από το ContractStatus::allowedNext(),
	// οπότε ο γράφος δεν αντιγράφεται εδώ — ερωτάται.
	var statusOpts = Object.keys(statuses).filter(function (s) {
		return allowedSet[s] && s !== c.status;
	}).map(function (s) {
		return '<button type="button" class="ecrm-statuschip ecrm-badge--' + s + '" data-status="' + s + '">' + esc(statuses[s]) + '</button>';
	}).join('');

	// Τερματική κατάσταση: το allowedNext() είναι ο κενός πίνακας για τα
	// «Ακυρώθηκε» και «Τερματίστηκε». Χωρίς αυτό το σκέλος το πάνελ έδειχνε
	// έναν τίτλο πάνω από το τίποτα.
	var statusFlow = '<div class="ecrm-statusflow">' +
		'<span class="ecrm-statusflow__now ecrm-badge ecrm-badge--' + esc(c.status) + '">' + esc(statuses[c.status] || c.status) + '</span>' +
		(statusOpts
			? '<span class="ecrm-statusflow__arrow" aria-hidden="true">&rarr;</span><div class="ecrm-statuschips">' + statusOpts + '</div>'
			: '<span class="ecrm-statusflow__end">Τερματική κατάσταση — καμία επιτρεπτή μετάβαση.</span>') +
		'</div>';

	var timeline = (c.events && c.events.length)
		? '<ul class="ecrm-timeline">' + c.events.map(function (e) {
			var label = e.type === 'status_change'
				? (statuses[e.from_status] || e.from_status || '—') + ' → ' + (statuses[e.to_status] || e.to_status || '—')
				: (e.message || e.type);
			return '<li><span class="ecrm-timeline__dot"></span><div><div class="ecrm-timeline__txt">' + esc(label) + '</div><div class="ecrm-timeline__time">' + timeAgo(e.created_at) + '</div></div></li>';
		}).join('') + '</ul>'
		: '<div class="ecrm-empty">Καμία καταγραφή.</div>';

	// Το checklist του rail βγαίνει από πεδία που ΗΔΗ υπάρχουν στην οθόνη — δεν
	// εφευρίσκεται κατάσταση που το backend δεν στέλνει. Πέντε γραμμές, καθεμιά
	// με ένα ερώτημα που ο συνεργάτης μπορεί να απαντήσει κοιτώντας δίπλα.
	var SIGNED_ON = ['signed', 'processing', 'pending', 'resolved', 'routed', 'active'];
	var checks = [
		{ ok: !!(c.afm && c.adt),               txt: 'Στοιχεία ταυτότητας' },
		{ ok: !!c.supply_number,                txt: 'Αριθμός παροχής' },
		{ ok: !!c.program_name,                 txt: 'Πρόγραμμα' },
		{ ok: SIGNED_ON.indexOf(c.status) >= 0, txt: 'Υπογραφή πελάτη' },
		{ ok: !!c.consent_at,                   txt: 'Συναίνεση GDPR' }
	];
	var done = checks.filter(function (x) { return x.ok; }).length;
	var checklistHTML = '<ul class="ecrm-rcheck">' + checks.map(function (x) {
		return '<li class="' + (x.ok ? 'is-ok' : '') + '"><span class="ecrm-rcheck__m">' + (x.ok ? '✓' : '○') + '</span>' + esc(x.txt) + '</li>';
	}).join('') + '</ul>';

	function kv(label, val) {
		return '<div class="ecrm-kv"><span>' + esc(label) + '</span><b>' + (val ? esc(val) : '—') + '</b></div>';
	}

	/* Το audit της υπογραφής — μπαίνει ΜΟΝΟ όταν υπάρχει `signed_at`, οπότε σε
	   πρόχειρη ή ανυπόγραφη σύμβαση το rail μένει ακριβώς όπως ήταν. Καμία νέα
	   κλήση: το `signed_at` και το `signed_ip` είναι στήλες του πίνακα
	   (EnsureLegacyColumns), γράφονται από το `applyTransition()` μέσω του
	   WritableColumns, και ταξιδεύουν ήδη με το `SELECT c.*` της findDetailed(). */
	var auditCard = c.signed_at
		? '<div class="ecrm-card ecrm-rcard--audit">' +
			'<div class="ecrm-step">Υπογραφή &nbsp;<b>\u2713</b></div>' +
			kv('Ώρα', signStamp(c.signed_at)) +
			kv('IP πελάτη', maskIp(c.signed_ip)) +
			'<div class="ecrm-rcard__status">Κατάσταση <span class="ecrm-badge ecrm-badge--' +
			esc(c.status) + '">' + esc(statuses[c.status] || c.status) + '</span></div>' +
			'</div>'
		: '';

	view.innerHTML = '' +
		'<div class="ecrm-detail2"><div class="ecrm-detail2__main">' +

		'<div class="ecrm-dhead">' +
		'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-go="contracts"><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M19 12H5M11 6l-6 6 6 6"/></svg> Πίσω</button>' +
		'<div class="ecrm-dhead__who"><h2 class="ecrm-dhead__name">' + esc(name) + '</h2>' +
		'<div class="ecrm-dhead__sub">' + esc(c.code || '') + (c.created_at ? ' · δημιουργία ' + esc(fmtDate(c.created_at)) : '') + '</div></div>' +
		'<div class="ecrm-dhead__acts">' +
		'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-printform="' + c.id + '"><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 9V3h10v6M7 18H5a2 2 0 01-2-2v-4a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2h-2M7 15h10v6H7z"/></svg> PDF έντυπο</button>' +
		'<button type="button" class="ecrm-btn ecrm-btn--primary ecrm-btn--sm" data-detail-edit>' + svgIcon('edit') + ' Επεξεργασία</button>' +
		'</div></div>' +

		'<div class="ecrm-dsum"><span class="ecrm-badge ecrm-badge--' + esc(c.status) + '">' + esc(statuses[c.status] || c.status) + '</span>' +
		'<span class="ecrm-dsum__t">' + esc([c.afm ? 'ΑΦΜ ' + c.afm : '', c.provider_name, c.program_name].filter(Boolean).join(' · ')) + '</span>' +
		(c.consent_at ? '<span class="ecrm-chip-consent" title="Συναίνεση: ' + esc(fmtDate(c.consent_at)) + '">✓ GDPR</span>' : '') +
		'</div>' +

		'<div class="ecrm-cols">' +
		'<div class="ecrm-card"><div class="ecrm-step">Στοιχεία πελάτη</div>' +
		kv('Ονοματεπώνυμο', name) + kv('ΑΦΜ', c.afm) + kv('ΔΟΥ', c.doy) + kv('ΑΔΤ', c.adt) +
		kv('Πατρώνυμο', c.father_name) + kv('Ημ. γέννησης', c.birth_date) +
		kv('Κινητό', c.mobile) + kv('Τηλέφωνο', c.phone) + kv('Email', c.email) +
		'</div>' +
		'<div class="ecrm-card"><div class="ecrm-step">Παροχή / διεύθυνση</div>' +
		kv('Αρ. παροχής', c.supply_number) + kv('Μετρητής', c.meter_number) + kv('Τιμολόγιο', c.invoice_code) +
		kv('Διεύθυνση', [c.street, c.street_no].filter(Boolean).join(' ')) +
		kv('Πόλη / ΤΚ', [c.city, c.postal_code].filter(Boolean).join(' · ')) + kv('Νομός', c.region) +
		kv('Πάροχος', c.provider_name) + kv('Πρόγραμμα', c.program_name) + kv('Είδος', energy) +
		kv('Ενεργοποίηση', acts[c.activation_type] || c.activation_type) +
		(c.notes ? '<div class="ecrm-notes"><strong>Σχόλια:</strong> ' + esc(c.notes) + '</div>' : '') +
		'</div></div>' +

		'<div class="ecrm-cols">' +
		'<div class="ecrm-card"><div class="ecrm-step">Ιστορικό ροής</div>' + timeline + '</div>' +
		'<div class="ecrm-card"><div class="ecrm-step">Αλλαγή κατάστασης</div>' + statusFlow + '</div>' +
		'</div>' +
		filesCard(c) +
		'</div>' +

		'<aside class="ecrm-drail">' +
		auditCard +
		'<div class="ecrm-card ecrm-rcard' + (done === checks.length ? ' is-ok' : '') + '">' +
		'<div class="ecrm-step">Checklist &nbsp;<b>' + done + '/' + checks.length + '</b></div>' + checklistHTML + '</div>' +
		'<div class="ecrm-drail__acts">' +
		'<button type="button" class="ecrm-btn ecrm-btn--primary" data-sign="' + c.id + '"><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 20h18M4 16l9-9 3 3-9 9H4z"/><path d="M13 5l3 3"/></svg> Αποστολή για υπογραφή</button>' +
		'<button type="button" class="ecrm-btn ecrm-btn--ghost" data-provform="' + c.id + '"><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4M9 13h6M9 17h4"/></svg> Λήψη εντύπου παρόχου</button>' +
		(c.track_url ? '<button type="button" class="ecrm-btn ecrm-btn--ghost" data-track="' + esc(c.track_url) + '"><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M10 13a5 5 0 007 0l3-3a5 5 0 00-7-7l-1.5 1.5M14 11a5 5 0 00-7 0l-3 3a5 5 0 007 7l1.5-1.5"/></svg> Link παρακολούθησης</button>' : '') +
		'<button type="button" class="ecrm-btn ecrm-btn--ghost" data-task-new="' + c.id + '"><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg> Νέα εργασία</button>' +
		'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--danger" data-detail-del="' + c.id + '"><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V5a1 1 0 011-1h4a1 1 0 011 1v2M6 7l1 13a1 1 0 001 1h8a1 1 0 001-1l1-13"/></svg> Διαγραφή</button>' +
		'</div></aside></div>';


	view.querySelectorAll('.ecrm-statuschip').forEach(function (b) {
		b.addEventListener('click', function () {
			var to = this.getAttribute('data-status');
			if (to === c.status) return;
			fetch(api('/contracts/' + c.id + '/status'), { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H()), body: JSON.stringify({ status: to }) })
				.then(function (r) { return r.json(); })
				.then(function (res) { if (res && res.ok) { toast('Κατάσταση: ' + (statuses[to] || to)); openDetail(c.id); } else { toast((res && res.error) || 'Αποτυχία αλλαγής.', false); } })
				.catch(function () { toast('Σφάλμα δικτύου.', false); });
		});
	});

	var dEdit = view.querySelector('[data-detail-edit]');
	if (dEdit) dEdit.addEventListener('click', function () { openEdit(c); });

	var delBtn = view.querySelector('[data-detail-del]');
	if (delBtn) delBtn.addEventListener('click', function () {
		var b = this;

		function doDelete() {
			b.disabled = true; var t = b.textContent; b.textContent = 'Διαγραφή…';
			fetch(api('/contracts/' + c.id), { method: 'DELETE', headers: H() })
				.then(function (r) { return r.text().then(function (x) { try { return JSON.parse(x); } catch (e) { throw new Error('HTTP ' + r.status); } }); })
				.then(function (d2) {
					if (d2 && d2.ok) { toast('Η αίτηση διαγράφηκε.', true); go('contracts'); }
					else { b.disabled = false; b.textContent = t; toast((d2 && d2.error) || 'Αποτυχία διαγραφής.', false); }
				})
				.catch(function (err) { b.disabled = false; b.textContent = t; toast((err && err.message) || 'Σφάλμα δικτύου.', false); });
		}

		/* Η πύλη με πληκτρολόγηση ΜΟΝΟ όταν η αίτηση έχει βγει από το πρόχειρο.
		   Ένα πρόχειρο δεν έχει έγγραφα, ούτε υπογραφή, ούτε ιστορικό — δεν
		   υπάρχει τίποτα να χαθεί, και μια τελετουργία που ζητιέται και εκεί
		   μαθαίνει τον χρήστη να την προσπερνά παντού. Απόφαση 21/08. */
		if (c.status === 'draft') {
			if (!window.confirm('Διαγραφή του πρόχειρου ' + (c.code || '') + ';')) { return; }
			doDelete();
			return;
		}

		// Ο κωδικός είναι αυτό που ξεχωρίζει ΑΥΤΗ την αίτηση από τη διπλανή.
		// Πέφτει στο id μόνο αν δεν έχει αποδοθεί ακόμη κωδικός — δεν μένει
		// ποτέ χωρίς κάτι να πληκτρολογηθεί.
		var hasCode = !!c.code;
		confirmTyped({
			expect: hasCode ? String(c.code) : String(c.id),
			expectLabel: hasCode ? 'Πληκτρολόγησε τον κωδικό της αίτησης' : 'Πληκτρολόγησε τον αριθμό της αίτησης',
			title: 'Διαγραφή αίτησης ' + (c.code || ('#' + c.id)),
			lead: ['Η ενέργεια είναι ', { b: 'οριστική' }, ' και θα διαγράψει και τα σχετικά έγγραφα και τις ', { b: 'υπογραφές' }, '.'],
			confirm: 'Οριστική διαγραφή',
			onConfirm: doDelete,
		});
	});
	var printBtn = view.querySelector('[data-printform]');
	if (printBtn) printBtn.addEventListener('click', function () {
		var b = this, win = window.open('', '_blank'); b.disabled = true; var t = b.textContent; b.textContent = 'Άνοιγμα…';
		fetch(api('/contracts/' + c.id + '/provider-form'), { headers: H() })
			.then(function (r) { return r.text(); })
			.then(function (x) {
				var d = JSON.parse(x);
				if (!d || !d.ok) throw new Error((d && d.error) || 'fail');
				var bin = atob(d.b64), len = bin.length, arr = new Uint8Array(len);
				for (var i = 0; i < len; i++) { arr[i] = bin.charCodeAt(i); }
				var url = URL.createObjectURL(new Blob([arr], { type: 'application/pdf' }));
				if (win) { win.location.href = url; } else { window.open(url, '_blank'); }
			})
			.catch(function (e) { if (win) { try { win.close(); } catch (er) {} } toast((e && e.message) || 'Αποτυχία.', false); })
			.finally(function () { b.disabled = false; b.textContent = t; });
	});
	var provBtn = view.querySelector('[data-provform]');
	if (provBtn) provBtn.addEventListener('click', function () { downloadBinary('/contracts/' + c.id + '/provider-form', this, 'Λήψη…', '<svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4M9 13h6M9 17h4"/></svg> Λήψη εντύπου παρόχου'); });

	var signBtn = view.querySelector('[data-sign]');
	if (signBtn) signBtn.addEventListener('click', function () {
		var b = this; b.disabled = true;
		fetch(api('/contracts/' + c.id + '/sign-link'), { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H()), body: JSON.stringify({ email: true }) })
			.then(function (r) { return r.json(); })
			.then(function (d) {
				if (!d || !d.ok) { toast((d && d.error) || 'Αποτυχία.', false); return; }
				copyText(d.url).then(function (copied) {
					var lead = d.emailed
						? (copied ? 'Στάλθηκε email στον πελάτη. Σύνδεσμος (αντιγράφηκε):' : 'Στάλθηκε email στον πελάτη. Σύνδεσμος:')
						: (copied ? 'Σύνδεσμος υπογραφής (αντιγράφηκε) — στείλ τον στον πελάτη:' : 'Σύνδεσμος υπογραφής — αντίγραψέ τον και στείλ τον στον πελάτη:');
					prompt(lead, d.url);
					if (copied) { toast(d.emailed ? 'Στάλθηκε email υπογραφής στον πελάτη.' : 'Ο σύνδεσμος αντιγράφηκε.'); }
					else { toast(d.emailed ? 'Στάλθηκε email υπογραφής στον πελάτη.' : 'Ο σύνδεσμος δημιουργήθηκε.'); }
					openDetail(c.id);
				});
			})
			.catch(function () { toast('Σφάλμα δικτύου.', false); })
			.finally(function () { b.disabled = false; });
	});

	var trackBtn = view.querySelector('[data-track]');
	if (trackBtn) trackBtn.addEventListener('click', function () {
		var url = this.getAttribute('data-track');
		copyText(url).then(function (copied) {
			prompt(copied ? 'Σύνδεσμος παρακολούθησης (αντιγράφηκε) — στείλ τον στον πελάτη:' : 'Σύνδεσμος παρακολούθησης — αντίγραψέ τον και στείλ τον στον πελάτη:', url);
			toast(copied ? 'Ο σύνδεσμος παρακολούθησης αντιγράφηκε.' : 'Σύνδεσμος παρακολούθησης έτοιμος.');
		});
	});

	/* Μία φόρμα αντί για δύο prompt στη σειρά.
	 *
	 * Το δεύτερο prompt ζητούσε ΗΜΕΡΟΜΗΝΙΑ ΩΣ ΚΕΙΜΕΝΟ, με το σχήμα μέσα σε
	 * παρένθεση: «π.χ. 2026-06-20 10:00». Καμία επικύρωση, κανένα ημερολόγιο,
	 * και ό,τι γραφόταν πήγαινε αυτούσιο στο due_at. Το datetime-local δίνει
	 * ημερολόγιο στο desktop και NATIVE επιλογέα στο κινητό — εκεί που ο
	 * συνεργάτης δουλεύει — και στέλνει πάντα «2026-06-20T10:00».
	 *
	 * Το «T» δεν χρειάστηκε τίποτα στον server: το TasksController::dueDate()
	 * ήδη κάνει str_replace('T', ' ', …). Ο server ήταν έτοιμος από την αρχή·
	 * το πεδίο εισόδου ήταν που δεν ήταν. */
	var taskNew = view.querySelector('[data-task-new]');
	if (taskNew) taskNew.addEventListener('click', function () {
		var body =
			'<div class="ecrm-modal__card ecrm-modal__stack">' +
				'<label class="ecrm-field"><span class="ecrm-field__label">Τίτλος</span>' +
				'<input class="ecrm-input" data-task-title value="Επανάκληση πελάτη">' +
				'<span class="ecrm-field__err" data-task-err hidden>Ο τίτλος δεν μπορεί να είναι κενός.</span></label>' +
				'<label class="ecrm-field"><span class="ecrm-field__label">Πότε — προαιρετικό</span>' +
				'<input class="ecrm-input" type="datetime-local" data-task-due></label>' +
			'</div>';

		openDialog({
			eyebrow: 'Νέα εργασία',
			title: 'Εργασία για ' + (c.code || ('#' + c.id)),
			body: body,
			confirm: 'Δημιουργία',
			onConfirm: function (el, close, btn) {
				var title = (el.querySelector('[data-task-title]').value || '').trim();
				var when = el.querySelector('[data-task-due]').value || '';

				// Ο τίτλος είναι το μόνο υποχρεωτικό. Το prompt το έλυνε με
				// σιωπηλή έξοδο· εδώ ο χρήστης βλέπει ΓΙΑΤΙ δεν έγινε τίποτα.
				// Και με λέξεις, όχι μόνο με κόκκινο περίγραμμα: η πρώτη γραφή
				// έβαφε μόνο το πλαίσιο, και στην απόδοση ήταν ένα κόκκινο κουτί
				// που δεν έλεγε τι του λείπει.
				var titleField = el.querySelector('[data-task-title]');
				var titleErr = el.querySelector('[data-task-err]');

				if (!title) {
					titleField.classList.add('is-err');
					titleErr.hidden = false;
					titleField.focus();
					return;
				}

				titleField.classList.remove('is-err');
				titleErr.hidden = true;

				btn.disabled = true;
				fetch(api('/tasks'), { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H()), body: JSON.stringify({ title: title, due_at: when, contract_id: c.id }) })
					.then(function (r) { return r.json(); })
					.then(function (res) {
						if (res && res.ok) { close(); toast('Δημιουργήθηκε εργασία.'); }
						else { btn.disabled = false; toast((res && res.error) || 'Αποτυχία.', false); }
					})
					.catch(function () { btn.disabled = false; toast('Σφάλμα δικτύου.', false); });
			},
		});
	});

	var docGo = view.querySelector('[data-docup-go]');
	if (docGo) docGo.addEventListener('click', function () {
		var wrap = view.querySelector('[data-docup]');
		var input = wrap.querySelector('[data-docup-file]');
		var kind = wrap.querySelector('[data-docup-kind]').value;
		var msg = wrap.querySelector('[data-docup-msg]');
		if (!input.files || !input.files.length) { msg.textContent = 'Επίλεξε αρχείο.'; msg.className = 'ecrm-docup__msg is-err'; return; }
		var fd = new FormData();
		for (var i = 0; i < input.files.length; i++) { fd.append('files[]', input.files[i]); fd.append('kinds[]', kind); }
		var b = this; b.disabled = true; msg.textContent = 'Ανέβασμα…'; msg.className = 'ecrm-docup__msg';
		fetch(api('/contracts/' + c.id + '/files'), { method: 'POST', headers: H(), body: fd })
			.then(function (r) { return r.json(); })
			.then(function (d) {
				if (!d || !d.ok) {
					msg.textContent = (d && d.error) || 'Αποτυχία.'; msg.className = 'ecrm-docup__msg is-err'; b.disabled = false;
					return;
				}
				// Όταν δεν μπήκε τίποτα δεν ξαναφορτώνουμε την καρτέλα: το ξαναχτίσιμο
				// θα έσβηνε το μήνυμα, που είναι το μόνο χρήσιμο εδώ.
				var note = rejectedNote(d.rejected);
				if (!d.saved) {
					msg.textContent = note || 'Δεν ανέβηκε κανένα αρχείο.';
					msg.className = 'ecrm-docup__msg is-err'; b.disabled = false;
					return;
				}
				toast('Προστέθηκαν ' + d.saved + ' έγγραφα.' + (note ? ' ' + note : ''), !note);
				openDetail(c.id);
			})
			.catch(function () { msg.textContent = 'Σφάλμα δικτύου.'; msg.className = 'ecrm-docup__msg is-err'; b.disabled = false; });
	});
}
function downloadBinary(path, btn, busy, idle) {
	btn.disabled = true; var t = btn.textContent; btn.textContent = busy;
	fetch(api(path), { headers: H() })
		.then(function (r) {
			return r.text().then(function (txt) {
				try { return JSON.parse(txt); }
				catch (e) { throw new Error('HTTP ' + r.status + ': ' + txt.slice(0, 300)); }
			});
		})
		.then(function (d) {
			if (!d || !d.ok) { toast((d && d.error) || 'Αποτυχία.', false); return; }
			var bin = atob(d.b64), len = bin.length, arr = new Uint8Array(len);
			for (var i = 0; i < len; i++) { arr[i] = bin.charCodeAt(i); }
			var a = document.createElement('a');
			a.href = URL.createObjectURL(new Blob([arr], { type: d.mime }));
			a.download = d.filename; document.body.appendChild(a); a.click();
			setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
		})
		.catch(function (err) { toast((err && err.message) || 'Σφάλμα δικτύου.', false); })
		.finally(function () { btn.disabled = false; btn.textContent = idle || t; });
}
