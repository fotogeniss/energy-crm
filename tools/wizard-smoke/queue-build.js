/* Φτιάχνει το queue.js από τον ΠΡΑΓΜΑΤΙΚΟ κώδικα του plugin, όπως ακριβώς
 * κάνει το build.js για τη φόρμα: κόβει το ένα import και βάζει stubs, ώστε
 * να τρέχει σε node χωρίς import map.
 *
 * Η διαφορά από το build.js: εδώ δεν χρειάζεται jsdom. Η ουρά δεν αγγίζει
 * DOM -- αγγίζει IndexedDB, δίκτυο και χρόνο, και τα τρία δίνονται από τον
 * έλεγχο. Αυτό είναι σκόπιμο και όχι σύμπτωση: ό,τι αποφασίζει για δεδομένα
 * πελατών ζει σε κώδικα που μπορεί να τρέξει χωρίς οθόνη.
 *
 * Το export μετατρέπεται σε module.exports γιατί ο έλεγχος τρέχει ως CommonJS
 * (ίδιο ύφος με το run.js, ώστε να μη χρειαστεί δεύτερο στήσιμο node).
 */
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..', '..');

let js = fs.readFileSync(path.join(ROOT, 'public/assets/ecrm-queue.js'), 'utf8');
const before = js;

js = js.replace(/^(?:import .*?;\n)+/, `
function api(p){ return 'http://x/wp-json/ecrm/v1' + p; }
function H(){ return { 'X-WP-Nonce': (typeof ECRM !== 'undefined' ? ECRM.nonce : 'n') }; }
function fetch(u, o){ return globalThis.__fetch(u, o); }
`);

if (js === before) {
	console.error('Δεν βρέθηκε import στο ecrm-queue.js — άλλαξε η κεφαλή του αρχείου.');
	process.exit(2);
}

if (/^import /m.test(js)) {
	console.error('Έμεινε import χωρίς stub στο ecrm-queue.js — δες την κεφαλή.');
	process.exit(2);
}

/* `export function x` → `function x` + συλλογή του ονόματος, ίδιο για
 * `export var`. Αν αύριο μπει άλλη μορφή export (default, λίστα), το
 * παρακάτω δεν θα την πιάσει και ο έλεγχος θα σκάσει με «δεν βρέθηκε» αντί
 * να περάσει σιωπηλά -- προτιμότερο. */
const names = [];

js = js.replace(/^export (function|var) (\w+)/gm, function (_, kind, name) {
	names.push(name);

	return kind + ' ' + name;
});

if (names.length < 8) {
	console.error('Βρέθηκαν μόνο ' + names.length + ' exports στο ecrm-queue.js — άλλαξε η μορφή τους.');
	process.exit(2);
}

js += '\nmodule.exports = { ' + names.join(', ') + ' };\n';

fs.writeFileSync(path.join(__dirname, 'queue.js'), js);
console.log('queue ' + js.length + ' bytes · exports: ' + names.join(', '));
