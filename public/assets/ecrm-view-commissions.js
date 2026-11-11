/* Energy CRM — commissions: what the partner has earned, and what is pending.
 *
 * First view lifted out of the app shell, and it qualified because it owns
 * everything it touches: commScope is read and written here and nowhere else,
 * and the only things it needs from outside are four primitives. Nothing had
 * to be rewritten to make the move possible — that is the bar the next view
 * has to clear too.
 *
 * (355) Στη νέα εμφάνιση «Signature»: ένα μεγάλο σύνολο με μπάρα «πληρωμένα /
 * προς πληρωμή / σε αναμονή», μηνιαίες στήλες, οι μήνες ως λίστα και οι
 * βεβαιώσεις ως γραμμές με PDF (sigCommissionsHTML). Ίδια δεδομένα, ίδια data-*.
 */

import { api, esc, fetch, H, toast, viewEl } from '@energy-crm/util';

var commScope = 'own';
export function loadCommissions() {
	var view = viewEl('commissions');
	Promise.all([
		fetch(api('/commissions') + '?scope=' + encodeURIComponent(commScope), { headers: H() }).then(function (r) { return r.json(); }),
		fetch(api('/payouts') + '?scope=' + encodeURIComponent(commScope), { headers: H() }).then(function (r) { return r.json(); })
	])
		.then(function (results) {
			var d = results[0], p = results[1];
			if (!d || !d.ok) { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα.</div></div>'; return; }
			view.innerHTML = commissionsHTML(d, (p && p.ok) ? p.rows : []);
			view.querySelectorAll('[data-cscope]').forEach(function (b) { b.addEventListener('click', function () { commScope = this.getAttribute('data-cscope'); loadCommissions(); }); });
			view.querySelectorAll('[data-payout-pdf]').forEach(function (b) {
				b.addEventListener('click', function () { downloadStatement(this.getAttribute('data-payout-pdf'), this); });
			});
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

/* Βεβαιώσεις εκκαθάρισης — build queue 11.
 *
 * Ξεχωριστή κάρτα από το «Ιστορικό εκκαθαρίσεων» πιο πάνω επίτηδες: εκείνο
 * είναι μηνιαία σύνολα υπολογισμένα ζωντανά από συμβάσεις, αυτό εδώ είναι οι
 * πραγματικές παρτίδες πληρωμής (wp_ecrm_payouts) — τις φτιάχνει χειροκίνητα
 * ο διαχειριστής και δεν ευθυγραμμίζονται πάντα με ημερολογιακό μήνα. Ίδιο
 * scope-toggle («Δικά μου»/«Ομάδας») με την υπόλοιπη οθόνη — το mockup
 * (docs/UI-PAYOUT-STATEMENT.html) ρώτησε ρητά και εγκρίθηκε: όχι δεύτερος
 * εναλλάκτης, ο ίδιος του header.
 */
function payoutsHTML(rows) {
	if (!rows || !rows.length) {
		return '<div class="ecrm-card" style="margin-top:16px"><div class="ecrm-step">Βεβαιώσεις εκκαθάρισης</div><div class="ecrm-empty">Καμία παρτίδα εκκαθάρισης ακόμα.</div></div>';
	}

	var body = rows.map(function (b) {
		var paid = b.status === 'paid';
		return '<tr>' +
			'<td><strong>' + esc(b.period) + '</strong>' + (commScope === 'team' ? '<div class="ecrm-muted">' + esc(b.partner) + '</div>' : '') + '</td>' +
			'<td class="ecrm-col-sec">' + b.count + '</td>' +
			'<td>' + (paid ? '<span class="ecrm-badge ecrm-badge--active">Πληρωμένη</span>' : '<span class="ecrm-badge ecrm-badge--pending">Εκκρεμεί</span>') + '</td>' +
			'<td style="text-align:right" class="ecrm-mono">' + Number(b.amount).toFixed(0) + ' €</td>' +
			'<td class="ecrm-rowactcol"><button type="button" class="ecrm-btn ecrm-btn--sm" data-payout-pdf="' + b.id + '">' +
			'<svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v13m0 0l-4-4m4 4l4-4"/><path d="M5 20h14"/></svg> PDF</button></td>' +
			'</tr>';
	}).join('');

	return '<div class="ecrm-card" style="margin-top:16px"><div class="ecrm-step">Βεβαιώσεις εκκαθάρισης <span class="ecrm-step__hint">το επίσημο PDF κάθε παρτίδας πληρωμής</span></div>' +
		'<div class="ecrm-tablewrap"><table class="ecrm-table"><thead><tr><th>Περίοδος</th><th class="ecrm-col-sec">Συμβάσεις</th><th>Κατάσταση</th><th style="text-align:right">Ποσό</th><th></th></tr></thead><tbody>' +
		body + '</tbody></table></div></div>';
}

/**
 * Λήψη βεβαίωσης — ίδιο μοτίβο b64 → Blob με το downloadBinary() του
 * ecrm-view-detail.js, αλλά με δικό του κουμπί ανά γραμμή αντί για ένα μόνο
 * κουμπί οθόνης, γι' αυτό ξεχωριστή, μικρή συνάρτηση εδώ.
 */
function downloadStatement(id, btn) {
	btn.disabled = true;
	var idle = btn.innerHTML;
	btn.textContent = 'Λήψη…';

	fetch(api('/payouts/' + id + '/statement'), { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (!d || !d.ok) { toast((d && d.error) || 'Αποτυχία.', false); return; }
			var bin = atob(d.b64), len = bin.length, arr = new Uint8Array(len);
			for (var i = 0; i < len; i++) { arr[i] = bin.charCodeAt(i); }
			var a = document.createElement('a');
			a.href = URL.createObjectURL(new Blob([arr], { type: d.mime || 'application/pdf' }));
			a.download = d.filename;
			document.body.appendChild(a); a.click();
			setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
		})
		.catch(function () { toast('Σφάλμα δικτύου.', false); })
		.finally(function () { btn.disabled = false; btn.innerHTML = idle; });
}

function commissionsHTML(d, payoutRows) {
	if (isSignature()) { return sigCommissionsHTML(d, payoutRows); }
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
		'<div class="ecrm-card"><div class="ecrm-step">Ιστορικό εκκαθαρίσεων <span class="ecrm-step__hint">μέσος όρος ' + avg.toFixed(0) + ' € / σύμβαση</span></div>' + hist + '</div>' +

		// payout statements
		payoutsHTML(payoutRows);
}

// ---------------------------------------------------------------------
// (355) «Signature»: Προμήθειες
// ---------------------------------------------------------------------

function isSignature() {
	var app = document.getElementById('ecrm-app');
	return !!app && app.getAttribute('data-ui') === 'signature';
}

/** 12.480 € -- τελεία για χιλιάδες, χωρίς δεκαδικά, όπως η κλασική οθόνη. */
function eur(n) {
	return Number(n || 0).toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ' €';
}

/* «Ιούνιος 2026» → «Ιούν». Τρία γράμματα δεν φτάνουν: Ιούνιος και Ιούλιος
 * γίνονταν και οι δύο «Ιού». */
var SHORT_MONTH = {
	'Ιανουάριος': 'Ιαν', 'Φεβρουάριος': 'Φεβ', 'Μάρτιος': 'Μάρ', 'Απρίλιος': 'Απρ', 'Μάιος': 'Μάι', 'Ιούνιος': 'Ιούν',
	'Ιούλιος': 'Ιούλ', 'Αύγουστος': 'Αύγ', 'Σεπτέμβριος': 'Σεπ', 'Οκτώβριος': 'Οκτ', 'Νοέμβριος': 'Νοέ', 'Δεκέμβριος': 'Δεκ'
};
function shortMonth(label) {
	var first = String(label || '').split(' ')[0];
	return SHORT_MONTH[first] || first.slice(0, 3);
}

function pct(part, whole) {
	return whole > 0 ? Math.max(0, Math.min(100, Math.round(part / whole * 1000) / 10)) : 0;
}

function sigCommissionsHTML(d, payoutRows) {
	var months = d.months || [];
	var paid = Number(d.paid_total) || 0;
	var unpaid = Number(d.unpaid_total) || 0;
	var pending = Number(d.pending_est) || 0;
	var whole = paid + unpaid + pending;
	var range = months.length ? (months[months.length - 1].label + ' έως ' + months[0].label) : '';

	var scope = '<div class="ecrm-scope"><button type="button" class="ecrm-scope__b' + (commScope === 'own' ? ' is-on' : '') + '" data-cscope="own">Δικά μου</button>' +
		'<button type="button" class="ecrm-scope__b' + (commScope === 'team' ? ' is-on' : '') + '" data-cscope="team">Ομάδας</button></div>';

	// Η μπάρα: τρία κομμάτια, με τη σειρά που φτάνουν τα λεφτά στην τσέπη.
	var bar = '<div class="sig-pay__bar" role="img" aria-label="' + esc('Πληρωμένα ' + eur(paid) + ', προς πληρωμή ' + eur(unpaid) + ', σε αναμονή περίπου ' + eur(pending)) + '">' +
		'<i class="is-paid" style="width:' + esc(String(pct(paid, whole))) + '%"></i>' +
		'<i class="is-due" style="width:' + esc(String(pct(unpaid, whole))) + '%"></i>' +
		'<i class="is-wait" style="width:' + esc(String(pct(pending, whole))) + '%"></i></div>';

	var legend = '<div class="sig-pay__legend">' +
		'<span class="is-paid"><i></i><small>Πληρωμένα</small><b>' + esc(eur(paid)) + '</b></span>' +
		'<span class="is-due"><i></i><small>Προς πληρωμή</small><b>' + esc(eur(unpaid)) + '</b></span>' +
		'<span class="is-wait"><i></i><small>Σε αναμονή</small><b>~' + esc(eur(pending)) + '</b></span></div>';

	var hero = '<section class="sig-pay">' +
		'<div class="sig-pay__top"><div><small>Συνολικά έσοδα' + (range ? ', ' + esc(range) : '') + '</small>' +
		'<b class="sig-pay__big">' + esc(eur(d.total)) + '</b>' +
		'<span class="sig-pay__sub">' + esc(String(d.count || 0)) + ((d.count === 1) ? ' σύμβαση' : ' συμβάσεις') +
			(d.count ? ', μέσος όρος ' + esc(eur((Number(d.total) || 0) / d.count)) : '') +
			(d.best_label ? ' · καλύτερος μήνας ' + esc(d.best_label) : '') + '</span></div></div>' +
		bar + legend +
		(d.truncated ? '<div class="sig-pay__warn">Μερικό: υπολογίστηκαν ' + esc(d.count) + ' από ' + esc(d.available) + ' συμβάσεις</div>' : '') +
		'</section>';

	// Στήλες ανά μήνα, από τον παλαιότερο αριστερά, έως 12.
	var shown = months.slice(0, 12).reverse();
	var top = shown.reduce(function (n, m) { return Math.max(n, Number(m.amount) || 0); }, 0);
	var chart = shown.length ? '<section class="ecrm-card sig-paychart"><h3 class="sig-ch">Ανά μήνα</h3><div class="sig-paychart__cols">' +
		shown.map(function (m) {
			var full = Number(m.count) > 0 && Number(m.paid) === Number(m.count);
			var h = top ? Math.max(4, Math.round((Number(m.amount) || 0) / top * 100)) : 4;
			return '<div class="sig-paychart__col' + (full ? ' is-paid' : '') + '" title="' + esc(m.label + ': ' + eur(m.amount)) + '">' +
				'<span class="sig-paychart__v">' + esc(eur(m.amount)) + '</span>' +
				'<span class="sig-paychart__b"><i style="height:' + esc(String(h)) + '%"></i></span>' +
				'<small>' + esc(shortMonth(m.label)) + '</small></div>';
		}).join('') + '</div></section>' : '';

	var monthRows = months.length
		? '<ul class="sig-paylist">' + months.map(function (m) {
			var paidN = Number(m.paid || 0), count = Number(m.count || 0);
			var state = count > 0 && paidN === count ? '<em class="is-ok">Πληρωμένο</em>'
				: paidN === 0 ? '<em>Εκκρεμεί</em>'
				: '<em>' + esc(paidN + '/' + count) + ' πληρωμένα</em>';
			return '<li><span class="sig-paylist__who"><b>' + esc(m.label) + '</b><small>' + esc(String(count)) + (count === 1 ? ' σύμβαση' : ' συμβάσεις') + '</small></span>' +
				state + '<b class="sig-paylist__amt">' + esc(eur(m.amount)) + '</b></li>';
		}).join('') + '</ul>'
		: '<div class="sig-tempty"><b>Δεν υπάρχουν εκκαθαρίσεις ακόμα.</b><span>Εμφανίζονται μόλις μια αίτηση γίνει Ενεργή.</span></div>';

	var statements = (payoutRows && payoutRows.length)
		? '<ul class="sig-paylist">' + payoutRows.map(function (b) {
			var isPaid = b.status === 'paid';
			return '<li><span class="sig-paylist__who"><b>' + esc(b.period) + '</b><small>' +
				(commScope === 'team' && b.partner ? esc(b.partner) + ' · ' : '') + esc(String(b.count)) + (Number(b.count) === 1 ? ' σύμβαση' : ' συμβάσεις') + '</small></span>' +
				(isPaid ? '<em class="is-ok">Πληρωμένη</em>' : '<em>Εκκρεμεί</em>') +
				'<b class="sig-paylist__amt">' + esc(eur(b.amount)) + '</b>' +
				'<button type="button" class="sig-paylist__pdf" data-payout-pdf="' + esc(String(b.id)) + '" aria-label="' + esc('Λήψη βεβαίωσης ' + b.period) + '">' +
					'<svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v13m0 0l-4-4m4 4l4-4"/><path d="M5 20h14"/></svg> PDF</button></li>';
		}).join('') + '</ul>'
		: '<div class="sig-tempty"><b>Καμία παρτίδα εκκαθάρισης ακόμα.</b><span>Η βεβαίωση εμφανίζεται εδώ μόλις ο διαχειριστής φτιάξει παρτίδα πληρωμής.</span></div>';

	return '<header class="sig-cuhead"><div><h2>Προμήθειες</h2><p>Τι έχεις βγάλει και τι έρχεται</p></div>' + scope + '</header>' +
		hero + chart +
		'<div class="sig-paygrid">' +
			'<section><h3 class="sig-ch sig-paygrid__h">Μήνες</h3>' + monthRows + '</section>' +
			'<section><h3 class="sig-ch sig-paygrid__h">Βεβαιώσεις εκκαθάρισης</h3>' + statements + '</section>' +
		'</div>';
}
