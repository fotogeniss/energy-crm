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
 * ## (313) Άνοιγμα χωρίς σύνδεση: το κέλυφος και ο κατάλογος παρόχων
 *
 * Μέχρι το 312 η εφαρμογή ΔΕΝ άνοιγε offline: το εικονίδιο στο κινητό έβγαζε
 * τη σελίδα «You're offline» του Chrome, γιατί η σελίδα HTML δεν κρατιόταν
 * πουθενά. Ο ιδιοκτήτης (14/10) διάλεξε: «νέα αίτηση offline» -- η εφαρμογή
 * ανοίγει, ο συνεργάτης συμπληρώνει, η αίτηση μπαίνει στην ουρά (Δ) και
 * φεύγει μόλις γυρίσει η σύνδεση. Λίστες συμβάσεων/πελατών ΔΕΝ κρατιούνται
 * (απόφαση Ζ, 11/09, GDPR) και γράφουν «χρειάζεται σύνδεση».
 *
 * Κρατιούνται ΜΟΝΟ δύο πράγματα, στο SHELL_CACHE:
 *
 * 1. Η σελίδα του CRM (navigation, network-first). Μόνο αν η απάντηση είναι
 *    όντως το κέλυφος (`id="ecrm-app"`) -- δηλαδή συνδεδεμένος χρήστης. Δεν
 *    περιέχει στοιχεία πελατών, μόνο όνομα/ρόλο του χρήστη και το nonce. Αν
 *    η ίδια σελίδα επιστρέψει ΧΩΡΙΣ κέλυφος (αποσύνδεση, λήξη session), το
 *    αντίγραφο σβήνεται. Σβήνεται και στην «Έξοδο». Λήγει σε 14 μέρες.
 *    Στο αντίγραφο μπαίνει `data-ecrm-offline-shell` στο <html>, ώστε το
 *    κέλυφος να ξέρει ότι ανοίχτηκε από εδώ και να ξαναφορτώσει (φρέσκο
 *    nonce) μόλις γυρίσει η σύνδεση, ΠΡΙΝ αδειάσει την ουρά.
 * 2. Το GET /ecrm/v1/providers χωρίς παραμέτρους (network-first): ο
 *    κατάλογος παρόχων/προγραμμάτων που χρειάζεται η φόρμα. Καμία πληροφορία
 *    πελάτη.
 *
 * Οτιδήποτε άλλο REST μένει ανέγγιχτο, όπως πριν.
 *
 * @package EnergyCRM
 */

var CACHE_NAME = 'ecrm-assets-v1';
var SHELL_CACHE = 'ecrm-shell-v1';
var SHELL_MAX_AGE = 14 * 24 * 3600 * 1000;
var PROVIDERS_KEY = '/__ecrm/providers';

function isProvidersCall(url) {
	if (url.pathname.replace(/\/$/, '').slice(-'/wp-json/ecrm/v1/providers'.length) === '/wp-json/ecrm/v1/providers') {
		return url.search === '' || /^\?_=\d+$/.test(url.search);
	}
	var route = url.searchParams.get('rest_route');
	if (route && route.replace(/\/$/, '') === '/ecrm/v1/providers') {
		var keys = [];
		url.searchParams.forEach(function (v, k) { keys.push(k); });
		return keys.length === 1;
	}
	return false;
}

function isNoCachePage(url) {
	return url.pathname.indexOf('/wp-admin') !== -1
		|| url.pathname.indexOf('/wp-login.php') !== -1
		|| url.pathname.indexOf('/wp-json') !== -1
		|| url.searchParams.has('rest_route');
}

function shellKey(url) {
	return url.origin + url.pathname;
}

function purgeShell() {
	return caches.delete(SHELL_CACHE);
}

var OFFLINE_HTML = '<!doctype html><html lang="el"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Energy CRM</title>'
	+ '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#1a1a18;color:#f1f1ee;font-family:system-ui,sans-serif;text-align:center;padding:24px}'
	+ 'b{display:block;font-size:20px;margin-bottom:8px}p{color:#a3a099;max-width:320px;line-height:1.5}button{margin-top:16px;font:inherit;font-weight:700;padding:12px 20px;border-radius:12px;border:0;background:#16c217;color:#0b3d0c}</style></head>'
	+ '<body><div><b>Χωρίς σύνδεση</b><p>Η εφαρμογή δεν έχει ανοίξει ακόμα σε αυτό το κινητό με σύνδεση. Άνοιξέ την μία φορά με internet και μετά θα ανοίγει και χωρίς.</p>'
	+ '<button onclick="location.reload()">Δοκίμασε ξανά</button></div></body></html>';

function handleNavigation(req) {
	var url = new URL(req.url);

	// Έξοδος: ό,τι κρατήθηκε για τον χρήστη σβήνεται.
	if (url.pathname.indexOf('/wp-login.php') !== -1 && url.searchParams.get('action') === 'logout') {
		return purgeShell().then(function () { return fetch(req); });
	}

	return fetch(req).then(function (res) {
		var type = (res.headers.get('Content-Type') || '');
		if (!res.ok || type.indexOf('text/html') === -1) { return res; }

		return res.clone().text().then(function (html) {
			return caches.open(SHELL_CACHE).then(function (cache) {
				if (html.indexOf('id="ecrm-app"') === -1) {
					// Η ίδια σελίδα χωρίς κέλυφος = δεν είσαι πια συνδεδεμένος.
					return cache.delete(shellKey(url)).then(function () { return res; });
				}
				var marked = html.replace(/<html\b/i, '<html data-ecrm-offline-shell="1"');
				var copy = new Response(marked, { headers: {
					'Content-Type': 'text/html; charset=utf-8',
					'X-ECRM-Cached-At': String(Date.now())
				} });
				return cache.put(shellKey(url), copy).then(function () { return res; });
			});
		}).catch(function () { return res; });
	}).catch(function () {
		return caches.open(SHELL_CACHE).then(function (cache) {
			return cache.match(shellKey(url)).then(function (hit) {
				var at = hit ? Number(hit.headers.get('X-ECRM-Cached-At') || 0) : 0;
				if (hit && Date.now() - at < SHELL_MAX_AGE) { return hit; }
				return new Response(OFFLINE_HTML, { headers: { 'Content-Type': 'text/html; charset=utf-8' } });
			});
		});
	});
}

function handleProviders(req) {
	return fetch(req).then(function (res) {
		if (res && res.ok) {
			var copy = res.clone();
			caches.open(SHELL_CACHE).then(function (cache) { cache.put(PROVIDERS_KEY, copy); });
		}
		return res;
	}).catch(function () {
		return caches.open(SHELL_CACHE).then(function (cache) {
			return cache.match(PROVIDERS_KEY).then(function (hit) {
				if (hit) { return hit; }
				throw new Error('offline');
			});
		});
	});
}

self.addEventListener('message', function (event) {
	if (event.data && event.data.type === 'ecrm-purge') { event.waitUntil(purgeShell()); }
});

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
					names.filter(function (n) { return n !== CACHE_NAME && n !== SHELL_CACHE; })
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
	if (req.mode === 'navigate' && !isNoCachePage(url)) { event.respondWith(handleNavigation(req)); return; }
	if (req.mode === 'navigate' && url.pathname.indexOf('/wp-login.php') !== -1 && url.searchParams.get('action') === 'logout') {
		event.respondWith(handleNavigation(req)); return;
	}
	if (isProvidersCall(url)) { event.respondWith(handleProviders(req)); return; }
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
