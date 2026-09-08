import { api, fetch, H } from '@energy-crm/util';

/**
 * Η ουρά εγγραφής — δημιουργία σύμβασης που επιβιώνει χωρίς δίκτυο.
 *
 * ## Τι λύνει, και γιατί όχι μόνο για offline
 *
 * Η ροή νέας σύμβασης είναι ΔΥΟ αιτήματα, όχι ένα: `POST /contracts` και μετά
 * `POST /contracts/{id}/files`. Το status ταξιδεύει ΜΕΣΑ στο πρώτο (το
 * `collect(status)` του ecrm-form.js), οπότε η αλυσίδα του §2Δ είναι
 * «δημιουργία → αρχεία» και όχι «δημιουργία → αρχεία → status».
 *
 * Πριν από αυτό το αρχείο, το δεύτερο αίτημα ήταν fire-and-forget: αν έσπαγε,
 * ένα toast και τέλος. Η σύμβαση είχε ήδη γραφτεί. Ενα κενό δικτύου δύο
 * δευτερολέπτων ανάμεσα στα δύο άφηνε **σύμβαση χωρίς έγγραφα** και η
 * φωτογραφία της ταυτότητας χανόταν οριστικά -- online, σήμερα, χωρίς κανένα
 * offline σενάριο. Η ουρά διορθώνει πρώτα αυτό.
 *
 * ## Γιατί το κλειδί είναι το request_id του Επιπέδου Γ
 *
 * Το `id` κάθε εγγραφής ΕΙΝΑΙ το `client_request_id` που ήδη ταξιδεύει στο
 * payload (259). Δεν είναι σύμπτωση ούτε οικονομία: η εγγραφή της ουράς και η
 * «πρόθεση δημιουργίας» είναι το ίδιο πράγμα, και έτσι κάθε επανάληψη της
 * ουράς προστατεύεται από το UNIQUE της βάσης χωρίς μία γραμμή επιπλέον. Αν
 * μια αποστολή πετύχει στον server αλλά χαθεί η απάντηση, η επόμενη
 * προσπάθεια παίρνει πίσω την ΑΡΧΙΚΗ σύμβαση με `replayed: true` -- ποτέ
 * δεύτερη. Χωρίς το (Γ), αυτό το αρχείο θα ήταν μηχανή παραγωγής διπλών
 * συμβάσεων.
 *
 * ## Γιατί IndexedDB, και τι αναιρεί
 *
 * Απόφαση ιδιοκτήτη 08/09, με πλήρη γνώση του τι ανατρέπει. Το build queue
 * #14 είχε ΑΠΟΣΥΡΕΙ την πρόταση για τοπικό πρόχειρο ακριβώς επειδή θα έγραφε
 * ΑΦΜ/ΑΔΤ/διεύθυνση σε δίσκο κινητού, και υλοποιήθηκε πρόχειρο στον server.
 * Εδώ γράφονται και φωτογραφίες ταυτοτήτων. Ο λόγος που υπερίσχυσε: η
 * εναλλακτική (ουρά μόνο στη μνήμη) χάνει τα πάντα όταν το λειτουργικό
 * σκοτώσει την καρτέλα στο παρασκήνιο -- κάτι που σε tablet με λίγη μνήμη
 * συμβαίνει χωρίς προειδοποίηση και χωρίς ίχνος.
 *
 * Επειδή ακριβώς αναιρεί εκείνη την απόφαση, η λήξη ΔΕΝ είναι επόμενο βήμα:
 * το TTL, το όριο μεγέθους και το όριο πλήθους είναι σε αυτό το ίδιο αρχείο.
 * Δεν γράφονται ταυτότητες σε δίσκο χωρίς ημερομηνία λήξης.
 *
 * ## Τι ΔΕΝ κάνει
 *
 * - Δεν βάζει στην ουρά ενημερώσεις υπάρχουσας σύμβασης, `/extract`,
 *   `/customers/check` ή οτιδήποτε άλλο. Μόνο τη ροή νέας σύμβασης, όπως
 *   ορίζει ρητά το §2Δ.
 * - Δεν κάνει ατέρμονο retry (§2Δ): μετά από MAX_ATTEMPTS αποτυχίες δικτύου
 *   σταματά μόνη της και περιμένει ρητή ενέργεια του συνεργάτη.
 * - Δεν κρύβει αποτυχία. Ο,τι ο server αρνήθηκε μένει ορατό με το μήνυμά του.
 *
 * @package EnergyCRM
 */

var DB_NAME = 'ecrm-offline-queue';
var DB_VERSION = 1;
var STORE = 'pending';

/* 48 ώρες, όπως προτείνει το §2Ε. Το νούμερο δεν είναι αυθαίρετο: καλύπτει
 * Σαββατοκύριακο με το tablet κλειστό, και δεν αφήνει ταυτότητα σε συσκευή
 * για εβδομάδα. */
export var TTL_MS = 48 * 60 * 60 * 1000;

/* Ορια. Το πλήθος είναι ο συνεργάτης που δουλεύει μια μέρα χωρίς σήμα· τα
 * bytes είναι ο φραγμός που μετράει στην πράξη, γιατί μια φωτογραφία
 * ταυτότητας από κάμερα κινητού είναι 2-5MB και δέκα από αυτές γεμίζουν την
 * quota του browser πολύ πριν φτάσουμε στις είκοσι συμβάσεις. */
export var MAX_RECORDS = 20;
export var MAX_BYTES = 40 * 1024 * 1024;

/* Πόσες αποτυχίες ΔΙΚΤΥΟΥ πριν σταματήσει η αυτόματη προσπάθεια. Το «Στείλε
 * τώρα» το μηδενίζει -- η φραγή είναι κατά του ατέρμονου βρόχου, όχι κατά του
 * χρήστη. */
export var MAX_ATTEMPTS = 5;

var _db = null;
var _running = false;
var _listeners = [];

function currentUserId() {
	try { return (typeof ECRM !== 'undefined' && ECRM.userId) ? ECRM.userId : 0; } catch (e) { return 0; }
}

function openDb() {
	if (_db) { return Promise.resolve(_db); }

	return new Promise(function (resolve, reject) {
		var req = indexedDB.open(DB_NAME, DB_VERSION);

		req.onupgradeneeded = function () {
			/* Κανένα index: με MAX_RECORDS στα είκοσι, ένα πλήρες πέρασμα
			 * είναι φθηνότερο από τη συντήρηση ευρετηρίου -- και ένα index
			 * λιγότερο είναι ένα migration λιγότερο την επόμενη φορά. */
			if (!req.result.objectStoreNames.contains(STORE)) {
				req.result.createObjectStore(STORE, { keyPath: 'id' });
			}
		};

		req.onsuccess = function () { _db = req.result; resolve(_db); };
		req.onerror = function () { reject(req.error); };
	});
}

function tx(mode, fn) {
	return openDb().then(function (db) {
		return new Promise(function (resolve, reject) {
			var t = db.transaction(STORE, mode);
			var out;

			t.oncomplete = function () { resolve(out); };
			t.onerror = function () { reject(t.error); };
			t.onabort = function () { reject(t.error); };

			out = fn(t.objectStore(STORE), function (v) { out = v; });
		});
	});
}

function readAll() {
	return tx('readonly', function (store, done) {
		var req = store.getAll();
		req.onsuccess = function () { done(req.result || []); };
	});
}

function put(record) {
	return tx('readwrite', function (store) { store.put(record); });
}

function drop(id) {
	return tx('readwrite', function (store) { store.delete(id); });
}

function notify() {
	count().then(function (n) {
		_listeners.forEach(function (fn) { try { fn(n); } catch (e) {} });
	}).catch(function () {});
}

/** Εγγραφές ΑΥΤΟΥ του χρήστη που δεν έχουν λήξει. */
function mine(all, now) {
	var uid = currentUserId();

	return all.filter(function (r) {
		return r.userId === uid && (now - r.createdAt) < TTL_MS;
	});
}

function bytesOf(files) {
	return (files || []).reduce(function (sum, f) {
		return sum + ((f.blob && f.blob.size) || 0);
	}, 0);
}

/**
 * Σβήνει ό,τι έληξε -- ΚΑΘΕ χρήστη, όχι μόνο του τρέχοντος.
 *
 * Το §2Ε ζητά και «καθάρισμα στο logout». Δεν υλοποιείται έτσι, και ο λόγος
 * αξίζει να γραφτεί: όταν ο χρήστης αποσυνδεθεί, το shortcode επιστρέφει την
 * πύλη «Συνδέσου» και αυτό το module δεν φορτώνεται καν -- δεν υπάρχει
 * στιγμή να τρέξει κώδικας καθαρισμού. Το TTL είναι η μόνη εγγύηση που δεν
 * εξαρτάται από το αν θα ξαναφορτώσει ποτέ κάτι.
 *
 * Οι εγγραφές ΑΛΛΟΥ χρήστη (κοινόχρηστο tablet) δεν σβήνονται πρόωρα: θα ήταν
 * καταστροφή της αστάλτης δουλειάς του, και αν ξανασυνδεθεί μέσα στο TTL
 * φεύγουν κανονικά. Απλώς δεν στέλνονται ΠΟΤΕ όσο είναι συνδεδεμένος άλλος --
 * θα δημιουργούσαν σύμβαση στο όνομα λάθος συνεργάτη.
 *
 * @return {Promise<number>} πόσες έληξαν και σβήστηκαν.
 */
export function purgeExpired() {
	var now = Date.now();

	return readAll().then(function (all) {
		var dead = all.filter(function (r) { return (now - r.createdAt) >= TTL_MS; });

		return Promise.all(dead.map(function (r) { return drop(r.id); }))
			.then(function () { if (dead.length) { notify(); } return dead.length; });
	}).catch(function () {
		/* Το IndexedDB μπορεί να μην υπάρχει καθόλου (ιδιωτική περιήγηση σε
		 * κάποιους browsers). Δεν είναι λόγος να μη φορτώσει η εφαρμογή: το
		 * μόνο που χάνεται είναι η ουρά, και η φόρμα το λέει τίμια τη στιγμή
		 * που θα χρειαζόταν. Επιστρέφεται 0 ώστε ο καλών να συνεχίσει. */
		return 0;
	});
}

/** Πόσες δικές μας εκκρεμούν. */
export function count() {
	var now = Date.now();

	return readAll().then(function (all) { return mine(all, now).length; }).catch(function () { return 0; });
}

/** Ολες οι δικές μας, για την οθόνη. */
export function pending() {
	var now = Date.now();

	return readAll().then(function (all) { return mine(all, now); }).catch(function () { return []; });
}

export function onChange(fn) {
	_listeners.push(fn);
}

/**
 * Βάζει στην ουρά μια δημιουργία σύμβασης μαζί με τα αρχεία της.
 *
 * Το `payload.client_request_id` γίνεται το κλειδί, οπότε δεύτερη κλήση με το
 * ίδιο id ΑΝΤΙΚΑΘΙΣΤΑ την προηγούμενη αντί να προσθέτει δεύτερη: ο συνεργάτης
 * που διόρθωσε ένα πεδίο και ξαναπάτησε αποθήκευση offline εξακολουθεί να
 * έχει μία αίτηση σε αναμονή, με τα τελευταία στοιχεία.
 *
 * @return {Promise<{ok: boolean, reason?: string}>}
 */
export function enqueueContract(payload, files) {
	var id = payload && payload.client_request_id;

	if (!id) {
		return Promise.resolve({ ok: false, reason: 'Η αίτηση δεν έχει αναγνωριστικό — δεν μπορεί να μπει σε ουρά.' });
	}

	var wanted = (files || []).map(function (item) {
		return { blob: item.file, kind: item.kind, name: item.file.name };
	});

	return readAll().then(function (all) {
		var now   = Date.now();
		var ours  = mine(all, now);
		var other = ours.filter(function (r) { return r.id !== id; });

		if (other.length >= MAX_RECORDS) {
			return { ok: false, reason: 'Η ουρά είναι γεμάτη (' + MAX_RECORDS + ' αιτήσεις). Συνδέσου για να σταλούν.' };
		}

		var used = other.reduce(function (sum, r) { return sum + bytesOf(r.files); }, 0);

		if ((used + bytesOf(wanted)) > MAX_BYTES) {
			return { ok: false, reason: 'Δεν χωρούν άλλα αρχεία εκτός σύνδεσης. Συνδέσου για να σταλούν όσα περιμένουν.' };
		}

		return put({
			id: id,
			userId: currentUserId(),
			createdAt: now,
			payload: payload,
			files: wanted,
			contractId: 0,
			attempts: 0,
			blocked: false,
			lastError: ''
		}).then(function () { notify(); return { ok: true }; });
	}).catch(function () {
		/* Η quota του browser γέμισε ή το IndexedDB δεν είναι διαθέσιμο
		 * (ιδιωτική περιήγηση σε κάποιους browsers). Τίμια άρνηση: ο
		 * συνεργάτης πρέπει να ξέρει ότι ΔΕΝ κρατήθηκε τίποτα. */
		return { ok: false, reason: 'Δεν ήταν δυνατή η τοπική αποθήκευση της αίτησης.' };
	});
}

/**
 * Η μισή περίπτωση: η σύμβαση γράφτηκε, τα αρχεία δεν έφυγαν.
 *
 * Αυτή είναι η σιωπηλή απώλεια που περιγράφεται στην κορυφή του αρχείου, και
 * ο λόγος που η ουρά κρατά `contractId` αντί να ξεκινά πάντα από το μηδέν.
 */
export function enqueueFiles(contractId, files, id) {
	if (!contractId || !(files || []).length || !id) { return Promise.resolve({ ok: false }); }

	var wanted = files.map(function (item) {
		return { blob: item.file, kind: item.kind, name: item.file.name };
	});

	return put({
		id: id,
		userId: currentUserId(),
		createdAt: Date.now(),
		payload: null,
		files: wanted,
		contractId: contractId,
		attempts: 0,
		blocked: false,
		lastError: ''
	}).then(function () { notify(); return { ok: true }; })
		.catch(function () { return { ok: false, reason: 'Δεν ήταν δυνατή η τοπική αποθήκευση των εγγράφων.' }; });
}

function isAuth(status) {
	return status === 401 || status === 403;
}

/** Στέλνει μία εγγραφή. Επιστρέφει 'sent' | 'kept' | 'blocked' | 'auth'. */
function send(record) {
	var chain = Promise.resolve();

	if (record.contractId === 0) {
		chain = fetch(api('/contracts'), {
			method: 'POST',
			headers: Object.assign({ 'Content-Type': 'application/json' }, H()),
			body: JSON.stringify(record.payload)
		}).then(function (r) {
			if (isAuth(r.status)) { return 'auth'; }

			return r.json().then(function (d) {
				if (!d || !d.ok) {
					/* Ο server ΑΡΝΗΘΗΚΕ -- λάθος ΑΦΜ, λάθος email, ό,τι δεν
					 * μπόρεσε να ελεγχθεί offline. Καμία επανάληψη δεν θα το
					 * αλλάξει, οπότε σταματά εδώ και μένει ορατό με το μήνυμα
					 * του server. Δεν σβήνεται: θα ήταν σιωπηλή απώλεια της
					 * δουλειάς του συνεργάτη. */
					record.blocked = true;
					record.lastError = (d && d.error) || 'Η αίτηση απορρίφθηκε.';

					return put(record).then(function () { return 'blocked'; });
				}

				record.contractId = d.contract_id;

				/* Γράφεται ΠΡΙΝ ξεκινήσουν τα αρχεία: αν σκάσει η καρτέλα
				 * ενδιάμεσα, η επόμενη προσπάθεια συνεχίζει από τα αρχεία
				 * αντί να ξαναζητήσει δημιουργία. (Και αν ξαναζητούσε, το
				 * κλειδί του (Γ) θα επέστρεφε την ίδια σύμβαση -- αυτό είναι
				 * ζώνη ασφαλείας, όχι δικαιολογία για να μη γραφτεί.) */
				return put(record).then(function () { return 'created'; });
			});
		});
	}

	return chain.then(function (early) {
		if (early === 'auth' || early === 'blocked') { return early; }

		if (!record.files.length) { return drop(record.id).then(function () { return 'sent'; }); }

		var fd = new FormData();

		record.files.forEach(function (f) {
			fd.append('files[]', f.blob, f.name);
			fd.append('kinds[]', f.kind);
		});

		return fetch(api('/contracts/' + record.contractId + '/files'), {
			method: 'POST',
			headers: H(),
			body: fd
		}).then(function (r) {
			if (isAuth(r.status)) { return 'auth'; }

			return r.json().then(function (d) {
				if (!d || !d.ok) {
					record.blocked = true;
					record.lastError = (d && d.error) || 'Τα έγγραφα απορρίφθηκαν.';

					return put(record).then(function () { return 'blocked'; });
				}

				return drop(record.id).then(function () { return 'sent'; });
			});
		});
	}).catch(function () {
		/* Σφάλμα δικτύου -- ούτε άρνηση ούτε επιτυχία. Η εγγραφή μένει,
		 * ο μετρητής ανεβαίνει, και μετά το MAX_ATTEMPTS σταματά η αυτόματη
		 * προσπάθεια ώστε να μη γίνει ατέρμονος βρόχος (§2Δ). */
		record.attempts = (record.attempts || 0) + 1;

		return put(record).then(function () { return 'kept'; });
	});
}

/**
 * Προσπαθεί να αδειάσει την ουρά, σειριακά.
 *
 * Σειριακά και όχι παράλληλα επίτηδες: ο συνεργάτης σε 3G που μόλις ξαναβρήκε
 * σήμα δεν κερδίζει τίποτα από δέκα ταυτόχρονα uploads φωτογραφιών, και τα
 * χάνει όλα μαζί αν πέσει πάλι.
 *
 * @return {Promise<{sent: number, kept: number, blocked: number, auth: boolean}>}
 */
export function flush() {
	var report = { sent: 0, kept: 0, blocked: 0, auth: false };

	if (_running) { return Promise.resolve(report); }
	if (typeof navigator !== 'undefined' && navigator.onLine === false) { return Promise.resolve(report); }

	_running = true;

	return pending().then(function (all) {
		var ready = all.filter(function (r) {
			return !r.blocked && (r.attempts || 0) < MAX_ATTEMPTS;
		}).sort(function (a, b) { return a.createdAt - b.createdAt; });

		return ready.reduce(function (chain, record) {
			return chain.then(function () {
				if (report.auth) { return null; }

				return send(record).then(function (result) {
					if (result === 'auth') { report.auth = true; }
					else if (result === 'sent') { report.sent++; }
					else if (result === 'blocked') { report.blocked++; }
					else { report.kept++; }
				});
			});
		}, Promise.resolve());
	}).then(function () {
		_running = false;
		notify();

		return report;
	}).catch(function () {
		_running = false;

		return report;
	});
}

/**
 * «Στείλε τώρα»: μηδενίζει τους μετρητές αποτυχιών και ξαναδοκιμάζει.
 *
 * Δεν ξεμπλοκάρει ό,τι ΑΡΝΗΘΗΚΕ ο server -- εκείνο θα ξαναπάρει την ίδια
 * άρνηση και το μόνο που θα άλλαζε είναι ότι ο συνεργάτης θα νόμιζε πως κάτι
 * κάνει.
 */
export function retryNow() {
	return pending().then(function (all) {
		var stalled = all.filter(function (r) { return !r.blocked && (r.attempts || 0) > 0; });

		return Promise.all(stalled.map(function (r) { r.attempts = 0; return put(r); }));
	}).then(flush);
}

/**
 * Ρητή διαγραφή: από τον συνεργάτη για ό,τι μπλόκαρε, ή από τη φόρμα όταν
 * μια απευθείας αποθήκευση πέτυχε και παίρνει πίσω την κυριότητα.
 *
 * Ανεκτική σε κενό κλειδί επίτηδες: η φόρμα την καλεί και σε ενημέρωση
 * υπάρχουσας σύμβασης, όπου `client_request_id` δεν υπάρχει καθόλου (το
 * (259) το στέλνει μόνο σε δημιουργία). Ο έλεγχος εδώ αντί για `if` σε κάθε
 * καλούντα.
 */
export function forget(id) {
	if (!id) { return Promise.resolve(); }

	return drop(id).then(function () { notify(); }).catch(function () {});
}
