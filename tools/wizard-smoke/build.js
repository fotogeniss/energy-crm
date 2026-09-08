/* Φτιάχνει δύο προσωρινά αρχεία από τον ΠΡΑΓΜΑΤΙΚΟ κώδικα του plugin:
 *
 *   form.html — το στατικό markup της φόρμας, βγαλμένο από το
 *               class-ecrm-shortcodes.php αφού αφαιρεθούν τα μπλοκ <?php ?>.
 *   form.js   — το ecrm-form.js με το ένα import του αντικατεστημένο από
 *               τοπικά stubs, ώστε να τρέχει χωρίς import map.
 *
 * ΤΙ ΔΕΝ ΚΑΝΕΙ: δεν εκτελεί PHP. Ό,τι παράγουν οι βρόχοι και οι closures
 * ($ecrm_field, τα chips, τα options) ΛΕΙΠΕΙ από το form.html. Άρα ο έλεγχος
 * πιάνει τη λογική του wizard και τον σκελετό — όχι κάθε πεδίο.
 */
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..', '..');

const php = fs.readFileSync(path.join(ROOT, 'public/class-ecrm-shortcodes.php'), 'utf8');
const a = php.indexOf('<div class="ecrm-form"');
const b = php.indexOf('<?php\n\t\treturn (string) ob_get_clean();');
if (a < 0 || b < 0) { console.error('Δεν βρέθηκαν τα όρια της φόρμας στο shortcode.'); process.exit(2); }
const html = php.slice(a, b).replace(/<\?php[\s\S]*?\?>/g, '');
fs.writeFileSync(path.join(__dirname, 'form.html'), html);

let js = fs.readFileSync(path.join(ROOT, 'public/assets/ecrm-form.js'), 'utf8');
const before = js;
js = js.replace(/^(?:import .*?;\n)+/, `
var __toasts = [];
function api(p){ return 'http://x/wp-json/ecrm/v1' + p; }
function esc(s){ return String(s == null ? '' : s); }
function rejectedNote(){ return ''; }
function toast(msg, ok){ __toasts.push({ msg: msg, ok: ok !== false }); }
window.__toasts = __toasts;
function openCustomerContracts(){ /* no-op: το smoke test δεν κάνει ποτέ click σε αυτό το κουμπί */ }
/* Το πραγματικό requestId() ζει στο ecrm-util.js και στηρίζεται στο crypto,
 * που εδώ δεν υπάρχει. Το stub κρατά ό,τι ελέγχεται: σχήμα UUID v4 (4 στην
 * αρχή της τρίτης ομάδας, 8-b στην τέταρτη) και ΔΙΑΦΟΡΕΤΙΚΗ τιμή σε κάθε
 * κλήση. Είναι ντετερμινιστικό επίτηδες -- δύο τρεξίματα βγάζουν το ίδιο
 * output, οπότε μια διαφορά σημαίνει αλλαγή κώδικα και όχι τύχη. */
var __rid = 0;
function requestId(){ __rid++; return ('0000000' + __rid).slice(-8) + '-0000-4000-8000-000000000000'; }
`);
if (js === before) { console.error('Δεν βρέθηκε import στο ecrm-form.js — άλλαξε η κεφαλή του αρχείου.'); process.exit(2); }
if (/^import /m.test(js)) { console.error('Έμεινε import χωρίς stub στο ecrm-form.js — δες την κεφαλή.'); process.exit(2); }
fs.writeFileSync(path.join(__dirname, 'form.js'), js);

const steps = (html.match(/data-wstep="\d"/g) || []).length;
console.log('markup ' + html.length + ' bytes · js ' + js.length + ' bytes · βήματα στο markup: ' + steps);
if (steps !== 4) { console.error('Περίμενα 4 wrappers [data-wstep], βρήκα ' + steps + '.'); process.exit(2); }
