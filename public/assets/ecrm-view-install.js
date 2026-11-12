/* Energy CRM — «Εφαρμογή στο κινητό» (347).
 *
 * docs/UI-INSTALL-APP.html, εγκρίθηκε 03/11. Το CRM είναι ήδη PWA (manifest,
 * service worker, εικονίδια)· έλειπε ο οδηγός. Στο Android ο Chrome δίνει
 * κουμπί εγκατάστασης (`beforeinstallprompt`), στο iPhone δεν δίνει τίποτα και
 * ο συνεργάτης πρέπει να ξέρει τα τρία πατήματα στο Safari.
 *
 * Όλα στον browser: ποιο κινητό είναι το λέει το ecrm-install-env.js, και
 * τίποτα δεν στέλνεται στον server. Το «×» του banner γράφει μόνο μια
 * προτίμηση εμφάνισης στον browser (ημερομηνία), όχι στοιχείο πελάτη.
 */

import { esc, root, toast, viewEl } from '@energy-crm/util';
import { currentInstallEnv } from '@energy-crm/install-env';
import { qrSvg } from '@energy-crm/qr';

var DISMISS_KEY = 'ecrm_install_banner_until';
var DISMISS_DAYS = 30;
var deferredPrompt = null;
var refreshView = null;

/* Ο Chrome στέλνει το γεγονός νωρίς, πριν ανοίξει η οθόνη, γι' αυτό
 * ακούμε από τη στιγμή που φορτώνει το module και όχι από το άνοιγμά της. */
if (typeof window !== 'undefined' && window.addEventListener) {
	window.addEventListener('beforeinstallprompt', function (e) {
		e.preventDefault();
		deferredPrompt = e;
		if (refreshView) { refreshView(); }
	});
	window.addEventListener('appinstalled', function () {
		deferredPrompt = null;
		hideBanner();
		if (refreshView) { refreshView(); }
	});
}

function pageUrl() {
	// Η σελίδα του CRM, χωρίς #όψη και χωρίς query: να ανοίγει στην αρχή.
	return location.origin + location.pathname;
}

function copyLink() {
	var url = pageUrl();
	var done = function () { toast('Ο σύνδεσμος αντιγράφηκε.'); };
	var fallback = function () {
		try {
			var ta = document.createElement('textarea');
			ta.value = url;
			ta.setAttribute('readonly', '');
			ta.style.position = 'fixed';
			ta.style.top = '-1000px';
			document.body.appendChild(ta);
			ta.select();
			var ok = document.execCommand('copy');
			document.body.removeChild(ta);
			if (ok) { done(); } else { toast('Δεν αντιγράφηκε. Επίλεξέ τον με το χέρι.', false); }
		} catch (e) { toast('Δεν αντιγράφηκε. Επίλεξέ τον με το χέρι.', false); }
	};
	if (navigator.clipboard && window.isSecureContext) {
		navigator.clipboard.writeText(url).then(done, fallback);
	} else {
		fallback();
	}
}

function steps(items) {
	return '<ol class="ecrm-inst__steps">' + items.map(function (s) { return '<li><div>' + s + '</div></li>'; }).join('') + '</ol>';
}

function ico(glyph) { return '<span class="ecrm-inst__ico">' + esc(glyph) + '</span>'; }

var ANDROID_STEPS = function () {
	return steps([
		'Άνοιξε το CRM στο <b>Chrome</b> και συνδέσου.',
		'Πάτα το μενού ' + ico('⋮') + ' πάνω δεξιά.',
		'Διάλεξε <b>«Εγκατάσταση εφαρμογής»</b> (ή «Προσθήκη στην αρχική οθόνη»).',
		'Επιβεβαίωσε. Το εικονίδιο «Energy CRM» εμφανίζεται στην αρχική οθόνη.',
	]);
};

var IOS_STEPS = function () {
	return steps([
		'Πάτα το κουμπί κοινοποίησης ' + ico('⬆︎') + ' κάτω στη μπάρα του Safari.',
		'Κύλησε και διάλεξε <b>«Προσθήκη στην αρχική οθόνη»</b> ' + ico('＋') + '.',
		'Πάτα <b>«Προσθήκη»</b> πάνω δεξιά.',
	]);
};

function status(tone, text) {
	return '<div class="ecrm-inst__status ecrm-inst__status--' + tone + '">' + text + '</div>';
}

function linkBlock(note) {
	return '<div class="ecrm-card"><div class="ecrm-step">Στείλε τον σύνδεσμο στο κινητό σου</div>' +
		'<div class="ecrm-inst__link"><div class="ecrm-inst__url">' + esc(pageUrl()) + '</div>' +
		'<button type="button" class="ecrm-btn" data-install-copy>Αντιγραφή συνδέσμου</button></div>' +
		'<div class="ecrm-muted ecrm-inst__note">' + note + '</div></div>';
}

function body(env, tab) {
	var head = '<div class="ecrm-step">Εφαρμογή στο κινητό</div>';

	if (env.kind === 'installed') {
		return '<div class="ecrm-card">' + head +
			status('ok', '✓ Το CRM τρέχει ως εφαρμογή σε αυτό το κινητό.') +
			'<div class="ecrm-muted ecrm-inst__note">Δεν χρειάζεται τίποτα άλλο. Για να το βάλεις και σε άλλο κινητό, ' +
			'στείλε τον σύνδεσμο από τον υπολογιστή.</div></div>';
	}

	if (env.kind === 'android') {
		var action = deferredPrompt
			? '<button type="button" class="ecrm-btn ecrm-btn--primary ecrm-inst__big" data-install-now>Εγκατάσταση εφαρμογής</button>' +
				'<div class="ecrm-muted ecrm-inst__note">Αν δεν εμφανιστεί παράθυρο: μενού ' + ico('⋮') + ' → «Εγκατάσταση εφαρμογής».</div>'
			: ANDROID_STEPS();
		return '<div class="ecrm-card">' + head +
			status('info', 'Δεν έχει εγκατασταθεί ακόμα σε αυτό το κινητό.') + action + '</div>';
	}

	if (env.kind === 'ios-safari') {
		return '<div class="ecrm-card">' + head +
			status('info', 'Το iPhone δεν επιτρέπει κουμπί. Τρία πατήματα:') + IOS_STEPS() + '</div>';
	}

	if (env.kind === 'ios-other') {
		return '<div class="ecrm-card">' + head +
			status('warn', 'Στο iPhone η εγκατάσταση γίνεται μόνο από το Safari.') +
			'<button type="button" class="ecrm-btn ecrm-btn--primary ecrm-inst__big" data-install-copy>Αντιγραφή συνδέσμου</button>' +
			'<div class="ecrm-muted ecrm-inst__note">Άνοιξε το Safari, πάτησε παρατεταμένα τη γραμμή διεύθυνσης και κάνε ' +
			'«Επικόλληση και μετάβαση». Μετά ακολούθησε τα βήματα.</div></div>' +
			'<div class="ecrm-card"><div class="ecrm-step">Τα βήματα στο Safari</div>' + IOS_STEPS() + '</div>';
	}

	// Υπολογιστής ή άγνωστο: και τα δύο σετ, με εναλλαγή, και ο σύνδεσμος για αποστολή.
	var isIos = tab === 'ios';
	return '<div class="ecrm-card">' + head +
		'<div class="ecrm-muted ecrm-inst__note ecrm-inst__lead">Βάλε το CRM στην αρχική οθόνη του κινητού, σαν εφαρμογή. ' +
		'Ανοίγει χωρίς γραμμή browser και δουλεύει και με κακή σύνδεση.</div>' +
		'<div class="ecrm-inst__tabs">' +
		'<button type="button" class="ecrm-filterchip' + (isIos ? '' : ' is-on') + '" data-install-tab="android">Android</button>' +
		'<button type="button" class="ecrm-filterchip' + (isIos ? ' is-on' : '') + '" data-install-tab="ios">iPhone</button></div>' +
		(isIos ? IOS_STEPS() : ANDROID_STEPS()) + '</div>' +
		linkBlock('Στείλ’ τον στον εαυτό σου ή σε συνεργάτη. Στο iPhone πρέπει να ανοίξει στο Safari.');
}

/* ═══ (368) «Signature»: το κινητό σου ═════════════════════════════════════
 * Αριστερά ένα κινητό με το εικονίδιο του CRM στην αρχική οθόνη· δεξιά τα
 * βήματα σε κάρτες. Από υπολογιστή, QR που σκανάρεις με την κάμερα του
 * κινητού (βγαίνει στον browser από το ecrm-qr.js, όπως η αφίσα του «Ο
 * σύνδεσμός μου»· τίποτα δεν πάει σε τρίτους). Ίδια data-install-* με την
 * κλασική εμφάνιση. docs/UI-HISTORY-INSTALL-FRESH.html, 2Α. */
function isSignature() {
	var app = document.getElementById('ecrm-app');
	return !!app && app.getAttribute('data-ui') === 'signature';
}

function sigSteps(items) {
	return '<ol class="sig-inst__steps">' + items.map(function (s) { return '<li><span>' + s + '</span></li>'; }).join('') + '</ol>';
}

var SIG_ANDROID = [
	'Άνοιξε το CRM στο <b>Chrome</b> και συνδέσου.',
	'Πάτα το μενού ' + ico('⋮') + ' πάνω δεξιά.',
	'Διάλεξε <b>«Εγκατάσταση εφαρμογής»</b> (ή «Προσθήκη στην αρχική οθόνη»).',
	'Επιβεβαίωσε. Το εικονίδιο «Energy CRM» εμφανίζεται στην αρχική οθόνη.',
];
var SIG_IOS = [
	'Άνοιξε το CRM στο <b>Safari</b> και πάτα το κουμπί κοινοποίησης ' + ico('⬆︎') + ' κάτω στη μπάρα.',
	'Κύλησε και διάλεξε <b>«Προσθήκη στην αρχική οθόνη»</b> ' + ico('＋') + '.',
	'Πάτα <b>«Προσθήκη»</b> πάνω δεξιά.',
];

function sigPhone() {
	var apps = ['Ρολόι', 'Χάρτες', 'Κάμερα', 'Φωτογραφίες', 'Energy CRM', 'Viber', 'Mail', 'Ημερολόγιο'];
	return '<div class="sig-inst__phone" aria-hidden="true"><div class="sig-inst__screen">' + apps.map(function (a) {
		return '<span class="sig-inst__app' + (a === 'Energy CRM' ? ' is-me' : '') + '"><i></i>' + esc(a) + '</span>';
	}).join('') + '</div></div>';
}

function sigQr(note) {
	var svg = qrSvg(pageUrl());
	return '<div class="sig-inst__qr">' + (svg ? '<span class="sig-inst__code">' + svg + '</span>' : '') +
		'<div><b>Σκάναρέ το με το κινητό</b><p>' + note + '</p>' +
		'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-install-copy>Αντιγραφή συνδέσμου</button></div></div>';
}

function sigBody(env, tab) {
	var right;
	if (env.kind === 'installed') {
		right = '<div class="sig-inst__ok">✓ Το CRM τρέχει ως εφαρμογή σε αυτό το κινητό.</div>' +
			'<p class="sig-inst__note">Δεν χρειάζεται τίποτα άλλο. Για να το βάλεις και σε άλλο κινητό, άνοιξε αυτή τη σελίδα από υπολογιστή και σκάναρε το QR.</p>';
	} else if (env.kind === 'android') {
		right = deferredPrompt
			? '<button type="button" class="ecrm-btn ecrm-btn--primary sig-inst__big" data-install-now>Εγκατάσταση εφαρμογής</button>' +
				'<p class="sig-inst__note">Αν δεν εμφανιστεί παράθυρο: μενού ' + ico('⋮') + ' → «Εγκατάσταση εφαρμογής».</p>'
			: sigSteps(SIG_ANDROID);
	} else if (env.kind === 'ios-safari') {
		right = '<p class="sig-inst__lead">Το iPhone δεν επιτρέπει κουμπί εγκατάστασης. Τρία πατήματα:</p>' + sigSteps(SIG_IOS);
	} else if (env.kind === 'ios-other') {
		right = '<div class="sig-inst__warn">Στο iPhone η εγκατάσταση γίνεται μόνο από το Safari.</div>' +
			'<button type="button" class="ecrm-btn ecrm-btn--primary sig-inst__big" data-install-copy>Αντιγραφή συνδέσμου</button>' +
			'<p class="sig-inst__note">Άνοιξε το Safari, πάτησε παρατεταμένα τη γραμμή διεύθυνσης και κάνε «Επικόλληση και μετάβαση». Μετά:</p>' +
			sigSteps(SIG_IOS);
	} else {
		// Υπολογιστής ή άγνωστο: Android / iPhone, και το QR για να ανοίξει στο κινητό.
		var isIos = tab === 'ios';
		right = '<div class="sig-inst__seg" role="group" aria-label="Κινητό">' +
				'<button type="button" class="' + (isIos ? '' : 'is-on') + '" data-install-tab="android" aria-pressed="' + !isIos + '">Android</button>' +
				'<button type="button" class="' + (isIos ? 'is-on' : '') + '" data-install-tab="ios" aria-pressed="' + isIos + '">iPhone</button></div>' +
			sigSteps(isIos ? SIG_IOS : SIG_ANDROID) +
			sigQr(isIos
				? 'Άνοιξε την κάμερα και σημάδεψε το τετράγωνο. Στο iPhone πρέπει να ανοίξει στο Safari· μετά ακολούθησε τα βήματα.'
				: 'Άνοιξε την κάμερα και σημάδεψε το τετράγωνο. Ανοίγει το CRM· μετά ακολούθησε τα βήματα.');
	}

	return '<header class="sig-cuhead"><div><h2>Εφαρμογή στο κινητό</h2>' +
			'<p>Το CRM μπαίνει στην αρχική οθόνη του κινητού και ανοίγει σαν εφαρμογή, χωρίς γραμμή browser.</p></div></header>' +
		'<div class="sig-inst">' + sigPhone() + '<div class="sig-inst__main">' + right + '</div></div>';
}

export function loadInstall() {
	var view = viewEl('install');
	if (!view) { return; }

	var tab = 'android';

	var render = function () {
		var env = currentInstallEnv();
		view.innerHTML = isSignature() ? sigBody(env, tab) :
			'<header class="ecrm-head"><h2 class="ecrm-title">Εφαρμογή στο κινητό</h2>' +
			'<p class="ecrm-sub">Το CRM μπαίνει στην αρχική οθόνη του κινητού και ανοίγει σαν εφαρμογή.</p></header>' +
			body(env, tab);

		view.querySelectorAll('[data-install-tab]').forEach(function (b) {
			b.addEventListener('click', function () { tab = this.getAttribute('data-install-tab'); render(); });
		});
		view.querySelectorAll('[data-install-copy]').forEach(function (b) { b.addEventListener('click', copyLink); });

		var now = view.querySelector('[data-install-now]');
		if (now) {
			now.addEventListener('click', function () {
				if (!deferredPrompt) { return; }
				var p = deferredPrompt;
				deferredPrompt = null; // ο Chrome το επιτρέπει μία φορά
				p.prompt();
				if (p.userChoice && p.userChoice.then) {
					p.userChoice.then(function () { render(); });
				}
			});
		}
	};

	refreshView = render;
	render();
}

/* ---- Banner πάνω από την Αρχική (μόνο κινητό, μόνο όταν δεν είναι εγκατεστημένο) ---- */

function dismissedUntil() {
	try { return Number(localStorage.getItem(DISMISS_KEY)) || 0; } catch (e) { return 0; }
}

function hideBanner() {
	var el = root() && root().querySelector('[data-install-banner]');
	if (el) { el.hidden = true; }
}

export function initInstallBanner() {
	var app = root();
	var el = app && app.querySelector('[data-install-banner]');
	if (!el) { return; }

	var small = window.matchMedia && window.matchMedia('(max-width: 860px)').matches;
	var env = currentInstallEnv();

	if (!small || env.kind === 'installed' || env.os === 'other') { return; }
	if (dismissedUntil() > Date.now()) { return; }

	el.hidden = false;

	var close = el.querySelector('[data-install-dismiss]');
	if (close) {
		close.addEventListener('click', function () {
			el.hidden = true;
			try { localStorage.setItem(DISMISS_KEY, String(Date.now() + DISMISS_DAYS * 86400000)); } catch (e) {}
		});
	}
}
