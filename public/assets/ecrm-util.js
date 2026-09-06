/* Energy CRM — the helpers every screen needs, in one copy.
 *
 * These three lived three times each: esc() in all of ecrm-app.js,
 * ecrm-form.js and ecrm-litsa.js, api() in all three, toast() byte-identical
 * in two. Copies drift, and one of them drifting is not a cosmetic problem —
 * FrontendEscapingTest had to assert that all three esc() bodies still covered
 * the same characters, which is a guard papering over a duplication rather
 * than a duplication being fixed.
 *
 * A native ES module, deliberately: no bundler, no node_modules, no build
 * artifact, and therefore no way to ship a stale one. The browser resolves the
 * import; WordPress never needs to know this file exists.
 *
 * One consequence worth knowing: an imported module gets no `?ver=` from
 * WordPress, so cache-busting here relies on the server's Last-Modified /
 * ETag revalidation rather than on a changed query string.
 */

/**
 * The only thing standing between a customer's name and the DOM.
 *
 * Covers & < > " — right for text and for double-quoted attributes. It is NOT
 * enough for an unquoted attribute, a javascript: URL, or anything inside a
 * <script> or a style=. No such site exists in this codebase, and
 * FrontendEscapingTest is what keeps it that way.
 */
export function esc(s) {
	return (s == null ? '' : String(s)).replace(/[&<>"]/g, function (c) {
		return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
	});
}

/** Absolute URL for a REST path, from the base wp_localize_script handed us. */
export function api(path) {
	return ECRM.rest.replace(/\/$/, '') + path;
}

/** The nonce header every write needs. */
export function H() {
	return { 'X-WP-Nonce': ECRM.nonce };
}

/**
 * Hiding a control the user may not use.
 *
 * Presentation only. Every one of these is enforced again on the server,
 * because anything that reaches the browser is a suggestion.
 */
export function can(capability) {
	return !!(ECRM.caps && ECRM.caps[capability]);
}

/**
 * Never serve our own API responses from the browser or proxy cache.
 *
 * An agent who changes a contract's status and clicks back to the list must
 * see the new status, not the one the browser kept. Only our REST base is
 * touched; everything else passes through untouched, which is why this can
 * safely shadow the global fetch in the modules that import it.
 *
 * It lived in the app shell and moved here when the views started splitting
 * out - each one needs it, and a second copy would be a second thing to keep
 * in step. ecrm-form.js and ecrm-litsa.js still call the global fetch; giving
 * them this one is a behaviour change and wants its own commit.
 */
var _origFetch = window.fetch.bind(window);

export function fetch(url, opts) {
	opts = opts || {};

	try {
		var base = ECRM.rest.replace(/\/$/, '');

		if (typeof url === 'string' && url.indexOf(base) === 0) {
			opts.cache = 'no-store';
			opts.headers = Object.assign({ 'Cache-Control': 'no-cache', 'Pragma': 'no-cache' }, opts.headers || {});
		}
	} catch (e) {}

	return _origFetch(url, opts);
}

/**
 * The container a view renders into.
 *
 * Here rather than passed in from the router: a view module that looks up its
 * own container needs nothing from the shell but the call, which is what keeps
 * the extraction of the next one a move rather than a rewrite.
 */
export function viewEl(name) {
	return document.querySelector('#ecrm-app .ecrm-view[data-view="' + name + '"]');
}

/**
 * The application root, for the few things that are not inside a view.
 *
 * An overlay or a toast belongs to the app, not to whichever screen happened
 * to open it — appending to a view would take it down with the next render.
 */
export function root() {
	return document.getElementById('ecrm-app');
}

/**
 * Κάνει ένα πάτημα του πραγματικού κουμπιού "πίσω" του browser να κλείνει μια
 * υπο-όψη (καρτέλα σύμβασης, καρτέλα πελάτη) αντί να προσπερνά τη λίστα από
 * κάτω και να πηδά σε ό,τι όψη ήταν ενεργή πριν από αυτήν.
 *
 * Το go('contract-detail')/go('customer-detail') δεν αγγίζει καθόλου το
 * hash/ιστορικό -- τα ονόματα αυτά δεν είναι στο VIEWS του ecrm-app.js (δεν
 * έχουν δικό τους URL, δεν παίρνουν id σε refresh). Αυτό σημαίνει ότι το
 * πραγματικό ιστορικό του browser ΔΕΝ ξέρει καν ότι ανοίξαμε μια καρτέλα --
 * παραμένει στην εγγραφή της λίστας (π.χ. #contracts) από πριν. Πατώντας
 * back, ο browser προσπερνά λοιπόν τη λίστα (αφού δεν άλλαξε ποτέ hash) και
 * πάει κατευθείαν στην ΠΡΟΗΓΟΥΜΕΝΗ κορυφαία όψη -- π.χ. το Dashboard --
 * επιβεβαιωμένο live 06/09: Συμβάσεις -> άνοιγμα σύμβασης -> back του browser
 * -> Αρχική, όχι Συμβάσεις.
 *
 * Η διόρθωση: προσθέτουμε ΕΜΕΙΣ μια εγγραφή ιστορικού με το ΙΔΙΟ hash τη
 * στιγμή που ανοίγει η καρτέλα. Δεν αλλάζει τίποτα οπτικά (το hash μένει το
 * ίδιο, δεν πυροδοτείται popstate από το ίδιο το pushState). Ενα back του
 * browser μετά καταναλώνει ΑΥΤΗ την εγγραφή -- ίδιο hash, το popstate του
 * ecrm-app.js ξαναδιαβάζει το ίδιο hash και ξανακάνει go() στη λίστα, δηλαδή
 * ο χρήστης βλέπει ακριβώς τη λίστα που άφησε. Ενα δεύτερο back προχωράει
 * κανονικά παραπέρα, σαν να μην υπήρχε καν η καρτέλα.
 */
export function markSubViewOpen() {
	try { history.pushState({}, '', location.hash || location.pathname); } catch (e) {}
}

/**
 * The one-line message at the bottom of the screen.
 *
 * Creates its own node on first use, so no view has to remember to render a
 * container it does not otherwise care about.
 */
/*
 * Τα αρχεία που δεν πέρασαν, σε μία πρόταση.
 *
 * Ο server στέλνει λόγο ανά αρχείο. Χωρίς αυτό ο συνεργάτης έβλεπε
 * «Προστέθηκαν 0 έγγραφα» και δεν μάθαινε ποτέ γιατί.
 */
export function rejectedNote(list) {
	if (!list || !list.length) { return ''; }
	return list.map(function (r) {
		var name = (r && r.filename) || 'Το αρχείο';
		return name + ': ' + ((r && r.reason) || 'δεν έγινε δεκτό.');
	}).join(' — ');
}

export function toast(msg, ok) {
	var t = document.getElementById('ecrm-toast');

	if (!t) {
		t = document.createElement('div');
		t.id = 'ecrm-toast';
		t.className = 'ecrm-toast';
		document.body.appendChild(t);
	}

	t.textContent = msg;
	t.className = 'ecrm-toast is-show ' + (ok === false ? 'is-err' : 'is-ok');
	setTimeout(function () { t.className = 'ecrm-toast'; }, 4000);
}
