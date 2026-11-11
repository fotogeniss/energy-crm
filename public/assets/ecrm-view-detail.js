/* Energy CRM — one contract, in full: fields, documents, history, actions.
 *
 * The heaviest screen in the app and the one an agent spends the most time
 * on. It owns no state — everything it shows comes from a single fetch, and
 * every button either navigates away or posts and re-fetches.
 *
 * copyText() and downloadBinary() came with it rather than going to the
 * shared module. Both have exactly one caller, here, and a helper with one
 * user is not shared — it is misplaced. */

import { api, esc, fetch, H, markSubViewOpen, providerMark, rejectedNote, toast, viewEl } from '@energy-crm/util';
import { contractStages, energyLabel, fmtDate, initials, svgIcon, timeAgo, tint } from '@energy-crm/format';
import { go, openEdit } from '@energy-crm/navigate';
import { confirmTyped, confirmTypedWithReason, openDialog } from '@energy-crm/dialog';
import { storedForAi } from '@energy-crm/ai-docs';
import { checkPhoto } from '@energy-crm/photo-check';
import { pickReason } from '@energy-crm/close-reason';

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
/* (353h) Κλικ έξω από ανοιχτό «⋯» της καρτέλας το κλείνει. Ένας listener για
 * όλη τη ζωή της σελίδας, όχι ένας ανά άνοιγμα καρτέλας. */
document.addEventListener('click', function (e) {
	document.querySelectorAll('[data-dh-more][open]').forEach(function (d) {
		if (!d.contains(e.target)) { d.open = false; }
	});
});

/* (353h) Η «Signature» είναι ανοιχτή; Ίδιος έλεγχος με τις άλλες οθόνες. */
function isSignature() {
	var app = document.getElementById('ecrm-app');
	return !!app && app.getAttribute('data-ui') === 'signature';
}
export function openDetail(id) {
	go('contract-detail');
	markSubViewOpen();
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
	// Η αίτηση/φύλλα/υπογεγραμμένο αντίγραφο δεν έρχονται καν εδώ: ο server τα
	// κρατά έξω (SystemKind) -- έχουν το δικό τους κουμπί «PDF έντυπο».
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

	// Έγγραφα λήξιμου είδους (π.χ. ταυτότητα) που έχουν ήδη λήξει -- παρόντα,
	// αλλά όχι πια έγκυρα. Ξεχωριστό banner από το checklist: το ένα λέει
	// "λείπει", το άλλο "υπάρχει αλλά δεν ισχύει πια" -- διαφορετικό πρόβλημα,
	// ίδιο μπλοκάρισμα σε routed/active (ContractStatusController).
	var expiredNote = '';
	if (c.doc_expired && c.doc_expired.length) {
		var elabels = c.doc_expired.map(function (e) { return esc(e.label) + ' (έληξε ' + esc(fmtDate(e.expires_at)) + ')'; }).join(', ');
		expiredNote = '<div class="ecrm-check__note is-missing">Έχει λήξει: ' + elabels + ' — δεν μπορεί να δρομολογηθεί/ενεργοποιηθεί.</div>';
	}

	var list;
	if (!files.length) {
		list = '<div class="ecrm-empty">Δεν έχουν επισυναφθεί έγγραφα.</div>';
	} else {
		list = '<div class="ecrm-files">' + files.map(function (f) {
			var thumb = f.is_image && f.url ? '<img src="' + esc(f.url) + '" alt="">' : '<span class="ecrm-file__ext">' + (f.mime === 'application/pdf' ? 'PDF' : 'DOC') + '</span>';
			var expiryTag = f.expires_at ? '<span class="ecrm-file__kind">λήξη ' + esc(fmtDate(f.expires_at)) + '</span>' : '';

			var link = '<a class="ecrm-file__link" href="' + esc(f.url || '#') + '" target="_blank" rel="noopener">' +
				'<span class="ecrm-file__thumb">' + thumb + '</span>' +
				'<span class="ecrm-file__meta"><span class="ecrm-file__name">' + esc(f.filename || 'έγγραφο') + '</span>' + expiryTag + '</span>' +
				'</a>';

			/* (272) Χειροκίνητη διόρθωση είδους -- για έγγραφα που η αυτόματη
			   ανάγνωση δεν κατάφερε ποτέ να ταξινομήσει με σιγουριά και έμεναν
			   κλειδωμένα σε γενική ετικέτα («Άλλο έγγραφο») για πάντα. Πάντα
			   διαθέσιμο, όχι μόνο όταν υπάρχει διόρθωση AI να αναιρεθεί -- και
			   ΕΞΩ από το <a> της λήψης, ίδιος λόγος με το κουμπί «Αναίρεση» πιο
			   κάτω: interactive στοιχείο μέσα σε σύνδεσμο είναι άκυρο HTML. */
			// Αρχείο του συστήματος (υπογραφή): σταθερή ετικέτα, όχι επιλογή --
			// το είδος του δεν αλλάζει, αλλιώς η υπογραφή χάνεται από τα έντυπα.
			var kindPicker = f.system_label
				? '<span class="ecrm-file__kind">' + esc(f.system_label) + '</span>'
				: '<select class="ecrm-file__kindsel" data-setkind="' + esc(String(f.id)) + '">' +
				Object.keys(kindLabel).map(function (k) {
					return '<option value="' + esc(k) + '"' + (k === f.doc_kind ? ' selected' : '') + '>' + esc(kindLabel[k]) + '</option>';
				}).join('') + '</select>';

			// (274) Σβήσιμο λάθος/διπλού εγγράφου. Ίδιος λόγος με το
			// kindPicker: ΕΞΩ από το <a>, δικό του κουμπί -- όχι μέρος του
			// συνδέσμου λήψης.
			var delBtn = '<button type="button" class="ecrm-file__del" data-delfile="' + esc(String(f.id)) +
				'" title="Διαγραφή εγγράφου" aria-label="Διαγραφή εγγράφου">✕</button>';

			if (f.kind_source !== 'ai' || !f.kind_before) {
				return '<div class="ecrm-file">' + link + kindPicker + delBtn + '</div>';
			}

			return '<div class="ecrm-file ecrm-file--fixed">' + link + kindPicker + delBtn +
				'<span class="ecrm-aiflag">' +
					'<span class="ecrm-aiflag__txt">Η AI το διάβασε και το άλλαξε από «' +
						esc(kindLabel[f.kind_before] || 'Έγγραφο') + '»</span>' +
					'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-unkind="' + esc(String(f.id)) + '">Αναίρεση</button>' +
				'</span></div>';
		}).join('') + '</div>';
	}

	// Inline upload control
	//
	// Κάθε νέο αρχείο ξεκινούσε πάντα στο πρώτο kind του καταλόγου (`id_card`) γιατί κανένα <option> δεν είχε `selected` -- ο browser απλώς
	// διαλέγει το πρώτο. Τώρα το είδος μαντεύεται από το όνομα αρχείου (guessDocKind, παρακάτω στο renderDocupRows),
	// με ασφαλή προεπιλογή «Άλλο» όταν δεν αναγνωρίζεται -- ποτέ ξανά «Ταυτότητα» για ο,τιδήποτε δεν μοιάζει με ταυτότητα
	// (π.χ. μια signature.png).
	var kindMap = JSON.stringify(kindLabel);
	var expirable = c.doc_expirable || [];
	/* Ενα είδος ΑΝΑ ΑΡΧΕΙΟ, όχι ένα για όλο το batch.
	 *
	 * Μέχρι σήμερα υπήρχε ένα κοινό dropdown και η τιμή του στελνόταν για κάθε
	 * αρχείο της επιλογής. Οποιος ανέβαζε ταυτότητα και λογαριασμό μαζί —
	 * δηλαδή η συνηθισμένη περίπτωση — έπαιρνε το ένα από τα δύο με λάθος
	 * ετικέτα, και μετά η καρτέλα έλεγε «λείπουν δικαιολογητικά» για χαρτί που
	 * ήταν ήδη εκεί. Οι γραμμές γεμίζουν όταν επιλεγούν αρχεία (renderDocupRows). */
	var upload = '<div class="ecrm-docup" data-docup="' + c.id + '" data-docup-expirable="' + esc(JSON.stringify(expirable)) + '" data-docup-kinds="' + esc(kindMap) + '">' +
		'<input type="file" multiple accept="image/*,application/pdf" data-docup-file>' +
		'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-docup-go>Προσθήκη</button>' +
		'<span class="ecrm-docup__msg" data-docup-msg></span>' +
		'<div class="ecrm-docup__rows" data-docup-rows></div></div>';

	return '<div class="ecrm-card">' + checklist + expiredNote + '<div class="ecrm-step">Έγγραφα</div>' + list + upload + '</div>';
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
	// (339) Για την «Αναφορά προβλήματος»: ποια αίτηση ήταν ανοιχτή.
	view.setAttribute('data-contract-id', String(c.id || ''));
	view.setAttribute('data-contract-code', String(c.code || ''));
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
	// Καμία εξαίρεση: η παράκαμψη που έκρυβε το 'awaiting_signature' υπήρχε
	// όσο δύο ονόματα έδειχναν στο ίδιο βήμα (07/09/2026 — ένα πλέον).
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

	// «Ποιος» -- build queue 09/10, docs/UI-TIMELINE-ACTOR.html (§1.8,
	// εγκρίθηκε 25/08). Το backend στέλνει ήδη e.actor έτοιμο (task 05/126,
	// EventRepository + ContractsReadController::withActorNames()) -- 'Σύστημα'
	// για ενέργειες χωρίς συγκεκριμένο άνθρωπο (π.χ. αυτόματη εισαγωγή Excel),
	// αλλιώς το display_name. Χωρίς badge για «Σύστημα»: ένας έγχρωμος κύκλος
	// με αρχικά θα υποσχόταν πρόσωπο που δεν υπάρχει.
	var timeline = (c.events && c.events.length)
		? '<ul class="ecrm-timeline">' + c.events.map(function (e) {
			var label = e.type === 'status_change'
				? (statuses[e.from_status] || e.from_status || '—') + ' → ' + (statuses[e.to_status] || e.to_status || '—')
				: (REMARK_LABELS[e.type] ? REMARK_LABELS[e.type] + ': ' : '') + (e.message || e.type);
			/* Το `message` ενός status_change γράφεται εδώ και μήνες σε πολλές
			 * διαδρομές -- χειροκίνητο σχόλιο αλλαγής, «Μαζική αλλαγή
			 * κατάστασης», η εξήγηση του AutoProcess, το IP της ηλεκτρονικής
			 * υπογραφής, τώρα και το σχόλιο του παρόχου από την εισαγωγή Excel
			 * -- αλλά καμία οθόνη δεν το έδειχνε ποτέ: η ετικέτα από πάνω παίρνει
			 * ΜΟΝΟ το βέλος «από → προς». Δεν ήταν σχεδιαστική επιλογή, ήταν
			 * κενό (27/08/2026, docs/CHANGELOG.md). Δική του γραμμή, ΚΑΤΩ από
			 * την ετικέτα και όχι μέσα της: το «από → προς» το παράγει ο ίδιος ο
			 * κώδικας, το μήνυμα το γράφει άνθρωπος ή πάροχος -- δεν πρέπει να
			 * μοιάζουν το ίδιο πράγμα. */
			var note = (e.type === 'status_change' && e.message) ? e.message : '';
			// (342) Ο λόγος απόρριψης/ακύρωσης, δίπλα στο «από → προς».
			if (e.reason_label) label += ' · ' + e.reason_label;
			var who = e.actor
				? (e.actor === 'Σύστημα'
					? '<div class="ecrm-timeline__who"><span class="ecrm-timeline__whoname is-sys">' + esc(e.actor) + '</span></div>'
					: '<div class="ecrm-timeline__who"><span class="ecrm-cell-mark ecrm-cell-mark--cust" style="--h:' + tint(e.actor) + '">' + esc(initials(e.actor)) + '</span><span class="ecrm-timeline__whoname">' + esc(e.actor) + '</span></div>')
				: '';
			return '<li><span class="ecrm-timeline__dot"></span><div><div class="ecrm-timeline__txt">' + esc(label) + '</div>' +
				(note ? '<div class="ecrm-timeline__note">' + esc(note) + '</div>' : '') +
				'<div class="ecrm-timeline__time">' + timeAgo(e.created_at) + '</div>' + who + '</div></li>';
		}).join('') + '</ul>'
		: '<div class="ecrm-empty">Καμία καταγραφή.</div>';

	/* «Γιατί κάθεται» — και ΤΙΠΟΤΑ όταν δεν κάθεται.
	 *
	 * Ο server στέλνει `c.stuck` μόνο όταν η σύμβαση είναι πραγματικά εκτός του
	 * συνηθισμένου· εδώ δεν αποφασίζεται τίποτα, μόνο ζωγραφίζεται. Δεν υπάρχει
	 * «όλα καλά» και δεν υπάρχει κενό κουτί: υπάρχει κάρτα ή δεν υπάρχει.
	 *
	 * Καμία κλήση σε μοντέλο για να εμφανιστεί. Ο αριθμός είναι μέτρημα πάνω σε
	 * γεγονότα που ήδη γράφονται — ακαριαίο, δωρεάν, και δεν εφευρίσκει νούμερο
	 * για τα λεφτά κανενός. Η κρίση είναι δουλειά της Λίτσας, με το κουμπί.
	 */
	function stuckCard(x) {
		if (!x.stuck) { return ''; }

		var d = x.stuck;
		var label = statuses[x.status] || x.status;

		return '<div class="ecrm-card ecrm-rcard ecrm-stuck">' +
			'<div class="ecrm-step ecrm-stuck__eyebrow">Κάθεται</div>' +
			// Αδιάσπαστο κενό: στα 320px του rail το «4 μέρες» έσπαγε σε δύο
			// γραμμές, με το «4» να μένει μόνο του στο τέλος. Αριθμός χωρίς τη
			// μονάδα του, για μισό δευτερόλεπτο ανάγνωσης.
			'<div class="ecrm-stuck__n">' + esc(String(d.days)) + (d.days === 1 ? '\u00a0μέρα' : '\u00a0μέρες') + '</div>' +
			'<p class="ecrm-stuck__p">σε «' + esc(label) + '». Ο συνήθης χρόνος είναι <b>' +
				esc(String(d.typical)) + (d.typical === 1 ? '\u00a0μέρα' : '\u00a0μέρες') +
				'</b> <span class="ecrm-stuck__s">(από ' + esc(String(d.sample)) + ' αιτήσεις)</span>.</p>' +
			'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm ecrm-stuck__ask" data-ask-litsa>' +
				'Ρώτα τον AIde γι\' αυτή την αίτηση</button>' +
			'</div>';
	}

	// Το checklist του rail βγαίνει από πεδία που ΗΔΗ υπάρχουν στην οθόνη — δεν
	// εφευρίσκεται κατάσταση που το backend δεν στέλνει. Πέντε γραμμές, καθεμιά
	// με ένα ερώτημα που ο συνεργάτης μπορεί να απαντήσει κοιτώντας δίπλα.
	//
	// AUDIT 30/08: εδώ ήταν μια λίστα καταστάσεων (SIGNED_ON) που έπρεπε να
	// μένει διαρκώς συγχρονισμένη με το ContractStatus -- και δεν ήταν: μια
	// σύμβαση που προχώρησε ως το τέλος ή ακυρώθηκε έδειχνε «○ Υπογραφή
	// πελάτη» παρότι είχε πράγματι υπογραφεί, γιατί οι τερματικές δεν ήταν
	// στη λίστα. Το `c.signed_at` είναι ήδη η πηγή αλήθειας παρακάτω σε αυτό
	// το ίδιο αρχείο (`auditCard`) -- ίδιο κριτήριο εδώ, όχι δεύτερη λίστα να
	// ξεσυγχρονιστεί. Το ίδιο μάθημα ακριβώς επανέλαβε ο `DeletionGate` στον
	// server στις 07/09.
	var checks = [
		// (281) hint: μόνο σε αυτή τη γραμμή -- ο συνεργάτης έβλεπε πράσινο
		// "Όλα τα δικαιολογητικά παρόντα" στα έγγραφα λίγο πιο πάνω και δεν
		// καταλάβαινε γιατί εδώ είναι ακόμα ○: τα δύο ελέγχουν διαφορετικά
		// πράγματα (αρχεία vs. τα ίδια τα πεδία ΑΦΜ/ΑΔΤ). Βλ. kv('ΑΦΜ', ...)
		// στην κάρτα "Στοιχεία πελάτη" παρακάτω -- ίδια πηγή, ίδιο tooltip.
		{ ok: !!(c.afm && c.adt),  txt: 'Στοιχεία ταυτότητας',
			hint: c.afm && !c.adt ? 'Λείπει το ΑΔΤ.' : !c.afm && c.adt ? 'Λείπει το ΑΦΜ.' : 'Λείπουν ΑΦΜ και ΑΔΤ.' },
		{ ok: !!c.program_name,    txt: 'Πρόγραμμα' }
	];
	// COMBO με δύο πρόσωπα (3β-Γ, 04/09): δύο γραμμές αντί για μία, ΜΟΝΟ όταν
	// το `signatures` φτάνει από τον server -- δηλαδή μόνο όταν χρειάζονται
	// πράγματι δύο ρόλοι (βλ. ContractsReadController::show(), το πεδίο
	// μπαίνει πάντα εκεί, ΟΧΙ υπό συνθήκη σαν στη λίστα). Κάθε άλλη αίτηση
	// μένει με τη μία ιστορική γραμμή, byte-for-byte ίδια με πριν.
	if (c.signatures && c.signatures.required && c.signatures.required.length > 1) {
		checks.push(
			{ ok: c.signatures.collected.indexOf('mobile') !== -1, txt: 'Υπογραφή πελάτη κινητής' },
			{ ok: c.signatures.collected.indexOf('energy') !== -1, txt: 'Υπογραφή πελάτη ενέργειας' }
		);
	} else {
		checks.push({ ok: !!c.signed_at, txt: 'Υπογραφή πελάτη' });
	}
	checks.push({ ok: !!c.consent_at, txt: 'Συναίνεση GDPR' });
	// (281β) «Ενώ δεν υπάρχει κανένα έγγραφο δεν μου λέει το checklist ότι
	// λείπουν έγγραφα» -- σωστό: το rail δεν είχε ΚΑΘΟΛΟΥ γραμμή για τα
	// δικαιολογητικά, μόνο η ξεχωριστή κάρτα «Απαιτούμενα δικαιολογητικά»
	// πιο κάτω (`c.doc_checklist`, ίδιο πεδίο, ίδιο "ok" ανά έγγραφο). Ιδιο
	// guard με εκείνη την κάρτα -- items άδεια/απούσα σημαίνει "καμία
	// απαίτηση για αυτόν τον τύπο", όχι "λείπουν", οπότε δεν μπαίνει γραμμή.
	if (c.doc_checklist && c.doc_checklist.items && c.doc_checklist.items.length) {
		var missingDocs = c.doc_checklist.items.filter(function (it) { return !it.ok; }).map(function (it) { return it.label; });
		checks.push({
			ok: !!c.doc_checklist.complete,
			txt: 'Δικαιολογητικά',
			hint: missingDocs.length ? 'Λείπει: ' + missingDocs.join(', ') + '.' : ''
		});
	}
	// «Αριθμός παροχής» μπαίνει μόνο για ρεύμα/αέριο — 2026-08-24: καμία
	// φόρμα Orizon δεν συλλέγει/τυπώνει supply_number (ίδιος λόγος με την
	// απόκρυψη του πεδίου στην κάρτα «Διεύθυνση» παραπάνω, (112)). Χωρίς
	// αυτό, ΚΑΘΕ αίτηση κινητής κολλούσε μόνιμα σε "λιγότερο από πλήρες"
	// checklist — ένα κουτί που δεν μπορεί ποτέ να μπει, όχι κάτι που
	// ξεχάστηκε.
	if (c.energy_type !== 'mobile') {
		checks.splice(1, 0, { ok: !!c.supply_number, txt: 'Αριθμός παροχής' });
	}
	var done = checks.filter(function (x) { return x.ok; }).length;
	var checklistHTML = '<ul class="ecrm-rcheck">' + checks.map(function (x) {
		var title = (!x.ok && x.hint) ? ' title="' + esc(x.hint) + '"' : '';
		return '<li class="' + (x.ok ? 'is-ok' : '') + '"' + title + '><span class="ecrm-rcheck__m">' + (x.ok ? '✓' : '○') + '</span>' + esc(x.txt) + '</li>';
	}).join('') + '</ul>';

	function kv(label, val) {
		return '<div class="ecrm-kv"><span>' + esc(label) + '</span><b>' + (val ? esc(val) : '—') + '</b></div>';
	}

	/* Το audit της υπογραφής — μπαίνει ΜΟΝΟ όταν υπάρχει `signed_at`, οπότε σε
	   πρόχειρη ή ανυπόγραφη σύμβαση το rail μένει ακριβώς όπως ήταν. Καμία νέα
	   κλήση: το `signed_at` και το `signed_ip` είναι στήλες του πίνακα
	   (EnsureLegacyColumns), γράφονται από το `applyTransition()` μέσω του
	   WritableColumns, και ταξιδεύουν ήδη με το `SELECT c.*` της findDetailed(). */
	// COMBO με δύο πρόσωπα (3β-Γ): η κάρτα δείχνει ΔΥΟ γραμμές, μία ανά ρόλο,
	// αντί να περιμένει το `signed_at` της σύμβασης -- που πλέον σημαίνει
	// «υπέγραψαν όλοι» (§ SignatureRoles::isComplete()) και θα έμενε σιωπηλός
	// για όσο λείπει ο δεύτερος. Καμία ώρα/IP ανά πρόσωπο -- αποφασίστηκε
	// ρητά 04/09: αρκεί το ✓/αναμονή, δεν ταξιδεύει ξεχωριστή ώρα ανά
	// υπογραφή σήμερα (δες docs/UI-COMBO-SIGN-STATUS.html, ερώτημα 1).
	var twoRoles = c.signatures && c.signatures.required && c.signatures.required.length > 1;
	var sigRows = '';
	if (twoRoles) {
		var mobileName = c.company_name || ((c.first_name || '') + ' ' + (c.last_name || '')).trim() || 'Πελάτης κινητής';
		var energyName = (c.extra && c.extra.combo_energy_name) || 'Πελάτης ενέργειας';
		[
			{ role: 'mobile', who: mobileName, sub: 'πελάτης κινητής' },
			{ role: 'energy', who: energyName, sub: 'πελάτης ενέργειας' }
		].forEach(function (p) {
			var signed = c.signatures.collected.indexOf(p.role) !== -1;
			sigRows += '<div class="ecrm-sigrow' + (signed ? ' is-ok' : ' is-wait') + '">' +
				'<span class="ecrm-sigrow__m">' + (signed ? '✓' : '○') + '</span>' +
				'<span class="ecrm-sigrow__who"><b>' + esc(p.who) + '</b><small>' + esc(p.sub) + '</small></span>' +
				'</div>';
		});
	}

	var auditCard = twoRoles
		? '<div class="ecrm-card ecrm-rcard--audit">' +
			'<div class="ecrm-step">Υπογραφές &nbsp;<b>' + c.signatures.collected.length + ' από ' + c.signatures.required.length + '</b></div>' +
			sigRows +
			'<div class="ecrm-rcard__status">Κατάσταση <span class="ecrm-badge ecrm-badge--' +
			esc(c.status) + '">' + esc(statuses[c.status] || c.status) + '</span></div>' +
			'</div>'
		: c.signed_at
		? '<div class="ecrm-card ecrm-rcard--audit">' +
			'<div class="ecrm-step">Υπογραφή &nbsp;<b>\u2713</b></div>' +
			kv('Ώρα', signStamp(c.signed_at)) +
			kv('IP πελάτη', maskIp(c.signed_ip)) +
			'<div class="ecrm-rcard__status">Κατάσταση <span class="ecrm-badge ecrm-badge--' +
			esc(c.status) + '">' + esc(statuses[c.status] || c.status) + '</span></div>' +
			'</div>'
		: '';

	/* (353h) Τα κουμπιά ενεργειών, μία φορά: τα χρησιμοποιούν και η κλασική
	   καρτέλα και η «Signature». Ίδια data-* και στις δύο, οπότε η καλωδίωση
	   πιο κάτω (querySelector μέσα στο view) δεν ξέρει ποια ζωγραφίστηκε. */
	var printBtnHTML = '<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-printform="' + c.id + '"><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 9V3h10v6M7 18H5a2 2 0 01-2-2v-4a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2h-2M7 15h10v6H7z"/></svg> PDF έντυπο</button>';
	var editBtnHTML = '<button type="button" class="ecrm-btn ecrm-btn--primary ecrm-btn--sm" data-detail-edit>' + svgIcon('edit') + ' Επεξεργασία</button>';
	var signBtnHTML = ( c.status !== 'active' && c.status !== 'cancelled'
		? '<button type="button" class="ecrm-btn ecrm-btn--primary" data-sign="' + c.id + '"><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 20h18M4 16l9-9 3 3-9 9H4z"/><path d="M13 5l3 3"/></svg> Στείλε για υπογραφή</button>'
		: '' );
	// (326) «+ Αέριο / + Ρεύμα στον ίδιο πελάτη»: το γεμίζει η
	// fillCompanion() μόλις απαντήσει το /companion -- κουμπί, ή σύνδεσμος
	// αν η δίδυμη αίτηση υπάρχει ήδη.
	var companionSlot = ( c.energy_type === 'power' || c.energy_type === 'gas' ? '<div data-companion-slot></div>' : '' );
	var provBtnHTML = '<button type="button" class="ecrm-btn ecrm-btn--ghost" data-provform="' + c.id + '"><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4M9 13h6M9 17h4"/></svg> Λήψη εντύπου παρόχου</button>';
	var trackBtnHTML = (c.track_url ? '<button type="button" class="ecrm-btn ecrm-btn--ghost" data-track="' + esc(c.track_url) + '"><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M10 13a5 5 0 007 0l3-3a5 5 0 00-7-7l-1.5 1.5M14 11a5 5 0 00-7 0l-3 3a5 5 0 007 7l1.5-1.5"/></svg> Σύνδεσμος παρακολούθησης</button>' : '');
	var taskBtnHTML = '<button type="button" class="ecrm-btn ecrm-btn--ghost" data-task-new="' + c.id + '"><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg> Νέα εργασία</button>';
	var delBtnHTML = '<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--danger" data-detail-del="' + c.id + '"><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V5a1 1 0 011-1h4a1 1 0 011 1v2M6 7l1 13a1 1 0 001 1h8a1 1 0 001-1l1-13"/></svg> Διαγραφή</button>';

	/* (353h) «Signature»: η ίδια καρτέλα, άλλος σκελετός -- docs/UI-REDESIGN-NEW-SKELETON.html,
	 * καρτέλα «Καρτέλα αίτησης». Μεγάλο όνομα με τις ενέργειες δίπλα, λωρίδα με
	 * τους πέντε σταθμούς, στοιχεία σε κουτάκια, έγγραφα ως μικρογραφίες, πορεία
	 * δεξιά. ΤΑ ΙΔΙΑ κομμάτια με την κλασική (timeline, statusFlow, filesCard,
	 * stuckCard, auditCard, checklist, παρατηρήσεις, κουμπιά): αλλάζει μόνο η
	 * θέση τους, όχι τι κάνουν, οπότε καμία καλωδίωση δεν διπλασιάζεται. */
	function sigDetailHTML() {
		function fact(label, val, wide) {
			return '<div class="sig-fact' + (val ? '' : ' is-empty') + (wide ? ' is-wide' : '') + '"><small>' + esc(label) + '</small><b>' + (val ? esc(val) : '—') + '</b></div>';
		}
		var stages = contractStages(c);
		var ck = c.doc_checklist && c.doc_checklist.items && c.doc_checklist.items.length ? c.doc_checklist : null;
		var sg = twoRoles ? c.signatures : null;
		var subs = [
			c.created_at ? fmtDate(c.created_at) : '',
			ck ? ck.items.filter(function (it) { return it.ok; }).length + ' από ' + ck.items.length
				: ((c.files || []).length ? (c.files.length === 1 ? '1 έγγραφο' : c.files.length + ' έγγραφα') : 'κανένα έγγραφο'),
			c.signed_at ? signStamp(c.signed_at).slice(0, 10)
				: sg ? sg.collected.length + ' από ' + sg.required.length
				: (c.status === 'awaiting_signature' ? 'στάλθηκε' : ''),
			'',
			''
		];
		var marks = { ok: '✓ ', now: '● ', warn: '! ', stop: '✕ ', next: '' };
		var stageHTML = '<ol class="sig-stage">' + stages.map(function (st, i) {
			var sub = subs[i];
			if (st.state === 'now' || st.state === 'warn' || st.state === 'stop') {
				if (i >= 3 || st.state !== 'now') sub = statuses[c.status] || c.status;
			}
			if (i === 3 && st.state === 'ok' && c.status !== 'active') sub = statuses[c.status] || c.status;
			return '<li class="sig-stage__s is-' + esc(st.state) + '"><b>' + marks[st.state] + esc(st.label) + '</b><small>' + (sub ? esc(sub) : ' ') + '</small></li>';
		}).join('') + '</ol>';

		var sub = ['#' + (c.code || c.id), [c.provider_name, c.program_name].filter(Boolean).join(' '),
			c.created_at ? 'δημιουργήθηκε ' + fmtDate(c.created_at) : ''].filter(Boolean).join(' · ');

		var mobile = c.energy_type === 'mobile';
		var who = fact('ΑΦΜ', c.afm) + fact('ΔΟΥ', c.doy) + fact('ΑΔΤ', c.adt) +
			fact('Κινητό', c.mobile) + fact('Τηλέφωνο', c.phone) + fact('Email', c.email, true) +
			fact('Πατρώνυμο', c.father_name) + fact('Ημ. γέννησης', c.birth_date);
		var where = fact('Διεύθυνση', [c.street, c.street_no].filter(Boolean).join(' ')) +
			fact('Πόλη / ΤΚ', [c.city, c.postal_code].filter(Boolean).join(' · ')) + fact('Νομός', c.region) +
			(mobile ? '' : fact('Αρ. παροχής', c.supply_number) + fact('Μετρητής', c.meter_number) + fact('Τιμολόγιο', c.invoice_code)) +
			fact('Πάροχος', c.provider_name) + fact('Πρόγραμμα', c.program_name) + fact('Αριθμός αίτησης', c.provider_ref) +
			fact('Είδος', energy) + fact('Ενεργοποίηση', acts[c.activation_type] || c.activation_type);

		return '<div class="sig-detail">' +
			'<button type="button" class="sig-detail__back" data-go="contracts"><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg> Αιτήσεις</button>' +
			'<header class="sig-dh">' +
				'<span class="sig-dh__av" style="--h:' + tint(name) + '">' + esc(initials(name)) + '</span>' +
				'<div class="sig-dh__who"><h2>' + esc(name) + '</h2><small>' + esc(sub) +
					(c.consent_at ? ' <span class="sig-dh__gdpr" title="Συναίνεση: ' + esc(fmtDate(c.consent_at)) + '">✓ GDPR</span>' : '') + '</small></div>' +
				'<div class="sig-dh__acts">' + signBtnHTML + editBtnHTML + companionSlot +
					'<details class="sig-dh__more" data-dh-more><summary aria-label="Περισσότερες ενέργειες">⋯</summary>' +
					'<div class="sig-dh__menu">' + printBtnHTML + provBtnHTML + trackBtnHTML + taskBtnHTML + delBtnHTML + '</div></details>' +
				'</div>' +
			'</header>' +
			stageHTML +
			'<div class="sig-dgrid"><div class="sig-dmain">' +
				'<section class="ecrm-card sig-dcard"><h3 class="sig-ch">Στοιχεία πελάτη</h3><div class="sig-facts">' + who + '</div>' +
				'<h3 class="sig-ch sig-ch--prov"><span>' + (mobile ? 'Διεύθυνση και πρόγραμμα' : 'Παροχή και πρόγραμμα') + '</span>' +
					(c.provider_name ? providerMark(c.provider_name, c.provider_logo) : '') + '</h3><div class="sig-facts">' + where + '</div>' +
				(c.notes ? '<div class="sig-notes"><small>Σχόλια</small>' + esc(c.notes) + '</div>' : '') +
				'</section>' +
				filesCard(c) +
				'<section class="ecrm-card sig-dcard"><h3 class="sig-ch">Αλλαγή κατάστασης</h3>' + statusFlow + '</section>' +
			'</div><aside class="sig-dside">' +
				stuckCard(c) +
				auditCard +
				'<section class="ecrm-card ecrm-rcard sig-dcard' + (done === checks.length ? ' is-ok' : '') + '">' +
				'<h3 class="sig-ch">Checklist <b>' + done + '/' + checks.length + '</b></h3>' + checklistHTML + '</section>' +
				'<div data-remarks-wrap>' + remarksCard(c) + '</div>' +
				'<section class="ecrm-card sig-dcard"><h3 class="sig-ch">Πορεία</h3>' + timeline + '</section>' +
			'</aside></div></div>';
	}

	if (isSignature()) {
		view.innerHTML = sigDetailHTML();
	} else view.innerHTML = '' +
		'<div class="ecrm-detail2"><div class="ecrm-detail2__main">' +

		'<div class="ecrm-dhead">' +
		'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-go="contracts"><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M19 12H5M11 6l-6 6 6 6"/></svg> Πίσω</button>' +
		'<div class="ecrm-dhead__who"><h2 class="ecrm-dhead__name">' + esc(name) + '</h2>' +
		'<div class="ecrm-dhead__sub">' + esc(c.code || '') + (c.created_at ? ' · δημιουργία ' + esc(fmtDate(c.created_at)) : '') + '</div></div>' +
		'<div class="ecrm-dhead__acts">' +
		printBtnHTML +
		editBtnHTML +
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
		// Ο τίτλος «Παροχή» δεν έχει νόημα για κινητή — δεν υπάρχει παροχή,
		// μόνο διεύθυνση πελάτη. 2026-08-24, μαζί με την απόκρυψη των τριών
		// πεδίων παρακάτω: ο τίτλος τους ακολουθεί, όχι μόνο τα ίδια.
		'<div class="ecrm-card"><div class="ecrm-step">' +
		(c.energy_type === 'mobile' ? 'Διεύθυνση' : 'Παροχή / διεύθυνση') + '</div>' +
		// Αρ. παροχής/Μετρητής/Τιμολόγιο κρύβονται για mobile, 2026-08-24: καμία
		// φόρμα Orizon δεν τυπώνει supply_number/meter_number/invoice_code (το
		// COMBO έχει δικό του ξεχωριστό combo_supply_number/combo_arithmos_paroxis,
		// άσχετο πεδίο) — τρεις γραμμές πάντα «—» σε κάθε αίτηση κινητής.
		( c.energy_type !== 'mobile'
			? kv('Αρ. παροχής', c.supply_number) + kv('Μετρητής', c.meter_number) + kv('Τιμολόγιο', c.invoice_code)
			: '' ) +
		kv('Διεύθυνση', [c.street, c.street_no].filter(Boolean).join(' ')) +
		kv('Πόλη / ΤΚ', [c.city, c.postal_code].filter(Boolean).join(' · ')) + kv('Νομός', c.region) +
		kv('Πάροχος', c.provider_name) + kv('Πρόγραμμα', c.program_name) +
		// (349) Η αριθμοσειρά του εντύπου του παρόχου, όπως τη γράφει ο agent.
		kv('Αριθμός αίτησης', c.provider_ref) + kv('Είδος', energy) +
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
		stuckCard(c) +
		auditCard +
		'<div class="ecrm-card ecrm-rcard' + (done === checks.length ? ' is-ok' : '') + '">' +
		'<div class="ecrm-step">Checklist &nbsp;<b>' + done + '/' + checks.length + '</b></div>' + checklistHTML + '</div>' +
		// (348) Παρατηρήσεις: στο rail, κάτω από το Checklist (ιδιοκτήτης 03/11).
		'<div data-remarks-wrap>' + remarksCard(c) + '</div>' +
		'<div class="ecrm-drail__acts">' +
		// Μόνο σε καταστάσεις απ' όπου το pipeline όντως επιτρέπει μετάβαση σε
		// pending_signature (ContractStatus::allowedNext(), src/Domain/Contract/
		// ContractStatus.php) — μέχρι το 2026-08-24 το κουμπί έμενε πάντα
		// πράσινο/ενεργό και καλούσε τον πράκτορα σε ενέργεια που θα
		// απορριπτόταν με 409 χωρίς προειδοποίηση.
		//
		// Τα στάδια ΜΕΤΑ την υπογραφή ('awaiting_sim', 'finalisation') είναι
		// μέσα σκόπιμα: από εκεί περνά η δεύτερη υπογραφή — ο πάροχος γυρίζει
		// πίσω την αίτηση ζητώντας νέα, ή βρέθηκε λάθος αμέσως μετά. Δεν
		// στέλνει σιωπηλά: ο SignLinkController απαντά needs_confirm και ο
		// χρήστης επιβεβαιώνει πρώτα ότι σβήνει την παλιά υπογραφή.
		// ΑΦΟΡΑ ΟΛΟΥΣ ΤΟΥΣ ΠΑΡΟΧΟΥΣ — καμία σχέση με energy_type/Orizon.
		// 05/10/2026: όλες οι ενδιάμεσες πάνε πλέον ελεύθερα σε Αναμονή
		// υπογραφής, οπότε το κουμπί κρύβεται μόνο σε Ενεργό και Ακυρώθηκε.
		signBtnHTML + companionSlot + provBtnHTML + trackBtnHTML + taskBtnHTML + delBtnHTML +
		'</div></aside></div>';


	bindRemarks(view, c);

	// (353h) Στο κινητό η λωρίδα σταθμών κυλά οριζόντια: ξεκινά από εκεί που βρίσκεται η αίτηση.
	var stageNow = view.querySelector('.sig-stage .is-now, .sig-stage .is-warn, .sig-stage .is-stop');
	if (stageNow && stageNow.parentNode.scrollWidth > stageNow.parentNode.clientWidth) {
		stageNow.parentNode.scrollLeft = Math.max(0, stageNow.offsetLeft - stageNow.parentNode.offsetLeft - 14);
	}

	// (353h) Το «⋯» της Signature κλείνει μόλις διαλεχτεί κάτι (το έξω-κλικ: πιο κάτω, μία φορά).
	var dhMore = view.querySelector('[data-dh-more]');
	if (dhMore) dhMore.addEventListener('click', function (e) {
		if (e.target.closest && e.target.closest('.sig-dh__menu button')) { dhMore.open = false; }
	});

	view.querySelectorAll('.ecrm-statuschip').forEach(function (b) {
		b.addEventListener('click', function () {
			var to = this.getAttribute('data-status');
			if (to === c.status) return;
			// (342) Απόρριψη/ακύρωση: πρώτα ο λόγος. Άκυρο = καμία αλλαγή.
			// Πρόχειρο που πετιέται: χωρίς λόγο (CloseReason::required()).
			(c.status === 'draft' ? Promise.resolve({ reason: '', note: '' }) : pickReason(to, statuses[to] || to, 1)).then(function (picked) {
				if (!picked) return;
				fetch(api('/contracts/' + c.id + '/status'), { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H()), body: JSON.stringify({ status: to, reason: picked.reason, message: picked.note }) })
					.then(function (r) { return r.json(); })
					.then(function (res) { if (res && res.ok) { toast('Κατάσταση: ' + (statuses[to] || to)); openDetail(c.id); } else { toast((res && res.error) || 'Αποτυχία αλλαγής.', false); } })
					.catch(function () { toast('Σφάλμα δικτύου.', false); });
			});
		});
	});

	fillCompanion(view, c);

	var dEdit = view.querySelector('[data-detail-edit]');
	if (dEdit) dEdit.addEventListener('click', function () { openEdit(c); });

	var delBtn = view.querySelector('[data-detail-del]');
	if (delBtn) delBtn.addEventListener('click', function () {
		var b = this;

		function doDelete() {
			b.disabled = true; var t = b.textContent; b.textContent = 'Διαγραφή…';
			fetch(api('/contracts/' + c.id), { method: 'DELETE', headers: H() })
				.then(function (r) { return r.text().then(function (x) { try { return JSON.parse(x); } catch (e) { throw new Error('Ο server δεν απάντησε σωστά.'); } }); })
				.then(function (d2) {
					if (d2 && d2.ok) { toast('Η αίτηση διαγράφηκε.', true); go('contracts'); }
					// build queue #15: ο server αρνήθηκε γιατί υπογράφηκε
					// ποτέ (code === 'was_signed'). Μόνο admin βλέπει τη
					// δεύτερη πύλη -- ο έλεγχος εδώ είναι απλά για το αν θα
					// την ΠΡΟΣΦΕΡΕΙ το UI, ξαναελέγχεται στον server.
					else if (d2 && d2.code === 'was_signed' && window.ECRM && window.ECRM.isAdmin) {
						b.disabled = false; b.textContent = t;
						offerForceDelete(c, b);
					}
					else { b.disabled = false; b.textContent = t; toast((d2 && d2.error) || 'Αποτυχία διαγραφής.', false); }
				})
				.catch(function (err) { b.disabled = false; b.textContent = t; toast((err && err.message) || 'Σφάλμα δικτύου.', false); });
		}

		// build queue #15: η «ειδική πύλη» admin. Ανοίγει ΜΟΝΟ αφού η
		// κανονική διαγραφή αρνήθηκε με 'was_signed' -- δεν είναι μόνιμα
		// ορατό κουμπί, ώστε η ύπαρξή της να μη διδάσκει κανέναν να την
		// προσπερνά. Ίδιος κωδικός αίτησης με το confirmTyped() παραπάνω,
		// ΣΥΝ υποχρεωτική αιτιολογία -- δες confirmTypedWithReason().
		function offerForceDelete(c, b) {
			var hasCode = !!c.code;
			confirmTypedWithReason({
				eyebrow: 'Παράκαμψη admin',
				expect: hasCode ? String(c.code) : String(c.id),
				expectLabel: hasCode ? 'Πληκτρολόγησε τον κωδικό της αίτησης' : 'Πληκτρολόγησε τον αριθμό της αίτησης',
				reasonLabel: 'Γιατί χρειάζεται να διαγραφεί αυτή η υπογεγραμμένη σύμβαση;',
				title: 'Οριστική διαγραφή υπογεγραμμένης — ' + (c.code || ('#' + c.id)),
				lead: ['Αυτή η σύμβαση ', { b: 'υπογράφηκε' }, '. Η κανονική διαγραφή την αρνείται εσκεμμένα. Ως admin μπορείς να προχωρήσεις, αλλά η ενέργεια είναι ', { b: 'οριστική' }, ' και καταγράφεται μόνιμα ποιος, πότε και γιατί.'],
				confirm: 'Οριστική διαγραφή (admin)',
				onConfirm: function (reason) { doForceDelete(c, b, reason); },
			});
		}

		function doForceDelete(c, b, reason) {
			b.disabled = true; var t = b.textContent; b.textContent = 'Διαγραφή…';
			fetch(api('/contracts/' + c.id + '/force'), {
				method: 'DELETE',
				headers: Object.assign({ 'Content-Type': 'application/json' }, H()),
				body: JSON.stringify({ reason: reason }),
			})
				.then(function (r) { return r.text().then(function (x) { try { return JSON.parse(x); } catch (e) { throw new Error('Ο server δεν απάντησε σωστά.'); } }); })
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
	/* Η παράδοση στη Λίτσα: ανοίγει το πάνελ με την ερώτηση ΗΔΗ γραμμένη.
	 *
	 * Δεν στέλνεται μόνη της. Ο συνεργάτης βλέπει τι θα ρωτηθεί και μπορεί να
	 * το αλλάξει — μια ερώτηση που φεύγει χωρίς να τη δει είναι απάντηση σε
	 * κάτι που δεν ρώτησε.
	 *
	 * Το `toggle` πατιέται ΜΟΝΟ αν το πάνελ είναι κλειστό: το ίδιο κουμπί
	 * κλείνει και ανοίγει, οπότε τυφλό κλικ σε ανοιχτό πάνελ θα το έκλεινε.
	 */
	var askBtn = view.querySelector('[data-ask-litsa]');
	if (askBtn) askBtn.addEventListener('click', function () {
		var panel = document.getElementById('ecrm-litsa');
		if (!panel) { toast('Ο AIde δεν είναι διαθέσιμος εδώ.', false); return; }

		var input = panel.querySelector('[data-litsa-input]');
		var opener = panel.querySelector('[data-litsa-toggle]');

		if (panel.getAttribute('data-open') !== '1' && opener) { opener.click(); }

		if (input) {
			input.value = 'Η αίτηση ' + (c.code || ('#' + c.id)) + ' κάθεται ' +
				(c.stuck ? c.stuck.days : '') + ' μέρες σε «' + (statuses[c.status] || c.status) +
				'». Τι μπορώ να κάνω;';
			setTimeout(function () { input.focus(); }, 60);
		}
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

	/* Η αποστολή για υπογραφή ρωτάει ΠΟΙΟ κανάλι — B4, 21/08.
	 *
	 * Μέχρι σήμερα έστελνε σκληρά `{ email: true }` και δεν ρωτούσε τίποτα, ενώ
	 * το ECRM_Messaging έστελνε Viber-με-πτώση-σε-SMS και είχε ήδη γραμμένο
	 * πρότυπο για αυτή ακριβώς τη στιγμή, με τον σύνδεσμο μέσα. Το πιο επείγον
	 * μήνυμα του προϊόντος ήταν το μόνο που δεν πήγαινε εκεί που ο πελάτης
	 * κοιτάει.
	 *
	 * Τι μπορεί να δουλέψει το λέει ο SERVER (`c.comms`), όχι αυτό το αρχείο: το
	 * αν έχει ρυθμιστεί πάροχος είναι κατάσταση του server, και ένας διάλογος
	 * που προσφέρει Viber χωρίς πάροχο υπόσχεται σιωπηλή αποτυχία.
	 */

	var CHANNELS = [
		{ key: 'sms',   title: 'Viber / SMS', note: 'Πτώση σε SMS αν δεν έχει Viber. Ο σύνδεσμος μπαίνει μέσα στο μήνυμα.', at: function (x) { return x.mobile || x.phone || ''; } },
		{ key: 'email', title: 'Email',       note: 'Το μήνυμα με τον σύνδεσμο υπογραφής.',                                 at: function (x) { return x.email || ''; } },
		{ key: 'link',  title: 'Μόνο σύνδεσμος', note: 'Τον στέλνεις εσύ — WhatsApp, Messenger, από κοντά.',       at: function () { return ''; } }
	];

	var WHY = {
		no_provider: 'Δεν έχει ρυθμιστεί πάροχος — Ρυθμίσεις → Μηνύματα',
		no_mobile:   'Ο πελάτης δεν έχει κινητό καταχωρημένο',
		no_email:    'Ο πελάτης δεν έχει email καταχωρημένο',
		// 3β-Γ: ίδιος λόγος με το SignLinkController::reason() στο PHP --
		// ένα σημείο κειμένου θα ήταν καλύτερο, αλλά αυτό το αρχείο δεν έχει
		// σήμερα καμία διαδρομή να διαβάσει PHP σταθερές· ίδιο κείμενο,
		// αντιγραμμένο ρητά, όχι εφευρημένο.
		sms_energy_unsupported: 'Δεν υποστηρίζεται ακόμα για τον πελάτη ενέργειας — χρησιμοποίησε Email ή Σύνδεσμο'
	};

	/* Η μνήμη του διαλόγου: τι έφυγε την προηγούμενη φορά.
	 *
	 * Διαβάζεται από τα ΓΕΓΟΝΟΤΑ που ήδη κατεβαίνουν με τη σύμβαση — καμία
	 * επιπλέον κλήση. Τα `sign_*` είναι ειδικά για την υπογραφή· ένα σκέτο `sms`
	 * μπορεί να είναι το αυτόματο μήνυμα της «ενεργοποιήθηκε» και ΔΕΝ μετράει
	 * εδώ, αλλιώς ο διάλογος θα έλεγε ψέματα για το τι δοκιμάστηκε.
	 */
	function lastSend() {
		var list = c.events || [];
		for (var i = 0; i < list.length; i++) {
			var m = /^sign_(sent|failed)_(sms|email|link)$/.exec(list[i].type || '');
			if (m) { return { ok: m[1] === 'sent', channel: m[2], at: list[i].created_at }; }
		}
		return null;
	}

	var signBtn = view.querySelector('[data-sign]');
	if (signBtn) signBtn.addEventListener('click', function () {
		var b = this;
		var prev = lastSend();

		// 3β-Γ, 04/09: COMBO με δύο πρόσωπα δείχνει επιλογέα ρόλου -- κάθε
		// άλλη αίτηση (η συντριπτική πλειοψηφία) δεν βλέπει καμία από τις
		// γραμμές παρακάτω, `role` μένει πάντα 'mobile' και η συμπεριφορά
		// είναι byte-for-byte ίδια με πριν. Δες docs/UI-COMBO-SIGN-STATUS.html.
		var twoRoles = c.signatures && c.signatures.required && c.signatures.required.length > 1;
		var mobileName = c.company_name || ((c.first_name || '') + ' ' + (c.last_name || '')).trim() || 'Πελάτης κινητής';
		var energyName = (c.extra && c.extra.combo_energy_name) || 'Πελάτης ενέργειας';
		var ROLES = twoRoles ? [
			{ role: 'mobile', label: 'Πελάτης κινητής', who: mobileName, comms: c.comms || { sms: { ok: false }, email: { ok: false }, link: { ok: true } } },
			{ role: 'energy', label: 'Πελάτης ενέργειας', who: energyName, comms: c.comms_energy || { sms: { ok: false, why: 'sms_energy_unsupported' }, email: { ok: false }, link: { ok: true } } }
		] : [
			{ role: 'mobile', label: '', who: '', comms: c.comms || { sms: { ok: false }, email: { ok: false }, link: { ok: true } } }
		];

		// Προεπιλογή ρόλος: αυτός που ΛΕΙΠΕΙ ακόμα. Αν λείπουν και οι δύο ή
		// έχουν υπογράψει και οι δύο, ο πελάτης κινητής -- απόφαση 04/09.
		var collected = (c.signatures && c.signatures.collected) || [];
		var role = 'mobile';
		if (twoRoles) {
			var missing = ROLES.filter(function (r) { return collected.indexOf(r.role) === -1; });
			role = missing.length === 1 ? missing[0].role : 'mobile';
		}

		function roleOf(key) { return ROLES.filter(function (r) { return r.role === key; })[0]; }

		/* Προεπιλογή κανάλι: το καλύτερο διαθέσιμο ΓΙΑ ΤΟΝ ΕΠΙΛΕΓΜΕΝΟ ρόλο —
		   αλλά ΟΧΙ αυτό που μόλις δοκιμάστηκε. Αν το Viber δεν έπιασε δύο
		   ώρες, το δεύτερο Viber δεν είναι η απάντηση. */
		var order = ['sms', 'email', 'link'];
		function chooseChannel(comms) {
			var chosen = '';
			order.forEach(function (k) {
				if (chosen) { return; }
				if (!(comms[k] && comms[k].ok)) { return; }
				if (prev && prev.channel === k && order.some(function (o) { return o !== k && comms[o] && comms[o].ok; })) { return; }
				chosen = k;
			});
			return chosen || 'link';
		}

		function channelRows(comms, chosen) {
			return CHANNELS.map(function (ch) {
				var st = comms[ch.key] || { ok: false };
				var at = ch.at(c);
				var sub = st.ok
					? '<div class="ecrm-chan__s">' + (at ? esc(at) + ' · ' : '') + esc(ch.note) + '</div>'
					: '<div class="ecrm-chan__s ecrm-chan__s--why">' + esc(WHY[st.why] || 'Δεν είναι διαθέσιμο') + '</div>';
				return '<button type="button" class="ecrm-chan' + (st.ok ? '' : ' is-off') + (chosen === ch.key ? ' is-on' : '') + '"' +
					(st.ok ? '' : ' disabled') + ' data-chan="' + esc(ch.key) + '">' +
					'<span class="ecrm-chan__dot" aria-hidden="true"></span>' +
					'<span class="ecrm-chan__b"><span class="ecrm-chan__t">' + esc(ch.title) + '</span>' + sub + '</span></button>';
			}).join('');
		}

		var rows = channelRows(roleOf(role).comms, chooseChannel(roleOf(role).comms));

		var whopick = '';
		if (twoRoles) {
			whopick = '<p class="ecrm-whopick__lbl">Σε ποιον από τους δύο;</p><div class="ecrm-whopick">' +
				ROLES.map(function (r) {
					var signed = collected.indexOf(r.role) !== -1;
					return '<button type="button" class="ecrm-whopick__b' + (role === r.role ? ' is-on' : '') + '" data-role="' + esc(r.role) + '">' +
						'<span class="ecrm-whopick__t">' + esc(r.label) + '</span>' +
						'<span class="ecrm-whopick__s ' + (signed ? 'ecrm-whopick__s--ok' : 'ecrm-whopick__s--wait') + '">' + (signed ? '✓ υπέγραψε' : 'λείπει') + '</span>' +
						'</button>';
				}).join('') + '</div>';
		}

		/* Η λήξη τη ΛΕΕΙ Ο SERVER (`c.sign_expired`), δεν υπολογίζεται εδώ.
		   Το `created_at` είναι ώρα βάσης και το ρολόι του browser είναι ώρα
		   συσκευής· ένας υπολογισμός εδώ θα έλεγε «έληξε» ενώ ο server θα δεχόταν
		   ακόμη υπογραφή. Το timeAgo() μένει για το «πριν 2 ώρες», που είναι
		   περιγραφή και όχι ισχυρισμός. */
		var expired = !!c.sign_expired;

		var memory = prev
			? '<div class="ecrm-chan-memo' + (prev.ok && !expired ? '' : ' is-bad') + '">' +
				esc(expired
					? 'Ο σύνδεσμος υπογραφής έληξε'
					: (prev.ok ? 'Στάλθηκε ' : 'Απέτυχε ') + ({ sms: 'με Viber/SMS', email: 'με email', link: 'ως σύνδεσμος' }[prev.channel] || '')) +
				' · ' + esc(timeAgo(prev.at)) +
				(expired && c.sign_window_hours ? '<span class="ecrm-chan-memo__hint">Το «Ξαναστείλε σύνδεσμο» ανοίγει ξανά το παράθυρο των ' + esc(String(c.sign_window_hours)) + ' ωρών.</span>' : '') + '</div>'
			: '';

		// Ο σύνδεσμος μπαίνει ΜΟΝΟ αν υπάρχει το κουμπί που θα πατήσει. Σήμερα
		// υπάρχει πάντα· αν αύριο γίνει υπό συνθήκη, η εναλλακτική είναι ένας
		// σύνδεσμος που δεν κάνει τίποτα και δεν λέει γιατί.
		var hasForm = !!view.querySelector('[data-printform]');

		var body = memory + whopick +
			'<div class="ecrm-chan-list" data-chan-wrap>' + rows + '</div>' +
			(hasForm ? '<button type="button" class="ecrm-chan-doc" data-see-form>Δες το έντυπο παρόχου πριν στείλεις</button>' : '');

		var dlg = openDialog({
			eyebrow: 'Πώς να το στείλεις',
			title: c.code || ('#' + c.id),
			lead: [c.company_name || ((c.first_name || '') + ' ' + (c.last_name || '')).trim() || 'Ο πελάτης'],
			body: body,
			confirm: prev ? 'Ξαναστείλε σύνδεσμο' : 'Στείλε',
			onConfirm: function (el, close, go2) {
				var pick = el.querySelector('.ecrm-chan.is-on');
				if (!pick) { return; }
				var channel = pick.getAttribute('data-chan');
				var pickedRole = el.querySelector('.ecrm-whopick__b.is-on');
				var sendRole = pickedRole ? pickedRole.getAttribute('data-role') : role;

				go2.disabled = true;
				b.disabled = true;

				// confirmResend: true ΜΟΝΟ στη δεύτερη κλήση, αφού ο χρήστης
				// έχει ήδη πει «ναι» στο window.confirm() παρακάτω. Ο server
				// αρνείται σκόπιμα την πρώτη κλήση για μια ήδη υπογεγραμμένη
				// αίτηση (needs_confirm: true) — δες SignLinkController::create().
				function send(confirmResend) {
					var payload = { channel: channel };
					// role μπαίνει ΜΟΝΟ όταν χρειάζονται δύο υπογραφές -- σε
					// κάθε άλλη αίτηση ο server προεπιλέγει MOBILE μόνος του,
					// ίδια συμπεριφορά με πριν το 3β-Γ.
					if (twoRoles) { payload.role = sendRole; }
					if (confirmResend) { payload.confirm_resend = true; }

					fetch(api('/contracts/' + c.id + '/sign-link'), {
						method: 'POST',
						headers: Object.assign({ 'Content-Type': 'application/json' }, H()),
						body: JSON.stringify(payload),
					})
						.then(function (r) { return r.json(); })
						.then(function (d) {
							if (!d || !d.ok) {
								if (d && d.needs_confirm && d.reason === 'already_signed' && !confirmResend) {
									if (window.confirm((d.error || 'Η αίτηση έχει ήδη υπογραφεί.') + ' Θέλεις να την ξαναστείλεις για υπογραφή;')) {
										send(true);
										return; // Το κουμπί μένει disabled — τρέχει ήδη το δεύτερο αίτημα.
									}
								}
								go2.disabled = false;
								b.disabled = false;
								toast((d && d.error) || 'Αποτυχία.', false);
								return;
							}
							close();
							b.disabled = false;
							// Ο σύνδεσμος αντιγράφεται ΠΑΝΤΑ, όποιο κανάλι κι αν
							// επιλέχθηκε: αν το Viber αποτύχει, ο συνεργάτης έχει ήδη
							// στο πρόχειρο αυτό που χρειάζεται για να το σώσει.
							copyText(d.url).then(function (copied) {
								toast(deliveryNote(d, copied), d.delivered !== false);
								openDetail(c.id);
							});
						})
						.catch(function () { go2.disabled = false; b.disabled = false; toast('Σφάλμα δικτύου.', false); });
				}

				send(false);
			},
		});

		function wireChanClicks() {
			dlg.el.querySelectorAll('.ecrm-chan').forEach(function (btn) {
				btn.addEventListener('click', function () {
					if (btn.disabled) { return; }
					dlg.el.querySelectorAll('.ecrm-chan').forEach(function (x) { x.classList.remove('is-on'); });
					btn.classList.add('is-on');
				});
			});
		}
		wireChanClicks();

		// Αλλαγή προσώπου: ξαναχτίζει ΜΟΝΟ τα κανάλια, με τα δικά του στοιχεία
		// επικοινωνίας -- ο επιλογέας προσώπου μένει όπως είναι.
		dlg.el.querySelectorAll('.ecrm-whopick__b').forEach(function (btn) {
			btn.addEventListener('click', function () {
				dlg.el.querySelectorAll('.ecrm-whopick__b').forEach(function (x) { x.classList.remove('is-on'); });
				btn.classList.add('is-on');
				var r = roleOf(btn.getAttribute('data-role'));
				var wrap = dlg.el.querySelector('[data-chan-wrap]');
				wrap.innerHTML = channelRows(r.comms, chooseChannel(r.comms));
				wireChanClicks();
			});
		});

		var see = dlg.el.querySelector('[data-see-form]');
		if (see) see.addEventListener('click', function () {
			var p = view.querySelector('[data-printform]');
			if (p) { p.click(); }
		});
	});

	/* Τι λέει το toast — και γιατί λέει και τις αποτυχίες.
	   Η σύμβαση ΕΧΕΙ μετακινηθεί σε «αναμονή υπογραφής» ακόμη κι όταν το κανάλι
	   απέτυχε: ο σύνδεσμος δουλεύει και ο συνεργάτης μπορεί να τον στείλει με το
	   χέρι. Αυτό που δεν επιτρέπεται είναι να μην το ΜΑΘΕΙ. */
	function deliveryNote(d, copied) {
		var tail = copied ? ' Ο σύνδεσμος αντιγράφηκε.' : '';

		if (d.channel === 'link') { return 'Έτοιμο για αποστολή.' + tail; }

		if (d.delivered) {
			return (d.channel === 'sms' ? 'Στάλθηκε Viber/SMS στον πελάτη.' : 'Στάλθηκε email στον πελάτη.') + tail;
		}

		return 'ΔΕΝ στάλθηκε — στείλ\' τον σύνδεσμο με το χέρι.' + tail;
	}

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

	var docupWrap = view.querySelector('[data-docup]');
	var docupRows = docupWrap ? docupWrap.querySelector('[data-docup-rows]') : null;
	var docupFile = docupWrap ? docupWrap.querySelector('[data-docup-file]') : null;

	function expirableKinds() {
		if (!docupWrap) return [];
		try { return JSON.parse(docupWrap.getAttribute('data-docup-expirable') || '[]'); } catch (e) { return []; }
	}

	/* Μία γραμμή ανά επιλεγμένο αρχείο: όνομα, είδος, και λήξη όπου έχει νόημα.
	 *
	 * Το πεδίο λήξης φαίνεται μόνο για είδη που πραγματικά λήγουν (σήμερα:
	 * ταυτότητα/διαβατήριο) — η λίστα έρχεται από τον server
	 * (ECRM_Docs::expirable_kinds()), δεν είναι κωδικοποιημένη εδώ. Πριν ήταν
	 * ένα πεδίο για όλο το widget· τώρα ακολουθεί τη γραμμή του, γιατί δύο
	 * αρχεία του ίδιου batch μπορεί να θέλουν διαφορετική απάντηση. */
	/* Μαντεύει το είδος από το όνομα αρχείου -- ίδιο μοτίβο με το guessKind() της δημόσιας φόρμας
	 * (ecrm-form.js). Ασφαλής προεπιλογή "other": ένα λάθος-σίγουρο "Ταυτότητα" σε έγγραφο
	 * που δεν είναι κρύβει το πραγματικό πρόβλημα (λείπει ταυτότητα) πίσω από ψεύτικο
	 * "υπάρχει". Δεν υπάρχει δικό είδος "υπογραφή" στον κατάλογο (ECRM_Docs::kinds()) -- η υπογραφή της σύμβασης
	 * ζει στο ContractDocuments, όχι εδώ, οπότε ένα signature.png που έρχεται χειροκίνητα πέφτει στο "Άλλο", όχι στο
	 * "Ταυτότητα" που ήταν το πρώτο kind του καταλόγου. */
	function guessDocKind(name, kindLabel) {
		var n = (name || '').toLowerCase();
		var guesses = [
			['provider_bill', /(λογαριασμ|bill|invoice|παροχ)/],
			['id_card', /(ταυτοτητ|passport|διαβατ)/],
			['sim_card', /(sim[-_]?card|καρτα ?sim)/],
			['iban', /(iban)/],
			['gemi', /(γεμη|καταστατικ)/],
			['death_cert', /(θανατ)/],
			['heir_cert', /(κληρονομ|συγγεν)/],
			['residence', /(κατοικ|residence)/],
			['authorization', /(εξουσιοδοτ|authoriz)/],
		];
		for (var i = 0; i < guesses.length; i++) {
			if (guesses[i][1].test(n) && kindLabel[guesses[i][0]]) return guesses[i][0];
		}
		return kindLabel.other ? 'other' : Object.keys(kindLabel)[0];
	}

	function renderDocupRows() {
		if (!docupRows || !docupFile) return;
		var kindLabel = {};
		try { kindLabel = JSON.parse(docupWrap.getAttribute('data-docup-kinds') || '{}'); } catch (e) { kindLabel = {}; }
		var files = docupFile.files || [];
		var out = '';

		for (var i = 0; i < files.length; i++) {
			var name = files[i].name || 'αρχείο';
			var ext = (name.split('.').pop() || '').toUpperCase().slice(0, 4);
			var guessed = guessDocKind(name, kindLabel);
			var rowKindOpts = Object.keys(kindLabel).map(function (k) {
				return '<option value="' + esc(k) + '"' + (k === guessed ? ' selected' : '') + '>' + esc(kindLabel[k]) + '</option>';
			}).join('');
			out += '<div class="ecrm-docup__row" data-docup-row="' + i + '">' +
				'<span class="ecrm-docup__ext">' + esc(ext) + '</span>' +
				'<span class="ecrm-docup__name" title="' + esc(name) + '">' + esc(name) + '</span>' +
				'<select class="ecrm-input ecrm-docup__kind" data-docup-kind>' + rowKindOpts + '</select>' +
				'<input type="date" class="ecrm-input ecrm-docup__expiry" data-docup-expiry title="Ημερομηνία λήξης (αν υπάρχει πάνω στο έγγραφο)" hidden>' +
				'</div>';
		}

		docupRows.innerHTML = out;
		var rows = docupRows.querySelectorAll('[data-docup-row]');
		for (var r = 0; r < rows.length; r++) { syncRowExpiry(rows[r]); bindRowExpiry(rows[r]); }

		// (340) Έλεγχος φωτογραφίας: εδώ μόνο προειδοποίηση κάτω από τη γραμμή
		// -- ο πωλητής διαλέγει άλλη εικόνα ή ανεβάζει όπως είναι.
		Array.prototype.forEach.call(files, function (file, n) {
			checkPhoto(file).then(function (res) {
				var row = docupRows.querySelector('[data-docup-row="' + n + '"]');
				if (!row || !res.problems.length || docupFile.files[n] !== file) return;
				var note = document.createElement('div');
				note.className = 'ecrm-docup__qa';
				note.textContent = res.problems.map(function (p) { return p.text; }).join(' ');
				row.appendChild(note);
			});
		});
	}

	function syncRowExpiry(row) {
		var sel = row.querySelector('[data-docup-kind]');
		var exp = row.querySelector('[data-docup-expiry]');
		if (!sel || !exp) return;
		var show = expirableKinds().indexOf(sel.value) !== -1;
		exp.hidden = !show;
		if (!show) exp.value = '';
	}

	function bindRowExpiry(row) {
		var sel = row.querySelector('[data-docup-kind]');
		if (sel) sel.addEventListener('change', function () { syncRowExpiry(row); });
	}

	if (docupFile) docupFile.addEventListener('change', renderDocupRows);

	/* Η ανάγνωση των εγγράφων, αφού έχουν ήδη μπει.
	 *
	 * Ξεχωριστή κλήση από το ανέβασμα, με πρόθεση: τα αρχεία είναι ήδη σωσμένα
	 * όταν ξεκινά, οπότε αν αργήσει, αποτύχει ή δεν απαντήσει ποτέ, το μόνο που
	 * χάνεται είναι η διόρθωση της ετικέτας — ποτέ το ίδιο το ανέβασμα. */
	function reviewKinds(silent) {
		var msg = docupWrap ? docupWrap.querySelector('[data-docup-msg]') : null;
		if (msg && !silent) { msg.textContent = 'Η AI διαβάζει τα έγγραφα…'; msg.className = 'ecrm-docup__msg'; }

		return fetch(api('/contracts/' + c.id + '/files/review'), { method: 'POST', headers: H() })
			.then(function (r) { return r.json(); })
			.then(function (d) {
				if (!d || !d.ok || !d.fixed || !d.fixed.length) return false;
				var first = d.fixed[0];
				toast(d.fixed.length === 1
					? 'Η AI διόρθωσε ένα έγγραφο σε «' + first.to_label + '».'
					: 'Η AI διόρθωσε ' + d.fixed.length + ' έγγραφα.');
				return true;
			})
			.catch(function () { return false; });
	}

	/* Παλιά έγγραφα, ή έγγραφα που η ανάγνωσή τους δεν πρόλαβε: διαβάζονται την
	 * πρώτη φορά που ανοίγει η καρτέλα τους. Ο,τι έχει ήδη κριθεί δεν
	 * ξαναδιαβάζεται — ο server το ξέρει από το kind_source, εδώ απλά δεν
	 * ενοχλούμε τον χρήστη με μήνυμα. */
	var unread = (c.files || []).filter(function (f) { return !f.kind_source && !f.system_label; });
	if (unread.length) {
		reviewKinds(true).then(function (changed) { if (changed) openDetail(c.id); });
	}

	/* Αναίρεση μιας αυτόματης διόρθωσης. Ο άνθρωπος έχει τον τελευταίο λόγο, και
	   αφού τον πει, η ανάγνωση δεν ξαναπροσπαθεί σε αυτό το αρχείο. */
	var unkinds = view.querySelectorAll('[data-unkind]');
	for (var u = 0; u < unkinds.length; u++) {
		unkinds[u].addEventListener('click', function () {
			var fileId = this.getAttribute('data-unkind');
			var btn = this;
			btn.disabled = true;
			fetch(api('/contracts/' + c.id + '/files/' + fileId + '/unkind'), { method: 'POST', headers: H() })
				.then(function (r) { return r.json(); })
				.then(function (d) {
					if (!d || !d.ok) { btn.disabled = false; toast((d && d.error) || 'Αποτυχία.', false); return; }
					toast('Επανήλθε: ' + d.label + '.');
					openDetail(c.id);
				})
				.catch(function () { btn.disabled = false; toast('Σφάλμα δικτύου.', false); });
		});
	}

	/* (272) Χειροκίνητη επιλογή είδους -- πάντα διαθέσιμη, δουλεύει και όταν
	   δεν υπήρξε ποτέ αυτόματη διόρθωση να αναιρεθεί. */
	var kindSels = view.querySelectorAll('[data-setkind]');
	for (var ks = 0; ks < kindSels.length; ks++) {
		kindSels[ks].addEventListener('change', function () {
			var fileId = this.getAttribute('data-setkind');
			var sel = this;
			var kind = sel.value;
			sel.disabled = true;
			fetch(api('/contracts/' + c.id + '/files/' + fileId + '/kind'), {
				method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H()), body: JSON.stringify({ kind: kind })
			})
				.then(function (r) { return r.json(); })
				.then(function (d) {
					if (!d || !d.ok) { sel.disabled = false; toast((d && d.error) || 'Αποτυχία.', false); return; }
					toast('Ορίστηκε ως «' + d.label + '».');
					openDetail(c.id);
				})
				.catch(function () { sel.disabled = false; toast('Σφάλμα δικτύου.', false); });
		});
	}

	/* (274) Οριστική διαγραφή ενός εγγράφου -- λάθος αρχείο, διπλό, ό,τι
	   ανέβηκε κατά λάθος. Απλό window.confirm(), ίδιο επίπεδο με τη
	   διαγραφή πρόχειρης αίτησης (γραμμή 524) -- ένα έγγραφο δεν έχει την
	   ίδια βαρύτητα με ολόκληρη υπογεγραμμένη σύμβαση, δεν χρειάζεται την
	   τελετουργία του confirmTypedWithReason(). */
	var delFiles = view.querySelectorAll('[data-delfile]');
	for (var df = 0; df < delFiles.length; df++) {
		delFiles[df].addEventListener('click', function () {
			var fileId = this.getAttribute('data-delfile');
			var btn = this;
			if (!window.confirm('Οριστική διαγραφή αυτού του εγγράφου;')) { return; }
			btn.disabled = true;
			fetch(api('/contracts/' + c.id + '/files/' + fileId), { method: 'DELETE', headers: H() })
				.then(function (r) { return r.json(); })
				.then(function (d) {
					if (!d || !d.ok) { btn.disabled = false; toast((d && d.error) || 'Αποτυχία διαγραφής.', false); return; }
					toast('Το έγγραφο διαγράφηκε.', true);
					openDetail(c.id);
				})
				.catch(function () { btn.disabled = false; toast('Σφάλμα δικτύου.', false); });
		});
	}

	var docGo = view.querySelector('[data-docup-go]');
	if (docGo) docGo.addEventListener('click', function () {
		var wrap = view.querySelector('[data-docup]');
		var input = wrap.querySelector('[data-docup-file]');
		var msg = wrap.querySelector('[data-docup-msg]');
		if (!input.files || !input.files.length) { msg.textContent = 'Επίλεξε αρχείο.'; msg.className = 'ecrm-docup__msg is-err'; return; }

		var rows = wrap.querySelectorAll('[data-docup-row]');
		var fd = new FormData();
		var uploadedKinds = [];
		for (var i = 0; i < input.files.length; i++) {
			var row = rows[i];
			var sel = row ? row.querySelector('[data-docup-kind]') : null;
			var exp = row ? row.querySelector('[data-docup-expiry]') : null;
			var kind = sel ? sel.value : 'other';
			fd.append('files[]', input.files[i]);
			fd.append('kinds[]', kind);
			fd.append('expires_at[]', exp && !exp.hidden ? exp.value : '');
			uploadedKinds.push(kind);
		}

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
				// (281) Λείπουν ΑΦΜ/ΑΔΤ και μόλις μπήκε ταυτότητα/λογαριασμός/SIM;
				// Ο εξαγωγέας υπάρχει ήδη και δουλεύει ακριβώς έτσι στη "Νέα
				// αίτηση" (ExtractionController::extract() με contract_id+apply,
				// χωρίς αρχεία -- διαβάζει ό,τι είναι ήδη αποθηκευμένο) -- απλά
				// δεν καλούνταν ποτέ από εδώ. Ξαναδιαβάζει ΟΛΑ τα αναγνώσιμα
				// έγγραφα της αίτησης, όχι μόνο το καινούργιο (δεν υπάρχει
				// "already read" σημείωση σαν το kind_source για την ταξινόμηση),
				// άρα τρέχει ΜΟΝΟ όταν κάτι πραγματικά λείπει -- όχι σε κάθε
				// ανέβασμα, να μην πληρώνεται το μοντέλο ξανά και ξανά για τα
				// ίδια αρχεία.
				// Κάθε έγγραφο μπορεί να έχει στοιχεία (29/09) -- όχι μόνο ταυτότητα/λογαριασμός.
				var worthExtracting = !(c.afm && c.adt) && uploadedKinds.length > 0;
				// Η ανάγνωση τρέχει ΠΡΙΝ το ξαναχτίσιμο, ώστε η καρτέλα να έρθει
				// ήδη με τις διορθωμένες ετικέτες και το checklist σωστό.
				reviewKinds(false).then(function () {
					return worthExtracting ? extractMissing(c.id) : null;
				}).then(function () { openDetail(c.id); });
			})
			.catch(function () { msg.textContent = 'Σφάλμα δικτύου.'; msg.className = 'ecrm-docup__msg is-err'; b.disabled = false; });
	});
}

/* (281) Ιδια κλήση με αυτή που ήδη κάνει η "Νέα αίτηση" (βλ. ecrm-form.js)
 * -- διαβάζει ό,τι έγγραφο έχει ήδη η αίτηση και γράφει ό,τι πεδίο είναι
 * ακόμα κενό (ΑΦΜ/ΑΔΤ κλπ, βλ. ExtractionController::applyToRecords() --
 * γεμίζει μόνο τα κενά, ποτέ δεν αντικαθιστά κάτι που ο συνεργάτης έγραψε).
 * Ο καλών αποφασίζει ΠΟΤΕ αξίζει να τρέξει -- εδώ απλά την εκτελεί, σιωπηλή
 * σε κάθε αποτυχία: μια ανάγνωση που δεν πρόλαβε δεν είναι κρίση, μένει η
 * "Επεξεργασία" για το χέρι. */
function extractMissing(contractId) {
	// Τα PDF κόβονται στον browser πριν πάνε στο AI (ecrm-ai-docs.js). Χωρίς
	// PDF ή αν αποτύχει, ο server τα διαβάζει από τον δίσκο όπως πριν.
	return storedForAi({ contract_id: contractId }).then(function (stored) {
		var fd = new FormData();
		fd.append('contract_id', String(contractId));
		fd.append('apply', '1');
		(stored || []).forEach(function (item) { fd.append('files[]', item.file); fd.append('kinds[]', item.kind); });
		return fetch(api('/extract'), { method: 'POST', headers: H(), body: fd });
	})
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (d && d.ok && d.applied && d.applied.length) {
				toast('Η AI συμπλήρωσε ' + d.applied.length + ' στοιχεί' + (d.applied.length === 1 ? 'ο' : 'α') + ' από τα έγγραφα.');
			}
		})
		.catch(function () {});
}
function downloadBinary(path, btn, busy, idle) {
	btn.disabled = true; var t = btn.innerHTML; btn.innerHTML = busy;
	fetch(api(path), { headers: H() })
		.then(function (r) {
			return r.text().then(function (txt) {
				try { return JSON.parse(txt); }
				catch (e) { throw new Error('Ο server δεν απάντησε σωστά.'); }
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
		.finally(function () { btn.disabled = false; btn.innerHTML = idle || t; });
}

/**
 * (326) Η δίδυμη αίτηση (αέριο από ρεύμα, ρεύμα από αέριο). Mockup:
 * docs/UI-ADD-GAS.html. Αν υπάρχει ήδη, σύνδεσμος προς αυτήν· αν ο πάροχος
 * δεν έχει προγράμματα του άλλου είδους, τίποτα.
 */
function fillCompanion(view, c) {
	var slot = view.querySelector('[data-companion-slot]');
	if (!slot) return;
	var gas = c.energy_type === 'power';
	var label = gas ? 'Αέριο' : 'Ρεύμα';
	fetch(api('/contracts/' + c.id + '/companion'), { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (!d || !d.ok) return;
			if (d.existing) {
				var ex = d.existing;
				slot.innerHTML = '<button type="button" class="ecrm-companion-link" data-companion-open="' + esc(ex.id) + '">' +
					'<span>' + esc(label) + ': <b>' + esc(ex.code) + '</b>' + (ex.program_name ? ' · ' + esc(ex.program_name) : '') + '</span>' +
					'<span class="ecrm-companion-link__go">Άνοιγμα →</span></button>';
				slot.querySelector('[data-companion-open]').addEventListener('click', function () { openDetail(ex.id); });
				return;
			}
			if (!d.available) return;
			slot.innerHTML = '<button type="button" class="ecrm-btn ecrm-btn--companion" data-companion-add>' +
				'<svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true">' + (gas
					? '<path d="M12 3c3 4 5 6.5 5 10a5 5 0 0 1-10 0c0-2 1-3.5 2-5 .5 1.5 1.5 2.5 3 3 0-3-1-5.5 0-8z"/>'
					: '<path d="M13 2L4 14h7l-1 8 9-12h-7z"/>') +
				'</svg> + ' + esc(label) + ' στον ίδιο πελάτη</button>';
			slot.querySelector('[data-companion-add]').addEventListener('click', function () {
				var btn = this; btn.disabled = true;
				addCompanion(c.id).finally(function () { btn.disabled = false; });
			});
		})
		.catch(function () { /* χωρίς σύνδεση: απλώς δεν εμφανίζεται */ });
}

/** Φτιάχνει (ή βρίσκει) τη δίδυμη αίτηση και την ανοίγει στη φόρμα. */
export function addCompanion(id) {
	return fetch(api('/contracts/' + id + '/companion'), { method: 'POST', headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (!d || !d.ok || !d.contract_id) { toast((d && d.error) || 'Αποτυχία.', false); return; }
			toast(d.existing ? 'Υπάρχει ήδη: ' + (d.code || '') + ' — άνοιξε για επεξεργασία.' : 'Δημιουργήθηκε ' + (d.code || '') + ' — συμπλήρωσε πρόγραμμα και μετρητή.');
			return fetch(api('/contracts/' + d.contract_id), { headers: H() })
				.then(function (r) { return r.json(); })
				.then(function (dd) { openEdit(dd.contract || dd); });
		})
		.catch(function () { toast('Σφάλμα δικτύου.', false); });
}


/* (348) Παρατηρήσεις στο rail, κάτω από το Checklist: «Παρατηρήσεις Protergia»
 * (μόνο σε «Εκκρεμότητα», το αποφασίζει ο server με `show_provider`· γράφει
 * μόνο ο Back office) και «Παρατηρήσεις Agent» (σε κάθε αίτηση).
 * Κάρτες που ανοίγουν/κλείνουν με βελάκι (docs/UI-REMARKS-RAIL.html, εκδοχή Β,
 * εγκρίθηκε 03/11). Κλειστές: πόσες + η τελευταία. Ανοιχτές: κουτί νέας
 * παρατήρησης (όπου επιτρέπεται) και όλο το ιστορικό, νεότερη πρώτη. */
var REMARK_LABELS = { remark_provider: 'Παρατηρήσεις Protergia', remark_agent: 'Παρατηρήσεις Agent' };

// Ποια κάρτα είναι ανοιχτή, ανά αίτηση -- για να μένει ανοιχτή μετά την αποθήκευση.
var remarkOpen = {};

function remarkBlock(kind, label, editable, max, c) {
	var type = kind === 'provider' ? 'remark_provider' : 'remark_agent';
	var list = (c.events || []).filter(function (e) { return e.type === type; });
	var open = !!remarkOpen[c.id + ':' + kind];
	var last = list[0];
	var lastHTML = last
		? '<div class="ecrm-rm__last"><span class="ecrm-rm__lt">' + esc(last.message || '') + '</span><small>' + esc(last.actor || '') + ' · ' + esc(fmtDate(last.created_at)) + '</small></div>'
		: '<div class="ecrm-rm__last is-none">Καμία παρατήρηση ακόμα.</div>';
	var form = editable
		? '<div class="ecrm-rm__new"><textarea class="ecrm-rm__ta" maxlength="' + esc(String(max)) + '" data-remark-text="' + kind + '" placeholder="' +
			(kind === 'provider' ? 'Νέα παρατήρηση της Protergia…' : 'Γράψε τι έγινε με την αίτηση…') + '"></textarea>' +
			'<div class="ecrm-rm__row"><button type="button" class="ecrm-btn ecrm-btn--primary ecrm-btn--sm" data-remark-save="' + kind + '">Αποθήκευση</button></div></div>'
		: (kind === 'provider' ? '<p class="ecrm-rm__ro">Μόνο ο Back office γράφει εδώ.</p>' : '');
	var r = c.remarks || {};
	var hist = list.length
		? '<ul class="ecrm-rm__hist">' + list.map(function (e, i) {
			// Κάδος: σε αυτόν που την έγραψε, και σε Back office / admin σε όλες.
			var del = (r.can_delete_any || (+e.user_id > 0 && +e.user_id === +r.me))
				? '<button type="button" class="ecrm-rm__del" data-remark-del="' + esc(String(e.id)) + '" data-kind="' + kind + '" title="Διαγραφή" aria-label="Διαγραφή παρατήρησης">' + svgIcon('trash') + '</button>'
				: '';
			return '<li' + (i === 0 ? ' class="is-now"' : '') + '>' + del + '<span class="ecrm-rm__msg">' + esc(e.message || '') + '</span>' +
				'<small>' + esc(e.actor || '') + ' · ' + esc(fmtDate(e.created_at)) + '</small></li>';
		}).join('') + '</ul>'
		: '';
	return '<div class="ecrm-rm ecrm-rm--' + kind + (open ? ' is-open' : '') + '" data-rm="' + kind + '">' +
		'<button type="button" class="ecrm-rm__h" data-rm-toggle="' + kind + '" aria-expanded="' + (open ? 'true' : 'false') + '">' +
		'<span class="ecrm-rm__dot"></span><span class="ecrm-rm__t">' + label + '</span>' +
		'<span class="ecrm-rm__c">' + list.length + '</span>' +
		'<span class="ecrm-rm__x" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg></span></button>' +
		// Τα δύο «συρτάρια» ανοιγοκλείνουν με ομαλή κίνηση ύψους (grid 0fr↔1fr στο CSS).
		'<div class="ecrm-rm__fold ecrm-rm__fold--last"><div>' + lastHTML + '</div></div>' +
		'<div class="ecrm-rm__fold ecrm-rm__fold--body"><div><div class="ecrm-rm__b">' + form + hist + '</div></div></div></div>';
}

function remarksCard(c) {
	var r = c.remarks;
	if (!r) { return ''; }
	return '<div class="ecrm-remarks">' +
		(r.show_provider ? remarkBlock('provider', 'Παρατηρήσεις Protergia', !!r.can_provider, r.max, c) : '') +
		remarkBlock('agent', 'Παρατηρήσεις Agent', true, r.max, c) +
		'</div>';
}

function redrawRemarks(view, c) {
	// Το `view` είναι είτε όλη η σελίδα (πρώτη φορά) είτε το ίδιο το wrap (μετά).
	var wrap = view.matches && view.matches('[data-remarks-wrap]') ? view : view.querySelector('[data-remarks-wrap]');
	if (wrap) { wrap.innerHTML = remarksCard(c); bindRemarks(wrap, c); }
}

function bindRemarks(view, c) {
	// Διαγραφή σε δύο πατήματα: το πρώτο ρωτά «Σίγουρα;», το δεύτερο σβήνει.
	view.querySelectorAll('[data-remark-del]').forEach(function (b) {
		b.addEventListener('click', function () {
			if (!b.classList.contains('is-confirm')) {
				b.classList.add('is-confirm');
				b.innerHTML = 'Διαγραφή;';
				setTimeout(function () { if (b.isConnected && b.classList.contains('is-confirm')) { b.classList.remove('is-confirm'); b.innerHTML = svgIcon('trash'); } }, 4000);
				return;
			}
			var eid = b.getAttribute('data-remark-del'), kind = b.getAttribute('data-kind');
			b.disabled = true;
			fetch(api('/contracts/' + c.id + '/remarks/' + eid), { method: 'DELETE', headers: H() })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (res && res.ok) {
						c.events = (c.events || []).filter(function (e) { return String(e.id) !== String(eid); });
						if (c.remarks && res.remarks) { c.remarks.provider = c.remarks.show_provider ? res.remarks.provider : null; c.remarks.agent = res.remarks.agent; }
						remarkOpen[c.id + ':' + kind] = true;
						redrawRemarks(view, c);
						toast('Η παρατήρηση διαγράφηκε.');
					} else { b.disabled = false; toast((res && res.error) || 'Αποτυχία διαγραφής.', false); }
				})
				.catch(function () { b.disabled = false; toast('Σφάλμα δικτύου.', false); });
		});
	});
	view.querySelectorAll('[data-rm-toggle]').forEach(function (h) {
		h.addEventListener('click', function () {
			var kind = this.getAttribute('data-rm-toggle');
			var box = this.parentNode;
			var open = !box.classList.contains('is-open');
			box.classList.toggle('is-open', open);
			this.setAttribute('aria-expanded', open ? 'true' : 'false');
			remarkOpen[c.id + ':' + kind] = open;
		});
	});
	view.querySelectorAll('[data-remark-save]').forEach(function (b) {
		b.addEventListener('click', function () {
			var kind = this.getAttribute('data-remark-save');
			var ta = view.querySelector('[data-remark-text="' + kind + '"]');
			var text = ta ? ta.value.trim() : '';
			if (!text) { toast('Γράψε μια παρατήρηση.', false); return; }
			b.disabled = true;
			fetch(api('/contracts/' + c.id + '/remarks'), { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H()), body: JSON.stringify({ kind: kind, text: text }) })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (res && res.ok) {
						// Χωρίς επαναφόρτωση της σελίδας: η νέα παρατήρηση μπαίνει πρώτη
						// στο ιστορικό και ξαναζωγραφίζεται μόνο η κάρτα των παρατηρήσεων.
						var cur = res.remarks && res.remarks[kind];
						c.events = c.events || [];
						c.events.unshift({
							id: cur ? cur.id : 0,
							user_id: c.remarks ? c.remarks.me : 0,
							type: kind === 'provider' ? 'remark_provider' : 'remark_agent',
							message: cur ? cur.text : text,
							actor: cur ? cur.actor : '',
							created_at: cur ? cur.created_at : ''
						});
						if (kind === 'provider' && c.remarks) { c.remarks.provider = cur || null; }
						if (kind === 'agent' && c.remarks) { c.remarks.agent = cur || null; }
						remarkOpen[c.id + ':' + kind] = true;
						redrawRemarks(view, c);
						toast('Αποθηκεύτηκε.');
					}
					else { b.disabled = false; toast((res && res.error) || 'Αποτυχία αποθήκευσης.', false); }
				})
				.catch(function () { b.disabled = false; toast('Σφάλμα δικτύου.', false); });
		});
	});
}
