/**
 * Service worker — μόνο app-shell caching, τίποτα άλλο.
 *
 * ## Τι κάνει και τι ΔΕΝ κάνει (offline commit Α, docs/OFFLINE-MODEL.md §2Α)
 *
 * Κάνει cache ΜΟΝΟ static αρχεία της ίδιας διαδρομής (`/public/assets/*.js`,
 * `*.css`) — ό,τι δεν χρειάζεται δίκτυο για να είναι σωστό. Ποτέ HTML, ποτέ
 * REST (`wp-json`), ποτέ οτιδήποτε εκτός GET. Αυτό είναι σκόπιμο, όχι
 * προσωρινό: δεδομένα πελατών πρέπει να είναι πάντα φρέσκα, και το
 * OFFLINE-MODEL.md βάζει την ουρά εγγραφής (Δ) και το cache διαβάσματος (Ζ)
 * σε ξεχωριστά, μεταγενέστερα commits — αυτό το αρχείο δεν προσπαθεί να τα
 * προλάβει.
 *
 * ## Γιατί site-root scope
 *
 * Το `[energy_crm_app]` shortcode μπορεί να ζει σε ΟΠΟΙΑΔΗΠΟΤΕ σελίδα του
 * site — ο ιδιοκτήτης την επιλέγει, δεν είναι σταθερό URL του plugin. Το
 * μόνο scope που την καλύπτει σίγουρα, όποια κι αν είναι, είναι το site
 * root ("/"). Αυτό ΔΕΝ σημαίνει ότι το SW «κατέχει» όλο το site: το
 * `fetch` handler παρακάτω αγνοεί οτιδήποτε δεν ταιριάζει ρητά με το δικό
 * του asset path — κάθε άλλο αίτημα (άλλα plugins, το wp-admin, το REST,
 * οποιαδήποτε άλλη σελίδα) περνάει ανέγγιχτο στο δίκτυο, σαν να μην υπήρχε
 * καθόλου service worker.
 *
 * ## Γιατί δεν χρειάζεται cache name δεμένο στο ECRM_VERSION
 *
 * Κάθε asset URL κουβαλάει ήδη `?ver=<filemtime>` (βλ.
 * `ECRM_Shortcodes::asset_version()`) — ένα αλλαγμένο αρχείο είναι ήδη
 * αλλαγμένο URL, άρα νέα εγγραφή cache αυτόματα, χωρίς να χρειάζεται αυτό
 * το αρχείο να ξέρει τίποτα για εκδόσεις. Το `CACHE_NAME` παρακάτω αλλάζει
 * ΜΟΝΟ όταν αλλάζει η ΙΔΙΑ η λογική caching εδώ μέσα (σπάνιο, χειροκίνητο) —
 * τότε το `activate` καθαρίζει το παλιό όνομα και ό,τι είχε μέσα.
 *
 * @package EnergyCRM
 */

var CACHE_NAME = 'ecrm-assets-v1';

function isPluginAsset(url) {
	return url.pathname.indexOf('/public/assets/') !== -1
		&& /\.(js|mjs|css)$/.test(url.pathname);
}

self.addEventListener('install', function () {
	// Καμία λίστα προφόρτωσης εδώ: το πρώτο πραγματικό αίτημα κάθε αρχείου
	// είναι που το βάζει στο cache (βλ. fetch παρακάτω). Μια σταθερή λίστα θα
	// έπρεπε να συντηρείται χειροκίνητα δίπλα στο MODULES του
	// class-ecrm-shortcodes.php και θα ξέφευγε από αυτό στην πρώτη προσθήκη
	// module που κάποιος ξεχνά να προσθέσει και εδώ.
	self.skipWaiting();
});

self.addEventListener('activate', function (event) {
	event.waitUntil(
		caches.keys()
			.then(function (names) {
				return Promise.all(
					names.filter(function (n) { return n !== CACHE_NAME; })
						.map(function (n) { return caches.delete(n); })
				);
			})
			.then(function () { return self.clients.claim(); })
	);
});

self.addEventListener('fetch', function (event) {
	var req = event.request;

	// Ποτέ ό,τι δεν είναι GET (POST/PATCH/DELETE ΔΕΝ κρύβονται ποτέ),
	// ποτέ cross-origin, ποτέ ό,τι δεν είναι ρητά δικό μας static asset.
	if (req.method !== 'GET') { return; }

	var url;
	try { url = new URL(req.url); } catch (e) { return; }
	if (url.origin !== self.location.origin) { return; }
	if (!isPluginAsset(url)) { return; }

	event.respondWith(
		caches.open(CACHE_NAME).then(function (cache) {
			return cache.match(req).then(function (cached) {
				var network = fetch(req).then(function (res) {
					if (res && res.ok) { cache.put(req, res.clone()); }
					return res;
				}).catch(function () { return cached; });

				// stale-while-revalidate: άμεση απάντηση από cache αν υπάρχει
				// (γρήγορο άνοιγμα, δουλεύει offline), αλλιώς περιμένει το
				// δίκτυο. Χάρη στο ?ver= στο URL αυτό είναι ήδη σχεδόν πάντα
				// "fresh" -- το revalidate στο παρασκήνιο είναι δίχτυ
				// ασφαλείας, όχι η κύρια άμυνα κατά μπαγιάτικου περιεχομένου.
				return cached || network;
			});
		})
	);
});
