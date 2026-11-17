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
 * ## (407) Βάση Γνώσης και Εκπαίδευση χωρίς σήμα
 *
 * docs/UI-OFFLINE.html. Δύο ακόμη αντίγραφα στο SHELL_CACHE, network-first:
 * το GET /kb χωρίς φίλτρα (όλη η Βάση Γνώσης) και το GET /kb?section=training
 * (τα μαθήματα). Οδηγοί που γράφει ο διαχειριστής, καμία πληροφορία πελάτη.
 * Αναζήτηση/φίλτρα δεν αποθηκεύονται: χωρίς σήμα παίρνουν το αντίγραφο χωρίς
 * φίλτρα, και η οθόνη το λέει (`X-ECRM-Cached-At`, βλ. ecrm-view-kb.js).
 * Σβήνονται μαζί με το κέλυφος: στην «Έξοδο» και όταν η σελίδα έρθει χωρίς
 * κέλυφος (δεν είσαι πια συνδεδεμένος).
 *
 * @package EnergyCRM
 */

var CACHE_NAME = 'ecrm-assets-v1';
var SHELL_CACHE = 'ecrm-shell-v1';
var SHELL_MAX_AGE = 14 * 24 * 3600 * 1000;
var PROVIDERS_KEY = '/__ecrm/providers';
var KB_KEY = '/__ecrm/kb';
var TRAINING_KEY = '/__ecrm/kb-training';

/* (407) Η διαδρομή REST και οι παράμετροί της, και για τα δύο σχήματα URL
 * (/wp-json/... και ?rest_route=...). Το `_` (cache-buster) δεν μετράει. */
function restCall(url) {
	var route = url.searchParams.get('rest_route');
	if (!route) {
		var at = url.pathname.indexOf('/wp-json/');
		if (at === -1) { return null; }
		route = url.pathname.slice(at + '/wp-json'.length);
	}
	var params = {};
	url.searchParams.forEach(function (v, k) {
		if (k !== 'rest_route' && k !== '_') { params[k] = v; }
	});
	return { route: route.replace(/\/$/, ''), params: params };
}

/* null = δεν είναι η Βάση Γνώσης· '' = με φίλτρα (δεν αποθηκεύεται, χωρίς
 * σήμα παίρνει το αντίγραφο χωρίς φίλτρα)· αλλιώς το κλειδί του αντιγράφου. */
function kbKey(url) {
	var call = restCall(url);
	if (!call || call.route !== '/ecrm/v1/kb') { return null; }
	var p = call.params;
	if (p.section === 'training') { return TRAINING_KEY; }
	var bare = !p.q && !p.energy && !p.section && !p.type && (!p.provider || p.provider === '0');
	return bare ? KB_KEY : '';
}

/* Το αντίγραφο κρατά πότε σώθηκε· η οθόνη το δείχνει. */
function saveStamped(key, res) {
	return res.blob().then(function (body) {
		var copy = new Response(body, { headers: {
			'Content-Type': res.headers.get('Content-Type') || 'application/json; charset=utf-8',
			'X-ECRM-Cached-At': String(Date.now())
		} });
		return caches.open(SHELL_CACHE).then(function (cache) { return cache.put(key, copy); });
	});
}

/* Το URL μιας διαδρομής πάνω στη βάση REST, όποιο σχήμα κι αν έχει. */
function restUrl(base, path, query) {
	var join = base.indexOf('?') === -1 ? '?' : '&';
	return base + path + (query ? join + query : '');
}

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
	+ 'b{display:block;font-size:20px;margin-bottom:8px}p{color:#a3a099;max-width:320px;line-height:1.5}a{display:inline-block;margin-top:16px;font-weight:700;padding:12px 20px;border-radius:12px;background:#16c217;color:#0b3d0c;text-decoration:none}</style></head>'
	+ '<body><div><b>Χωρίς σύνδεση</b><p>Η εφαρμογή δεν έχει ανοίξει ακόμα σε αυτό το κινητό με σύνδεση. Άνοιξέ την μία φορά με internet και μετά θα ανοίγει και χωρίς.</p>'
	+ '<a href="">Δοκίμασε ξανά</a></div></body></html>';

/* (313b) Μαζί με το κέλυφος κρατιέται ΚΑΙ ο κατάλογος παρόχων, με το nonce
 * που μόλις τυπώθηκε στη σελίδα. Πριν, η αντιγραφή γινόταν μόνο αν η σελίδα
 * πρόφταινε να ζητήσει /providers (4" μετά το άνοιγμα): στο κινητό του
 * ιδιοκτήτη η εφαρμογή άνοιξε offline αλλά «Δεν φόρτωσαν οι πάροχοι» (15/10). */
function cacheProvidersFrom(html) {
	var rest = /"rest":"([^"]+)"/.exec(html);
	var nonce = /"nonce":"([A-Za-z0-9]+)"/.exec(html);
	if (!rest || !nonce) { return Promise.resolve(); }
	var base = rest[1].replace(/\\\//g, '/').replace(/\/$/, '');
	var opts = { credentials: 'same-origin', headers: { 'X-WP-Nonce': nonce[1] } };
	var providers = fetch(restUrl(base, '/providers', ''), opts)
		.then(function (res) {
			if (!res || !res.ok) { return; }
			return caches.open(SHELL_CACHE).then(function (cache) { return cache.put(PROVIDERS_KEY, res); });
		})
		.catch(function () {});
	// (407) Μαζί και η Βάση Γνώσης και τα μαθήματα, για τον ίδιο λόγο (313b):
	// να υπάρχουν ακόμη κι αν ο συνεργάτης δεν τα άνοιξε ποτέ με σύνδεση.
	var kb = fetch(restUrl(base, '/kb', 'q=&energy=&section=&type=&provider=0'), opts)
		.then(function (res) { if (res && res.ok) { return saveStamped(KB_KEY, res); } })
		.catch(function () {});
	var training = fetch(restUrl(base, '/kb', 'section=training'), opts)
		.then(function (res) { if (res && res.ok) { return saveStamped(TRAINING_KEY, res); } })
		.catch(function () {});
	return Promise.all([providers, kb, training]);
}

/* (407) Network-first. Χωρίς σήμα: το ίδιο αντίγραφο, ή για αναζήτηση/φίλτρα
 * το αντίγραφο χωρίς φίλτρα. Αν δεν σώθηκε ποτέ, η αποτυχία φτάνει στην οθόνη. */
function handleKb(req, key) {
	return fetch(req).then(function (res) {
		if (res && res.ok && key) { saveStamped(key, res.clone()).catch(function () {}); }
		return res;
	}).catch(function () {
		return caches.open(SHELL_CACHE).then(function (cache) {
			return cache.match(key || KB_KEY).then(function (hit) {
				if (hit) { return hit; }
				throw new Error('offline');
			});
		});
	});
}

function handleNavigation(req, event) {
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
					// (407) Φεύγουν μαζί και τα αντίγραφα της Βάσης Γνώσης.
					return Promise.all([
						cache.delete(shellKey(url)), cache.delete(KB_KEY), cache.delete(TRAINING_KEY)
					]).then(function () { return res; });
				}
				var marked = html.replace(/<html\b/i, '<html data-ecrm-offline-shell="1"');
				var copy = new Response(marked, { headers: {
					'Content-Type': 'text/html; charset=utf-8',
					'X-ECRM-Cached-At': String(Date.now())
				} });
				var both = cache.put(shellKey(url), copy).then(function () { return cacheProvidersFrom(html); });
				if (event && event.waitUntil) { event.waitUntil(both); }
				return res;
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
	if (req.mode === 'navigate' && !isNoCachePage(url)) { event.respondWith(handleNavigation(req, event)); return; }
	if (req.mode === 'navigate' && url.pathname.indexOf('/wp-login.php') !== -1 && url.searchParams.get('action') === 'logout') {
		event.respondWith(handleNavigation(req)); return;
	}
	if (isProvidersCall(url)) { event.respondWith(handleProviders(req)); return; }
	var kb = kbKey(url);
	if (kb !== null) { event.respondWith(handleKb(req, kb)); return; }
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
