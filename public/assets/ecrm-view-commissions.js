/* Energy CRM — commissions: what the partner has earned, and what is pending.
 *
 * First view lifted out of the app shell, and it qualified because it owns
 * everything it touches: commScope is read and written here and nowhere else,
 * and the only things it needs from outside are four primitives. Nothing had
 * to be rewritten to make the move possible — that is the bar the next view
 * has to clear too.
 */

import { api, esc, fetch, H, viewEl } from '@energy-crm/util';

var commScope = 'own';
export function loadCommissions() {
	var view = viewEl('commissions');
	fetch(api('/commissions') + '?scope=' + encodeURIComponent(commScope), { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (!d || !d.ok) { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα.</div></div>'; return; }
			view.innerHTML = commissionsHTML(d);
			view.querySelectorAll('[data-cscope]').forEach(function (b) { b.addEventListener('click', function () { commScope = this.getAttribute('data-cscope'); loadCommissions(); }); });
		})
		.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα φόρτωσης.</div></div>'; });
}

/* Η στήλη «Κατάσταση» λέει την αλήθεια — 22/08.
 *
 * Μέχρι σήμερα τύπωνε «Καταχωρημένο» σε ΚΑΘΕ γραμμή, σταθερά, σε πίνακα που
 * λέγεται «Οι εκκαθαρίσεις μου». Ο συνεργάτης που έβλεπε «Ιούλιος ·
 * Καταχωρημένο» έβγαζε συμπέρασμα για την πληρωμή του Ιουλίου. Η στήλη δεν
 * έλεγε τίποτα, και τα δεδομένα υπήρχαν ήδη στον server.
 *
 * Ο μισοπληρωμένος μήνας δείχνει ΑΡΙΘΜΟ, όχι λέξη: «2/5 πληρωμένα». Μια λέξη
 * θα έπρεπε να διαλέξει ανάμεσα σε δύο ψέματα. Ίδιος κανόνας με την καρτέλα
 * συνεργάτη — κάθε νούμερο κουβαλά τον παρονομαστή του.
 */
function paidBadge(m) {
	var paid = Number(m.paid || 0);
	var count = Number(m.count || 0);

	if (count > 0 && paid === count) {
		return '<span class="ecrm-badge ecrm-badge--active">Πληρωμένο</span>';
	}
	if (paid === 0) {
		return '<span class="ecrm-badge ecrm-badge--pending">Εκκρεμεί</span>';
	}
	return '<span class="ecrm-badge ecrm-badge--pending">' + paid + '/' + count + ' πληρωμένα</span>';
}

function commissionsHTML(d) {
	var months = d.months || [];
	var range = months.length ? (months[months.length - 1].label + ' → ' + months[0].label) : '—';
	var avg = d.count ? (d.total / d.count) : 0;

	var hist = months.length
		? '<div class="ecrm-tablewrap"><table class="ecrm-table"><thead><tr><th>Περίοδος</th><th class="ecrm-col-sec">Συμβόλαια</th><th>Κατάσταση</th><th style="text-align:right">Σύνολο</th></tr></thead><tbody>' +
			months.map(function (m) {
				return '<tr><td><strong>' + esc(m.label) + '</strong></td><td class="ecrm-col-sec">' + m.count + ' συμβόλαια</td>' +
					'<td>' + paidBadge(m) + '</td>' +
					'<td style="text-align:right" class="ecrm-mono">' + Number(m.amount).toFixed(0) + ' €</td></tr>';
			}).join('') + '</tbody></table></div>'
		: '<div class="ecrm-empty">Δεν υπάρχουν εκκαθαρίσεις ακόμα.</div>';

	return '' +
		'<header class="ecrm-head ecrm-head--row"><div><div class="ecrm-eyebrow">Προμήθειες</div><h2 class="ecrm-title">Οι εκκαθαρίσεις μου</h2>' +
		'<p class="ecrm-sub">Ιστορικό και συνολικά έσοδα από επιτυχημένες συμβάσεις</p></div>' +
		'<div class="ecrm-scope"><button type="button" class="ecrm-scope__b' + (commScope==="own"?" is-on":"") + '" data-cscope="own">Δικά μου</button><button type="button" class="ecrm-scope__b' + (commScope==="team"?" is-on":"") + '" data-cscope="team">Ομάδας</button></div></header>' +

		// dark hero
		'<div class="ecrm-payhero">' +
		'<div class="ecrm-payhero__main"><div class="ecrm-payhero__eyebrow"><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7h15a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><path d="M3 7V6a2 2 0 012-2h11M17 13h.01"/></svg> ΣΥΝΟΛΙΚΑ ΕΣΟΔΑ · ' + range + '</div>' +
		'<div class="ecrm-payhero__big">' + Number(d.total).toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g, ".") + ' €</div>' +
		'<div class="ecrm-payhero__sub">Από ' + months.length + ' εκκαθαρίσεις</div>' +

		/* Το σύνολο βγαίνει από όσες γραμμές επέστρεψε ο server, και ο server
		   σταματά σε ένα όριο. Πάνω από αυτό το ποσό ΕΙΝΑΙ μικρότερο από το
		   πραγματικό — και ένα λάθος ποσό χωρίς ένδειξη είναι χειρότερο από
		   κανένα ποσό. */
		(d.truncated
			? '<div class="ecrm-payhero__warn">Μερικό: υπολογίστηκαν ' + esc(d.count) +
			  ' από ' + esc(d.available) + ' συμβάσεις</div>'
			: '') +
		'</div>' +
		'<div class="ecrm-payhero__side"><div class="ecrm-payhero__k">ΚΟΡΥΦΑΙΟΣ ΜΗΝΑΣ</div><div class="ecrm-payhero__v">' + esc(d.best_label || '—') + '</div>' +
		'<div class="ecrm-payhero__k" style="margin-top:12px">ΣΕ ΑΝΑΜΟΝΗ</div><div class="ecrm-payhero__v">~' + Number(d.pending_est).toFixed(0) + ' €</div></div></div>' +

		// three stat cards
		'<div class="ecrm-stats">' +
		'<div class="ecrm-stat is-routed"><div class="ecrm-stat__k">✓ Καταχωρημένες</div><div class="ecrm-stat__v">' + (d.count || 0) + '</div></div>' +
		'<div class="ecrm-stat is-today"><div class="ecrm-stat__k"><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M15.5 9A4 4 0 009 12a4 4 0 006.5 3M7.5 11h5M7.5 13.5h5"/></svg> Πληρωμένα</div><div class="ecrm-stat__v">' + Number(d.paid_total || 0).toFixed(0) + ' €</div></div>' +
		'<div class="ecrm-stat is-pending"><div class="ecrm-stat__k"><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h12v18l-2-1.5L14 21l-2-1.5L10 21l-2-1.5L6 21z"/><path d="M9 8h6M9 12h6"/></svg> Προς πληρωμή</div><div class="ecrm-stat__v">' + Number(d.unpaid_total || 0).toFixed(0) + ' €</div></div></div>' +

		// history
		'<div class="ecrm-card"><div class="ecrm-step">Ιστορικό εκκαθαρίσεων <span class="ecrm-step__hint">μέσος όρος ' + avg.toFixed(0) + ' € / σύμβαση</span></div>' + hist + '</div>';
}
