/* Energy CRM — σε τι κινητό τρέχει η σελίδα, για τον οδηγό «Εφαρμογή στο κινητό» (347).
 *
 * Καθαρή συνάρτηση, χωρίς DOM και χωρίς imports: παίρνει όσα διάβασε ο
 * browser και λέει ποια οδηγία ταιριάζει. Ξεχωριστό αρχείο από την οθόνη
 * για να τρέχει στα τεστ (tools/js-tests/install.test.js) με UA αληθινών
 * κινητών. Τίποτα από αυτά δεν στέλνεται στον server.
 *
 * Τα είδη:
 *   installed   τρέχει ήδη ως εφαρμογή (display-mode: standalone)
 *   android     Android: κουμπί «Εγκατάσταση» ή μενού ⋮ του Chrome
 *   ios-safari  iPhone/iPad στο Safari: κοινοποίηση → Προσθήκη στην αρχική οθόνη
 *   ios-other   iPhone/iPad αλλά ΟΧΙ Safari (Chrome, Firefox, Viber, WhatsApp…):
 *               η εγκατάσταση γίνεται μόνο από το Safari
 *   other       υπολογιστής ή κάτι άγνωστο: δείχνει και τα δύο σετ βημάτων
 */

var IOS_OTHER_BROWSERS = /CriOS|FxiOS|EdgiOS|OPiOS|OPT\/|GSA\/|DuckDuckGo|Brave/;

export function installEnv(input) {
	var ua = String((input && input.ua) || '');
	var platform = String((input && input.platform) || '');
	var touch = Number((input && input.touchPoints) || 0);

	if (input && input.standalone) {
		return { kind: 'installed', os: detectOs(ua, platform, touch) };
	}

	var os = detectOs(ua, platform, touch);

	if (os === 'ios') {
		// Το Safari γράφει «Safari/» στο UA. Οι ενσωματωμένοι browsers του Viber,
		// του WhatsApp και του Facebook όχι, και δεν μπορούν να εγκαταστήσουν.
		var isSafari = /Safari\//.test(ua) && !IOS_OTHER_BROWSERS.test(ua);
		return { kind: isSafari ? 'ios-safari' : 'ios-other', os: os };
	}

	if (os === 'android') {
		return { kind: 'android', os: os };
	}

	return { kind: 'other', os: os };
}

/* Το iPadOS δηλώνεται ως Mac: το ξεχωρίζει μόνο η αφή. */
function detectOs(ua, platform, touch) {
	if (/iPhone|iPad|iPod/.test(ua)) { return 'ios'; }
	if (platform === 'MacIntel' && touch > 1) { return 'ios'; }
	if (/Android/.test(ua)) { return 'android'; }
	return 'other';
}

/* Διαβάζει τον πραγματικό browser. Σε δικό του αρχείο από τη installEnv()
 * για να μη χρειάζονται τα τεστ window. */
export function currentInstallEnv() {
	var standalone = false;
	try {
		standalone = !!(window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
			|| window.navigator.standalone === true;
	} catch (e) {}

	return installEnv({
		ua: navigator.userAgent,
		platform: navigator.platform,
		touchPoints: navigator.maxTouchPoints,
		standalone: standalone,
	});
}
