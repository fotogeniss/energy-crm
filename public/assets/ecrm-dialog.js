/* Energy CRM — το κέλυφος διαλόγου, και η πύλη με πληκτρολόγηση.
 *
 * ## Γιατί υπάρχει ξεχωριστά
 *
 * Το `.ecrm-modalov` / `.ecrm-modal` το έχτιζε μόνο του το export-modal. Μόλις
 * χρειάστηκε δεύτερος και τρίτος χρήστης (μαζική διαγραφή, διαγραφή αίτησης,
 * νέα εργασία), το σκαρίφημα έγινε αντικείμενο αντί να αντιγραφεί τρεις φορές.
 * Το ίδιο σκεπτικό με το `ScopeClause` και τη `deltaChip()`: μια δεύτερη γραφή
 * του ίδιου πράγματος είναι δεύτερο σημείο να γίνει λάθος.
 *
 * ## Τι προσθέτει σε σχέση με το υπάρχον
 *
 * **Escape**, **εστίαση** και **παγίδα Tab**. Το export-modal δεν έχει κανένα
 * από τα τρία: ανοίγει, και ο χρήστης πρέπει να βρει το × με το ποντίκι, ενώ
 * το Tab τον βγάζει πίσω από το πέπλο σε στοιχεία που δεν βλέπει. Ένας
 * διάλογος που ζητά πληκτρολόγηση χωρίς να δίνει τον δρόμο πίσω είναι παγίδα,
 * όχι ασφάλεια — και ένας που δηλώνει `aria-modal="true"` χωρίς να κρατά την
 * εστίαση λέει ψέματα στον αναγνώστη οθόνης.
 *
 * ## Η πύλη με πληκτρολόγηση, και πού ΔΕΝ μπαίνει
 *
 * Μπαίνει μόνο όπου η ενέργεια είναι **οριστική και παίρνει μαζί της δεδομένα
 * που δεν ξαναφτιάχνονται** — μαζική διαγραφή, διαγραφή αίτησης που έχει περάσει
 * το πρόχειρο. ΔΕΝ μπαίνει σε διαγραφή πρόχειρης, εργασίας ή αφαίρεση μέλους:
 * εκεί το `confirm()` αρκεί. Μια τελετουργία που ζητιέται παντού εκπαιδεύει τον
 * χρήστη να την προσπερνά, και τότε χάνεται και εκεί που μετράει.
 * Απόφαση ιδιοκτήτη 21/08 — docs/UI-DIALOGS.html.
 */

import { esc, root } from '@energy-crm/util';

var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/* Το lead ως ΔΕΔΟΜΕΝΑ, όχι ως markup.
 *
 * Η πρώτη γραφή έπαιρνε το lead αυτούσιο, ώστε να μπορεί να έχει <strong>.
 * Αυτό θα έσπαγε το FrontendEscapingTest — `+ opts.lead +` σε γραμμή με <p>
 * είναι ακριβώς αυτό που ψάχνει — και ο μόνος τρόπος να περάσει θα ήταν μια
 * εγγραφή στο APPROVED, μια λίστα που το ίδιο της το docblock λέει ότι πρέπει
 * να μένει άδεια. Άρα το lead γίνεται πίνακας: συμβολοσειρά = απλό κείμενο,
 * { b: '…' } = έντονο. Και τα δύο περνούν από esc(). Ίδια λογική με το
 * --st-cancel παραπάνω: δεν παίρνεις εξαίρεση από τον μηχανισμό, τον
 * χρησιμοποιείς.
 */
function leadMarkup(lead) {
	if (!lead) { return ''; }

	var parts = Array.isArray(lead) ? lead : [lead];

	return parts.map(function (part) {
		if (typeof part === 'string') { return esc(part); }
		return '<strong>' + esc(part.b) + '</strong>';
	}).join('');
}

/**
 * Ανοίγει διάλογο και επιστρέφει { el, close }.
 *
 * Το `opts.body` μπαίνει ΑΥΤΟΥΣΙΟ — είναι σκαρίφημα που γράφει ο καλών με
 * σταθερά ονόματα κλάσεων. Ό,τι προέρχεται από δεδομένα το περνά αυτός από
 * esc(). Το `opts.lead` όχι: δες leadMarkup() ακριβώς από πάνω.
 */
export function openDialog(opts) {
	opts = opts || {};

	/*
	 * Το κουμπί επιβεβαίωσης παρακάτω έχει `data-dlg-confirm`, ΟΧΙ `data-go`.
	 * Το `root()` (@energy-crm/util) επιστρέφει το ΙΔΙΟ #ecrm-app στοιχείο
	 * που έχει τον καθολικό click listener του κελύφους (ecrm-app.js), κι
	 * αυτός ψάχνει ΟΠΟΙΟΔΗΠΟΤΕ `[data-go]` μέσα του χωρίς να ξέρει ότι είναι
	 * μέσα σε διάλογο -- ένα bare `data-go` εδώ θα bubble-άρει ως go(''),
	 * που αδειάζει την ενεργή όψη (καμία `.ecrm-view` δεν ταιριάζει σε
	 * data-view="") ΠΡΙΝ καν προλάβει να τρέξει το onConfirm. Ζωντανό bug,
	 * 06/09: η καρτέλα πελάτη έδειχνε λευκή σελίδα μετά από κάθε
	 * αποθήκευση -- εμφανές μόνο όταν το onConfirm σταμάτησε να ξανακαλεί
	 * go() μετά την αποθήκευση, που έως τότε "θεράπευε" τυχαία το ίδιο bug.
	 */
	var ov = document.createElement('div');
	ov.className = 'ecrm-modalov';
	ov.innerHTML =
		'<div class="ecrm-modal" role="dialog" aria-modal="true">' +
			'<button type="button" class="ecrm-modal__x" data-x aria-label="Κλείσιμο">×</button>' +
			(opts.eyebrow ? '<div class="ecrm-modal__eyebrow' + (opts.danger ? ' ecrm-modal__eyebrow--danger' : '') + '">' + esc(opts.eyebrow) + '</div>' : '') +
			'<h3 class="ecrm-modal__title">' + esc(opts.title || '') + '</h3>' +
			(opts.lead ? '<p class="ecrm-modal__lead">' + leadMarkup(opts.lead) + '</p>' : '') +
			(opts.body || '') +
			'<div class="ecrm-modal__bar">' +
				'<span class="ecrm-modal__spacer"></span>' +
				'<button type="button" class="ecrm-btn ecrm-btn--ghost" data-x>' + esc(opts.cancel || 'Ακύρωση') + '</button>' +
				'<button type="button" class="ecrm-btn ' + (opts.danger ? 'ecrm-btn--danger' : 'ecrm-btn--primary') + '" data-dlg-confirm' +
					(opts.armed === false ? ' disabled' : '') + '>' + esc(opts.confirm || 'Εντάξει') + '</button>' +
			'</div>' +
		'</div>';

	root().appendChild(ov);

	// Η εστίαση επιστρέφει εκεί που ήταν. Χωρίς αυτό, το κλείσιμο πετάει τον
	// χρήστη πληκτρολογίου στην αρχή της σελίδας.
	var previous = document.activeElement;
	var closed = false;

	function close() {
		if (closed) { return; }
		closed = true;
		document.removeEventListener('keydown', onKey, true);
		ov.remove();
		if (previous && typeof previous.focus === 'function') { previous.focus(); }
		if (typeof opts.onClose === 'function') { opts.onClose(); }
	}

	function onKey(e) {
		if (e.key === 'Escape') { close(); return; }
		if (e.key !== 'Tab') { return; }

		// Παγίδα Tab. Χωρίς αυτή, το τρίτο Tab φεύγει πίσω από το πέπλο και ο
		// χρήστης πληκτρολογίου «γράφει» σε οθόνη που δεν βλέπει.
		var items = Array.prototype.filter.call(
			ov.querySelectorAll(FOCUSABLE),
			function (el) { return el.offsetParent !== null || el === document.activeElement; }
		);
		if (!items.length) { return; }

		var first = items[0];
		var last = items[items.length - 1];

		if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
		else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
	}

	// Στη φάση σύλληψης: ένα πεδίο που καταναλώνει το keydown (π.χ. το native
	// datetime-local) δεν πρέπει να μπορεί να κρατήσει τον χρήστη μέσα.
	document.addEventListener('keydown', onKey, true);
	ov.addEventListener('click', function (e) { if (e.target === ov) { close(); } });
	ov.querySelectorAll('[data-x]').forEach(function (b) { b.addEventListener('click', close); });

	var go = ov.querySelector('[data-dlg-confirm]');
	if (go && typeof opts.onConfirm === 'function') {
		go.addEventListener('click', function () { opts.onConfirm(ov, close, go); });
	}

	// Το πρώτο πεδίο, αλλιώς το κουμπί ακύρωσης — ΠΟΤΕ το επικίνδυνο κουμπί.
	var target = ov.querySelector('input, select, textarea') || ov.querySelector('[data-x]');
	if (target) { target.focus(); }

	return { el: ov, close: close };
}

/**
 * Καταστροφική ενέργεια που ξεκλειδώνει μόνο με ακριβή πληκτρολόγηση.
 *
 * Ο έλεγχος είναι ΑΚΡΙΒΗΣ: όχι case-insensitive, όχι ανεκτικός σε κενά. Ίδιος
 * κανόνας με το D3 στο `admin/class-ecrm-gdpr.php` — μια μη αναστρέψιμη
 * ενέργεια δεν έχει «σχεδόν σωστό».
 *
 * @param {Object} o
 * @param {string} o.expect      Τι πρέπει να πληκτρολογηθεί, ακριβώς.
 * @param {string} o.expectLabel Τι λέει η ετικέτα του πεδίου.
 */
export function confirmTyped(o) {
	var expect = String(o.expect);

	var body =
		'<div class="ecrm-modal__card">' +
			'<label class="ecrm-field"><span class="ecrm-field__label">' + esc(o.expectLabel || 'Επιβεβαίωση') + '</span>' +
			// ΟΧΙ σκέτο το ζητούμενο. Στην απόδοση το placeholder «12» ήταν
			// οπτικά ίδιο με πληκτρολογημένο «12»: ο διάλογος έδειχνε
			// ΣΥΜΠΛΗΡΩΜΕΝΟΣ ενώ ήταν κλειδωμένος. Το πρόθεμα το κάνει οδηγία
			// και όχι τιμή — ίδια διατύπωση με το D3 (class-ecrm-gdpr.php).
			'<input class="ecrm-input" data-gate autocomplete="off" spellcheck="false" placeholder="' + esc('πληκτρολόγησε: ' + expect) + '"></label>' +
		'</div>';

	var dlg = openDialog({
		eyebrow: o.eyebrow || 'Μη αναστρέψιμη ενέργεια',
		title: o.title,
		lead: o.lead,
		body: body,
		confirm: o.confirm,
		danger: true,
		armed: false,
		onConfirm: function (el, close) {
			var field = el.querySelector('[data-gate]');
			// Δεύτερος έλεγχος τη στιγμή του κλικ, όχι μόνο στο input: ένα
			// `disabled` που αφαιρέθηκε από τα εργαλεία του browser δεν πρέπει
			// να είναι αρκετό για να σβήσει δεδομένα.
			if (!field || field.value !== expect) { return; }
			close();
			o.onConfirm();
		},
	});

	var input = dlg.el.querySelector('[data-gate]');
	var go = dlg.el.querySelector('[data-dlg-confirm]');

	input.addEventListener('input', function () {
		go.disabled = (input.value !== expect);
	});

	// Enter στο πεδίο ισοδυναμεί με το κουμπί — αλλά μόνο όταν ταιριάζει, οπότε
	// δεν είναι το ρεφλεξικό Enter που ήθελε να αποφύγει όλο αυτό.
	input.addEventListener('keydown', function (e) {
		if (e.key === 'Enter' && input.value === expect) { e.preventDefault(); go.click(); }
	});

	return dlg;
}

/**
 * Ίδια πύλη πληκτρολόγησης με το confirmTyped(), ΣΥΝ υποχρεωτική αιτιολογία.
 *
 * Δεν έγινε παράμετρος στο confirmTyped() -- θα άλλαζε τη συμπεριφορά και
 * για τις τρεις υπάρχουσες χρήσεις του (καρτέλα αίτησης, γραμμή λίστας,
 * μαζική διαγραφή), καμία από τις οποίες χρειάζεται αιτιολογία. Ξεχωριστό
 * export, ίδιο σκεπτικό με το γιατί υπάρχει ξεχωριστό `openDialog()`.
 *
 * Μόνη χρήση προς το παρόν: η «ειδική πύλη» admin για διαγραφή σύμβασης που
 * έχει ήδη υπογραφεί (build queue #15) -- εξ ορισμού σπάνια και σοβαρή, οπότε
 * δικαιολογεί βαρύτερη τελετουργία από το confirmTyped() χωρίς να καθιστά
 * τη νέα τελετουργία τον κανόνα παντού αλλού.
 *
 * @param {Object} o
 * @param {string} o.expect       Τι πρέπει να πληκτρολογηθεί, ακριβώς.
 * @param {string} o.expectLabel  Τι λέει η ετικέτα του πεδίου πληκτρολόγησης.
 * @param {string} o.reasonLabel  Τι λέει η ετικέτα του πεδίου αιτιολογίας.
 * @param {function(string)} o.onConfirm  Παίρνει την (κομμένη) αιτιολογία.
 */
export function confirmTypedWithReason(o) {
	var expect = String(o.expect);

	var body =
		'<div class="ecrm-modal__card">' +
			'<label class="ecrm-field"><span class="ecrm-field__label">' + esc(o.expectLabel || 'Επιβεβαίωση') + '</span>' +
			'<input class="ecrm-input" data-gate autocomplete="off" spellcheck="false" placeholder="' + esc('πληκτρολόγησε: ' + expect) + '"></label>' +
			'<label class="ecrm-field"><span class="ecrm-field__label">' + esc(o.reasonLabel || 'Αιτιολογία (υποχρεωτικό)') + '</span>' +
			'<textarea class="ecrm-input" data-reason rows="3"></textarea></label>' +
		'</div>';

	var dlg = openDialog({
		eyebrow: o.eyebrow || 'Μη αναστρέψιμη ενέργεια',
		title: o.title,
		lead: o.lead,
		body: body,
		confirm: o.confirm,
		danger: true,
		armed: false,
		onConfirm: function (el, close) {
			var field  = el.querySelector('[data-gate]');
			var reason = el.querySelector('[data-reason]');
			// Δεύτερος έλεγχος τη στιγμή του κλικ, ίδιο σκεπτικό με το
			// confirmTyped(): ένα `disabled` που αφαιρέθηκε δεν πρέπει να
			// είναι αρκετό.
			if (!field || field.value !== expect) { return; }
			if (!reason || !reason.value.trim()) { return; }
			var trimmed = reason.value.trim();
			close();
			o.onConfirm(trimmed);
		},
	});

	var input  = dlg.el.querySelector('[data-gate]');
	var reason = dlg.el.querySelector('[data-reason]');
	var go     = dlg.el.querySelector('[data-dlg-confirm]');

	function refresh() { go.disabled = (input.value !== expect) || !reason.value.trim(); }

	input.addEventListener('input', refresh);
	reason.addEventListener('input', refresh);

	input.addEventListener('keydown', function (e) {
		if (e.key === 'Enter' && input.value === expect && reason.value.trim()) { e.preventDefault(); go.click(); }
	});

	return dlg;
}
