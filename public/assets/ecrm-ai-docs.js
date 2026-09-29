/* Energy CRM — τα έγγραφα όπως τα στέλνουμε στο AI.
 *
 * Το μοντέλο χρεώνει και αργεί ανά σελίδα, όχι ανά byte. Ένας λογαριασμός
 * Vodafone 28 σελίδων ήταν ~100.000 tokens, επειδή από τη σελ. 5 και μετά
 * είναι αναλυτικό κλήσεων: αριθμοί τρίτων που δεν έχουν λόγο να φύγουν από
 * εδώ. Τα στοιχεία του πελάτη και ο αριθμός παροχής είναι στην πρώτη σελίδα.
 *
 * Ο server δεν έχει βιβλιοθήκη που να κόβει PDF, ο browser έχει (pdf-lib,
 * τοπικό αντίγραφο στο vendor/). Γι' αυτό η περικοπή γίνεται εδώ, και για τα
 * αρχεία που διαλέγει ο συνεργάτης και για όσα έστειλε ο πελάτης από τον
 * σύνδεσμό του. Αποθηκεύεται πάντα ολόκληρο το αρχείο· κομμένο πάει μόνο
 * στην ανάγνωση. Δες CHANGELOG (294), (297). */

import { api, fetch, H } from '@energy-crm/util';

export var AI_PDF_PAGES = 2;

var pdfLibLoading = null;

function isPdf(file) {
	return file.type === 'application/pdf' || /\.pdf$/i.test(file.name || '');
}

/* Οι πρώτες AI_PDF_PAGES σελίδες ενός PDF, ή null όταν δεν χρειάζεται ή δεν
 * γίνεται — τότε ο καλών στέλνει το αρχείο ολόκληρο, όπως πριν. */
export function firstPages(file) {
	if (!isPdf(file)) { return Promise.resolve(null); }
	// Φορτώνεται μόνο την πρώτη φορά που πέφτει PDF — μισό MB που δεν το
	// κουβαλάει όποιος ανεβάζει μόνο φωτογραφίες.
	pdfLibLoading = pdfLibLoading || import('./vendor/pdf-lib-1.17.1.esm.min.js');

	return Promise.all([pdfLibLoading, file.arrayBuffer()])
		.then(function (r) {
			var lib = r[0];
			return lib.PDFDocument.load(r[1], { ignoreEncryption: true }).then(function (src) {
				// Κρυπτογραφημένο PDF βγαίνει με λευκές σελίδες αν το κόψεις —
				// καλύτερα ολόκληρο και αργό παρά γρήγορο και άδειο.
				if (src.isEncrypted || src.getPageCount() <= AI_PDF_PAGES) { return null; }
				return lib.PDFDocument.create().then(function (out) {
					var keep = [];
					for (var i = 0; i < AI_PDF_PAGES; i++) { keep.push(i); }
					return out.copyPages(src, keep).then(function (pages) {
						pages.forEach(function (page) { out.addPage(page); });
						return out.save();
					});
				});
			});
		})
		.then(function (bytes) {
			return bytes ? new File([bytes], file.name, { type: 'application/pdf' }) : null;
		})
		// Ό,τι κι αν πάει στραβά, στέλνεται ολόκληρο.
		.catch(function () { return null; });
}

// Το rest_url() με απλά permalinks έχει ήδη «?rest_route=», οπότε το σωστό
// διαχωριστικό δεν είναι πάντα το «?».
function withQuery(url, query) {
	return url + (url.indexOf('?') === -1 ? '?' : '&') + query;
}

/* Τα αποθηκευμένα έγγραφα μιας αίτησης ή ενός lead, με τα PDF κομμένα,
 * έτοιμα για files[] + kinds[] στο /extract.
 *
 * `scope` είναι { contract_id: N } ή { lead_id: N }.
 *
 * Επιστρέφει null όταν δεν αξίζει ή δεν γίνεται — ο καλών τότε στέλνει
 * contract_id/lead_id όπως πριν και ο server τα διαβάζει ολόκληρα. «Δεν
 * αξίζει» είναι όταν δεν υπάρχει PDF: τις φωτογραφίες ο server τις διαβάζει
 * ήδη, και κατέβασμα-ανέβασμα θα ήταν διπλή μεταφορά για το τίποτα. */
export function storedForAi(scope) {
	var key = scope.lead_id ? 'lead_id' : 'contract_id';
	var query = key + '=' + encodeURIComponent(String(scope[key]));

	return fetch(withQuery(api('/extract/sources'), query), { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			var sources = (d && d.ok && d.sources) || [];
			if (!sources.some(function (s) { return s.mime === 'application/pdf'; })) { return null; }

			return Promise.all(sources.map(function (src, i) {
				return fetch(withQuery(api('/extract/sources/' + src.id), query), { headers: H() })
					.then(function (r) {
						if (!r.ok) { throw new Error('HTTP ' + r.status); }
						return r.blob();
					})
					.then(function (blob) {
						var ext = src.mime === 'application/pdf' ? 'pdf' : (String(src.mime).split('/')[1] || 'bin');
						var file = new File([blob], 'doc-' + (i + 1) + '.' + ext, { type: src.mime });
						return firstPages(file).then(function (small) {
							return { file: small || file, kind: src.kind };
						});
					});
			}));
		})
		.catch(function () { return null; });
}
