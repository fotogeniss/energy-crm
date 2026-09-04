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
import { energyLabel, fmtDate, initials, svgIcon, timeAgo, tint } from '@energy-crm/format';
import { go, openEdit } from '@energy-crm/navigate';
import { confirmTyped, confirmTypedWithReason, openDialog } from '@energy-crm/dialog';

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
			return '<a class="ecrm-file" href="' + esc(f.url || '#') + '" target="_blank" rel="noopener">' +
				'<span class="ecrm-file__thumb">' + thumb + '</span>' +
				'<span class="ecrm-file__meta"><span class="ecrm-file__name">' + esc(f.filename || 'έγγραφο') + '</span>' +
				'<span class="ecrm-file__kind">' + esc(kindLabel[f.doc_kind] || 'Έγγραφο') + '</span>' + expiryTag + '</span></a>';
		}).join('') + '</div>';
	}

	// Inline upload control
	var kindOpts = Object.keys(kindLabel).map(function (k) {
		return '<option value="' + esc(k) + '">' + esc(kindLabel[k]) + '</option>';
	}).join('');
	var expirable = c.doc_expirable || [];
	var upload = '<div class="ecrm-docup" data-docup="' + c.id + '" data-docup-expirable="' + esc(JSON.stringify(expirable)) + '">' +
		'<select class="ecrm-input ecrm-docup__kind" data-docup-kind>' + kindOpts + '</select>' +
		'<input type="file" multiple accept="image/*,application/pdf" data-docup-file>' +
		'<input type="date" class="ecrm-input ecrm-docup__expiry" data-docup-expiry title="Ημερομηνία λήξης (αν υπάρχει πάνω στο έγγραφο)" hidden>' +
		'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-docup-go>Προσθήκη</button>' +
		'<span class="ecrm-docup__msg" data-docup-msg></span></div>';

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
	// 'awaiting_signature' μένει επιτρεπτό στο ContractStatus::allowedNext()
	// (ο server), αλλά είναι το ίδιο βήμα με το 'pending_signature' — νεκρό
	// status από παλιότερο σύστημα, τίποτα δεν το γράφει πια. Ένα κουμπί εδώ
	// θα το ανασταίνε σε πραγματική σύμβαση. Ίδια εξαίρεση με τη μπάρα
	// φίλτρων του ecrm-view-contracts.js, ίδιος λόγος.
	var statusOpts = Object.keys(statuses).filter(function (s) {
		return allowedSet[s] && s !== c.status && s !== 'awaiting_signature';
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
				: (e.message || e.type);
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
				'Ρώτα τη Λίτσα γι\' αυτή την αίτηση</button>' +
			'</div>';
	}

	// Το checklist του rail βγαίνει από πεδία που ΗΔΗ υπάρχουν στην οθόνη — δεν
	// εφευρίσκεται κατάσταση που το backend δεν στέλνει. Πέντε γραμμές, καθεμιά
	// με ένα ερώτημα που ο συνεργάτης μπορεί να απαντήσει κοιτώντας δίπλα.
	//
	// AUDIT 30/08: εδώ ήταν μια λίστα καταστάσεων (SIGNED_ON) που έπρεπε να
	// μένει διαρκώς συγχρονισμένη με το ContractStatus -- και δεν ήταν: μια
	// σύμβαση που έφτασε Signed → Active → Terminated (ή Signed → Cancelled,
	// νόμιμη απευθείας μετάβαση) έδειχνε «○ Υπογραφή πελάτη» παρότι είχε
	// πράγματι υπογραφεί, γιατί το 'terminated'/'cancelled' δεν ήταν στη
	// λίστα. Το `c.signed_at` είναι ήδη η πηγή αλήθειας παρακάτω σε αυτό το
	// ίδιο αρχείο (`auditCard`) -- ίδιο κριτήριο εδώ, όχι δεύτερη λίστα να
	// ξεσυγχρονιστεί.
	var checks = [
		{ ok: !!(c.afm && c.adt),  txt: 'Στοιχεία ταυτότητας' },
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
		stuckCard(c) +
		auditCard +
		'<div class="ecrm-card ecrm-rcard' + (done === checks.length ? ' is-ok' : '') + '">' +
		'<div class="ecrm-step">Checklist &nbsp;<b>' + done + '/' + checks.length + '</b></div>' + checklistHTML + '</div>' +
		'<div class="ecrm-drail__acts">' +
		// Μόνο σε καταστάσεις απ' όπου το pipeline όντως επιτρέπει μετάβαση σε
		// pending_signature (ContractStatus::allowedNext(), src/Domain/Contract/
		// ContractStatus.php) — μέχρι το 2026-08-24 το κουμπί έμενε πάντα
		// πράσινο/ενεργό και καλούσε τον πράκτορα σε ενέργεια που θα
		// απορριπτόταν με 409 χωρίς προειδοποίηση.
		//
		// 'signed' και 'routed' ΕΙΝΑΙ μέσα, σκόπιμα: από εκεί περνά η δεύτερη
		// υπογραφή — ο πάροχος γυρίζει πίσω την αίτηση («Στάλθηκε στον
		// πάροχο») ζητώντας νέα, ή βρέθηκε λάθος αμέσως μετά την υπογραφή.
		// Δεν στέλνει σιωπηλά: ο SignLinkController απαντά needs_confirm και
		// ο χρήστης επιβεβαιώνει πρώτα ότι σβήνει την παλιά υπογραφή.
		// ΑΦΟΡΑ ΟΛΟΥΣ ΤΟΥΣ ΠΑΡΟΧΟΥΣ — καμία σχέση με energy_type/Orizon.
		//
		// 'processing' προστέθηκε 04/09, ίδιο μοτίβο: κάθεται ανάμεσα σε
		// 'signed' και 'routed' στο ContractStatus::allowedNext() -- μια
		// COMBO αίτηση μπορεί να φτάσει εδώ αυτόματα (AutoProcess) ενώ
		// γνήσια λείπει ακόμα η δεύτερη υπογραφή.
		( [ 'draft', 'new', 'pending_signature', 'awaiting_signature', 'signed', 'processing', 'routed' ].indexOf( c.status ) !== -1
			? '<button type="button" class="ecrm-btn ecrm-btn--primary" data-sign="' + c.id + '"><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 20h18M4 16l9-9 3 3-9 9H4z"/><path d="M13 5l3 3"/></svg> Στείλε για υπογραφή</button>'
			: '' ) +
		'<button type="button" class="ecrm-btn ecrm-btn--ghost" data-provform="' + c.id + '"><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4M9 13h6M9 17h4"/></svg> Λήψη εντύπου παρόχου</button>' +
		(c.track_url ? '<button type="button" class="ecrm-btn ecrm-btn--ghost" data-track="' + esc(c.track_url) + '"><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M10 13a5 5 0 007 0l3-3a5 5 0 00-7-7l-1.5 1.5M14 11a5 5 0 00-7 0l-3 3a5 5 0 007 7l1.5-1.5"/></svg> Σύνδεσμος παρακολούθησης</button>' : '') +
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
		if (!panel) { toast('Η Λίτσα δεν είναι διαθέσιμη εδώ.', false); return; }

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
	var docupKindSel = docupWrap ? docupWrap.querySelector('[data-docup-kind]') : null;
	var docupExpiry = docupWrap ? docupWrap.querySelector('[data-docup-expiry]') : null;
	// Το πεδίο λήξης φαίνεται μόνο για είδη που πραγματικά λήγουν (σήμερα:
	// ταυτότητα/διαβατήριο) — η λίστα έρχεται από τον server
	// (ECRM_Docs::expirable_kinds()), δεν είναι κωδικοποιημένη εδώ.
	function syncExpiryVisibility() {
		if (!docupWrap || !docupKindSel || !docupExpiry) return;
		var expirable = [];
		try { expirable = JSON.parse(docupWrap.getAttribute('data-docup-expirable') || '[]'); } catch (e) { expirable = []; }
		var show = expirable.indexOf(docupKindSel.value) !== -1;
		docupExpiry.hidden = !show;
		if (!show) docupExpiry.value = '';
	}
	if (docupKindSel) { docupKindSel.addEventListener('change', syncExpiryVisibility); syncExpiryVisibility(); }

	var docGo = view.querySelector('[data-docup-go]');
	if (docGo) docGo.addEventListener('click', function () {
		var wrap = view.querySelector('[data-docup]');
		var input = wrap.querySelector('[data-docup-file]');
		var kind = wrap.querySelector('[data-docup-kind]').value;
		var expiryEl = wrap.querySelector('[data-docup-expiry]');
		var expiry = expiryEl && !expiryEl.hidden ? expiryEl.value : '';
		var msg = wrap.querySelector('[data-docup-msg]');
		if (!input.files || !input.files.length) { msg.textContent = 'Επίλεξε αρχείο.'; msg.className = 'ecrm-docup__msg is-err'; return; }
		var fd = new FormData();
		for (var i = 0; i < input.files.length; i++) { fd.append('files[]', input.files[i]); fd.append('kinds[]', kind); fd.append('expires_at[]', expiry); }
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
		.finally(function () { btn.disabled = false; btn.textContent = idle || t; });
}
