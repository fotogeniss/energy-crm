/* Ο έλεγχος της ουράς εγγραφής (docs/OFFLINE-MODEL.md §2Δ, §2Ε).
 *
 * Τρέχει με ΠΡΑΓΜΑΤΙΚΟ IndexedDB (fake-indexeddb: πλήρης υλοποίηση της
 * προδιαγραφής σε μνήμη, όχι mock με δύο μεθόδους) και ψεύτικο δίκτυο. Ο
 * λόγος που δεν φτάνει ένα mock: τα μισά από όσα μπορούν να πάνε στραβά εδώ
 * είναι της αποθήκευσης -- keyPath που αντικαθιστά αντί να προσθέτει,
 * συναλλαγή που κλείνει πριν προλάβει το γράψιμο, Blob που δεν επιβιώνει το
 * structured clone.
 *
 * Τρέξε το με:  npm test
 */
require('fake-indexeddb/auto');

const { strict: assert } = require('assert');
const path = require('path');

const Q = require(path.join(__dirname, 'queue.js'));

let fails = 0, passes = 0;

function ok(name, cond, extra) {
	if (cond) { passes++; console.log('  ✓ ' + name); }
	else { fails++; console.log('  ✗ ' + name + (extra ? '  → ' + extra : '')); }
}

/* ---- ψεύτικο περιβάλλον ------------------------------------------------ */

global.ECRM = { rest: 'http://x/wp-json/ecrm/v1', nonce: 'n', userId: 7 };
global.navigator = { onLine: true };
global.FormData = class {
	constructor() { this.parts = []; }
	append(k, v, n) { this.parts.push([k, v, n]); }
};

/* Blob-άκι με μέγεθος και όνομα, όσο χρειάζεται η ουρά. */
function fileOf(name, size) {
	const b = new Blob([new Uint8Array(size)]);
	b.name = name;

	return b;
}

let calls = [];
let responder = null;

globalThis.__fetch = function (url, opts) {
	calls.push({ url, opts });

	return responder(url, opts);
};

function jsonOnce(status, body) {
	return function () {
		return Promise.resolve({ status: status, json: function () { return Promise.resolve(body); } });
	};
}

function netFail() {
	return function () { return Promise.reject(new Error('offline')); };
}

async function wipe() {
	const all = await Q.pending();

	await Promise.all(all.map(function (r) { return Q.forget(r.id); }));
}

/**
 * Διαβάζει ΑΠΕΥΘΕΙΑΣ από το IndexedDB, παρακάμπτοντας την ουρά.
 *
 * Χρειάζεται για ένα μόνο πράγμα, και είναι το πιο σημαντικό του αρχείου: να
 * απαντηθεί «τι θα έβρισκε η επόμενη φόρτωση της σελίδας αν η καρτέλα πέθαινε
 * ΑΥΤΗ τη στιγμή;». Ο,τι κρατά η ουρά στη μνήμη της είναι άσχετο με αυτό.
 */
function readRaw(id) {
	return new Promise(function (resolve, reject) {
		const req = indexedDB.open('ecrm-offline-queue', 1);

		req.onsuccess = function () {
			const t = req.result.transaction('pending', 'readonly');
			const g = t.objectStore('pending').get(id);

			t.oncomplete = function () { req.result.close(); resolve(g.result || null); };
			t.onerror = function () { reject(t.error); };
		};

		req.onerror = function () { reject(req.error); };
	});
}

const UUID_A = '11111111-1111-4111-8111-111111111111';
const UUID_B = '22222222-2222-4222-8222-222222222222';

function payload(id, extra) {
	return Object.assign({ client_request_id: id, status: 'draft', afm: '090003373' }, extra || {});
}

/* ---- οι έλεγχοι --------------------------------------------------------- */

(async function () {
	console.log('\n1. μπαίνει στην ουρά και επιβιώνει');
	responder = jsonOnce(200, { ok: true, contract_id: 55 });
	let r = await Q.enqueueContract(payload(UUID_A), [{ file: fileOf('id.jpg', 1000), kind: 'id' }]);
	ok('δέχτηκε την αίτηση', r.ok, JSON.stringify(r));
	ok('μετριέται μία', (await Q.count()) === 1, String(await Q.count()));

	const stored = (await Q.pending())[0];
	ok('το κλειδί ΕΙΝΑΙ το client_request_id', stored.id === UUID_A, stored.id);
	ok('το αρχείο επέζησε με το μέγεθός του', stored.files[0].blob.size === 1000, String(stored.files[0].blob.size));
	ok('κρατήθηκε ο χρήστης', stored.userId === 7, String(stored.userId));

	console.log('\n2. ίδιο κλειδί = ίδια αίτηση, όχι δεύτερη');
	await Q.enqueueContract(payload(UUID_A, { afm: '094014201' }), []);
	ok('πάλι μία στην ουρά', (await Q.count()) === 1, String(await Q.count()));
	ok('με τα ΝΕΑ στοιχεία', (await Q.pending())[0].payload.afm === '094014201');

	console.log('\n3. η αποστολή: δημιουργία και μετά αρχεία, με τη σειρά');
	await wipe();
	await Q.enqueueContract(payload(UUID_A), [{ file: fileOf('id.jpg', 10), kind: 'id' }]);
	calls = [];
	responder = function (url) {
		return Promise.resolve({
			status: 200,
			json: function () {
				return Promise.resolve(/\/files$/.test(url) ? { ok: true, saved: 1 } : { ok: true, contract_id: 55 });
			}
		});
	};
	let rep = await Q.flush();
	ok('στάλθηκε μία', rep.sent === 1, JSON.stringify(rep));
	ok('δύο αιτήματα, με τη σωστή σειρά',
		calls.length === 2 && /\/contracts$/.test(calls[0].url) && /\/contracts\/55\/files$/.test(calls[1].url),
		calls.map(function (c) { return c.url; }).join(' , '));
	ok('η ουρά άδειασε', (await Q.count()) === 0, String(await Q.count()));

	console.log('\n4. ΤΟ ΚΡΙΣΙΜΟ: αν σπάσει στα αρχεία, η σύμβαση ΔΕΝ ξαναδημιουργείται');
	await wipe();
	await Q.enqueueContract(payload(UUID_A), [{ file: fileOf('id.jpg', 10), kind: 'id' }]);
	/* Τη στιγμή που ξεκινά το ανέβασμα αρχείων, τι υπάρχει ΣΤΟΝ ΔΙΣΚΟ; Αν το
	   contract_id δεν έχει ήδη γραφτεί εκεί, μια καρτέλα που πεθαίνει τώρα
	   (το λειτουργικό σκοτώνει το tab -- ο λόγος που διαλέχτηκε IndexedDB
	   εξαρχής) θα ξεκινούσε την επόμενη φορά από δημιουργία. */
	let onDiskWhenFilesStarted = null;
	responder = async function (url) {
		if (/\/files$/.test(url)) {
			onDiskWhenFilesStarted = await readRaw(UUID_A);

			throw new Error('offline');
		}

		return { status: 200, json: function () { return Promise.resolve({ ok: true, contract_id: 55 }); } };
	};
	await Q.flush();
	const half = (await Q.pending())[0];
	ok('η εγγραφή έμεινε', !!half);
	ok('κρατήθηκε το contract_id', half && half.contractId === 55, String(half && half.contractId));
	ok('και ήταν ΗΔΗ στον δίσκο πριν ξεκινήσουν τα αρχεία',
		onDiskWhenFilesStarted && onDiskWhenFilesStarted.contractId === 55,
		'στον δίσκο: ' + JSON.stringify(onDiskWhenFilesStarted && onDiskWhenFilesStarted.contractId));

	calls = [];
	responder = jsonOnce(200, { ok: true, saved: 1 });
	rep = await Q.flush();
	ok('η δεύτερη προσπάθεια πάει ΚΑΤΕΥΘΕΙΑΝ στα αρχεία',
		calls.length === 1 && /\/contracts\/55\/files$/.test(calls[0].url),
		calls.map(function (c) { return c.url; }).join(' , '));
	ok('και ολοκλήρωσε', rep.sent === 1 && (await Q.count()) === 0, JSON.stringify(rep));

	console.log('\n5. άρνηση του server (422): σταματά, δεν σβήνεται, λέει γιατί');
	await wipe();
	await Q.enqueueContract(payload(UUID_A), []);
	responder = jsonOnce(422, { ok: false, error: 'Μη έγκυρο ΑΦΜ.' });
	rep = await Q.flush();
	const bad = (await Q.pending())[0];
	ok('μετρήθηκε ως άρνηση', rep.blocked === 1, JSON.stringify(rep));
	ok('η δουλειά ΔΕΝ χάθηκε', !!bad);
	ok('κρατήθηκε το μήνυμα του server', bad && bad.lastError === 'Μη έγκυρο ΑΦΜ.', bad && bad.lastError);

	calls = [];
	await Q.flush();
	ok('και δεν ξαναδοκιμάζει μόνη της', calls.length === 0, String(calls.length));

	console.log('\n6. λήξη σύνδεσης: σταματά ΟΛΑ, δεν πετάει τίποτα');
	await wipe();
	await Q.enqueueContract(payload(UUID_A), []);
	await Q.enqueueContract(payload(UUID_B), []);
	calls = [];
	responder = jsonOnce(403, { ok: false });
	rep = await Q.flush();
	ok('σημάνθηκε ως θέμα σύνδεσης', rep.auth === true, JSON.stringify(rep));
	ok('σταμάτησε στην ΠΡΩΤΗ, δεν κάηκαν και οι δύο', calls.length === 1, String(calls.length));
	ok('καμία δεν χάθηκε', (await Q.count()) === 2, String(await Q.count()));

	console.log('\n7. ατέρμονο retry δεν υπάρχει');
	await wipe();
	await Q.enqueueContract(payload(UUID_A), []);
	responder = netFail();
	for (let i = 0; i < Q.MAX_ATTEMPTS + 2; i++) { await Q.flush(); }
	const stalled = (await Q.pending())[0];
	ok('ο μετρητής κόλλησε στο όριο', stalled.attempts === Q.MAX_ATTEMPTS, String(stalled.attempts));
	calls = [];
	await Q.flush();
	ok('και έπαψε να δοκιμάζει', calls.length === 0, String(calls.length));

	calls = [];
	responder = jsonOnce(200, { ok: true, contract_id: 55 });
	await Q.retryNow();
	ok('το «Στείλε τώρα» την ξεκολλά', calls.length === 1 && (await Q.count()) === 0, String(calls.length));

	console.log('\n8. offline: ούτε καν προσπαθεί');
	await wipe();
	await Q.enqueueContract(payload(UUID_A), []);
	calls = [];
	global.navigator.onLine = false;
	await Q.flush();
	ok('κανένα αίτημα εκτός σύνδεσης', calls.length === 0, String(calls.length));
	global.navigator.onLine = true;

	console.log('\n9. λήξη (TTL): οι ταυτότητες δεν μένουν για πάντα');
	await wipe();
	await Q.enqueueContract(payload(UUID_A), [{ file: fileOf('id.jpg', 10), kind: 'id' }]);
	const rec = (await Q.pending())[0];
	rec.createdAt = Date.now() - Q.TTL_MS - 1000;
	// Γράφεται πίσω μέσω enqueue του ΙΔΙΟΥ κλειδιού δεν γίνεται (θα ανανέωνε
	// τον χρόνο), οπότε περνά από την ίδια πόρτα που χρησιμοποιεί η ουρά.
	await new Promise(function (resolve, reject) {
		const req = indexedDB.open('ecrm-offline-queue', 1);
		req.onsuccess = function () {
			const t = req.result.transaction('pending', 'readwrite');
			t.objectStore('pending').put(rec);
			t.oncomplete = function () { req.result.close(); resolve(); };
			t.onerror = function () { reject(t.error); };
		};
		req.onerror = function () { reject(req.error); };
	});
	ok('η ληγμένη δεν μετριέται πια', (await Q.count()) === 0, String(await Q.count()));
	ok('και το purgeExpired τη σβήνει', (await Q.purgeExpired()) === 1);

	console.log('\n10. κοινόχρηστο tablet: η αίτηση άλλου δεν στέλνεται ΠΟΤΕ ως δική μας');
	await wipe();
	await Q.enqueueContract(payload(UUID_A), []);
	global.ECRM.userId = 9;
	ok('δεν τη βλέπει ο άλλος συνεργάτης', (await Q.count()) === 0, String(await Q.count()));
	calls = [];
	responder = jsonOnce(200, { ok: true, contract_id: 55 });
	await Q.flush();
	ok('και δεν στέλνεται στο όνομά του', calls.length === 0, String(calls.length));
	global.ECRM.userId = 7;
	ok('αλλά ο ιδιοκτήτης της τη βρίσκει ακέραιη', (await Q.count()) === 1, String(await Q.count()));

	console.log('\n11. όρια: η ουρά δεν γίνεται αποθήκη');
	await wipe();
	responder = netFail();
	for (let i = 0; i < Q.MAX_RECORDS; i++) {
		await Q.enqueueContract(payload('3333333' + i + '-3333-4333-8333-333333333333'), []);
	}
	r = await Q.enqueueContract(payload('44444444-4444-4444-8444-444444444444'), []);
	ok('η ' + (Q.MAX_RECORDS + 1) + 'η αρνείται', !r.ok, JSON.stringify(r));
	ok('και λέει γιατί', /γεμάτη/.test(r.reason || ''), r.reason);

	await wipe();
	r = await Q.enqueueContract(payload(UUID_A), [{ file: fileOf('huge.jpg', Q.MAX_BYTES + 1), kind: 'id' }]);
	ok('αρχείο πάνω από το όριο αρνείται', !r.ok, JSON.stringify(r));
	ok('χωρίς να αφήσει μισή εγγραφή', (await Q.count()) === 0, String(await Q.count()));

	console.log('\n12. τα μόνα αρχεία: η μισή περίπτωση που έτρωγε τις ταυτότητες');
	await wipe();
	await Q.enqueueFiles(77, [{ file: fileOf('id.jpg', 10), kind: 'id' }], UUID_B);
	calls = [];
	responder = jsonOnce(200, { ok: true, saved: 1 });
	rep = await Q.flush();
	ok('πάει κατευθείαν στα αρχεία της υπάρχουσας σύμβασης',
		calls.length === 1 && /\/contracts\/77\/files$/.test(calls[0].url),
		calls.map(function (c) { return c.url; }).join(' , '));
	ok('και καθάρισε', rep.sent === 1 && (await Q.count()) === 0, JSON.stringify(rep));

	console.log('\n' + (fails ? '✗ ΑΠΟΤΥΧΙΕΣ: ' + fails : '✓ ΟΛΑ ΠΕΡΑΣΑΝ') + '  (' + passes + ' έλεγχοι)');
	process.exit(fails ? 1 : 0);
})().catch(function (e) {
	console.error('\n✗ Ο έλεγχος έσκασε: ' + (e && e.stack || e));
	process.exit(1);
});
