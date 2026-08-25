/* Energy CRM — partner card: πώς τα πάει ένας άνθρωπος.
 *
 * ## Ο κανόνας αυτής της οθόνης: κανένα νούμερο χωρίς τον παρονομαστή του
 *
 * Ένα «78%» δίπλα σε ένα πρόσωπο διαβάζεται ως κρίση. Με τρεις συμβάσεις είναι
 * θόρυβος που μοιάζει με κρίση, και είναι χειρότερο από το να μη γράφει τίποτα:
 * ο μάνατζερ παίρνει απόφαση για άνθρωπο πάνω σε δείγμα που δεν του είπε κανείς.
 * Γι' αυτό κάθε πλακίδιο έχει υποσημείωση (`ecrm-kpi__f`) που λέει από πόσα
 * μετρήθηκε — και όταν το δείγμα είναι πολύ μικρό, το λέει καθαρά αντί για
 * ποσοστό. Το backend στέλνει πάντα τον παρονομαστή· εδώ απλώς δεν τον κρύβουμε.
 *
 * ## Γιατί ο μέσος χρόνος έχει προειδοποίηση
 *
 * Η στήλη `signed_at` μπήκε 2026-08-18. Ό,τι υπογράφηκε πριν την έχει κενή, όχι
 * επειδή δεν υπογράφηκε αλλά επειδή κανείς δεν κατέγραφε πότε. Ένας μέσος όρος
 * από δώδεκα υπογραφές δεν είναι «ο χρόνος του συνεργάτη» — και χωρίς το
 * δείγμα δίπλα του θα περνούσε για ιστορικό.
 */

import { api, esc, fetch, H, viewEl } from '@energy-crm/util';
import { fmtDate, initials, tint } from '@energy-crm/format';
import { go, openDetail } from '@energy-crm/navigate';

/** Κάτω από αυτό, ένα ποσοστό είναι θόρυβος και όχι μέτρηση. */
var MIN_SAMPLE = 5;

export function openPartner(id) {
	go('partner');
	var view = viewEl('partner');
	if (!view) { return; }
	view.innerHTML = '<div class="ecrm-loading">Φόρτωση…</div>';
	fetch(api('/team/' + id), { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (!d || !d.ok) {
				view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">' +
					esc((d && d.error) || 'Δεν βρέθηκε.') + '</div></div>';
				return;
			}
			renderPartner(view, d);
		})
		.catch(function () {
			view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα φόρτωσης.</div></div>';
		});
}

/** Το τσιπάκι μεταβολής — ίδιο σχήμα και ίδιο SVG με του πίνακα. */
function deltaChip(now, prev) {
	var d = now - prev;
	if (!d) { return ''; }
	var up = d > 0;
	return '<span class="ecrm-delta ' + (up ? 'is-up' : 'is-down') + '">' +
		'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 ' +
		(up ? '19V5M5 12l7-7 7 7' : '5v14M5 12l7 7 7-7') + '"/></svg>' +
		(up ? '+' : '') + d + '</span>';
}

function kpi(label, value, foot) {
	return '<div class="ecrm-kpi">' +
		'<div class="ecrm-kpi__k">' + esc(label) + '</div>' +
		'<div class="ecrm-kpi__v">' + value + '</div>' +
		'<div class="ecrm-kpi__f">' + foot + '</div>' +
		'</div>';
}

/** €1.234,50 — ελληνική μορφή, χωρίς εξάρτηση από locale του browser. */
function euro(n) {
	var v = Math.round((Number(n) || 0) * 100) / 100;
	var parts = v.toFixed(2).split('.');
	var whole = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
	return '€' + whole + ',' + parts[1];
}

function kpiCards(k) {
	var m = k.month || { month: 0, prev: 0 };
	var s = k.success || { payable: 0, settled: 0 };
	var g = k.sign || { avg: null, sample: 0 };
	var c = k.commission || { total: 0, unpaid: 0, count: 0 };

	// ΠΡΟΣΟΧΗ: prev === 0 σημαίνει «καμία τον προηγούμενο μήνα», ΟΧΙ «δεν
	// ξέρουμε». Το πρώτο γράψιμο εδώ έλεγε «δεν υπάρχει προηγούμενος μήνας για
	// σύγκριση» για κάθε μηδενικό — ενώ δίπλα του το τσιπάκι έδειχνε «+3». Δύο
	// στοιχεία της ίδιας κάρτας έλεγαν αντίθετα πράγματα, και φάνηκε μόνο όταν
	// αποδόθηκε ο νέος συνεργάτης.
	var monthFoot = m.prev === 0
		? 'καμία τον προηγούμενο μήνα'
		: 'έναντι ' + m.prev + ' τον προηγούμενο μήνα';

	// Το ποσοστό εμφανίζεται ΜΟΝΟ με αρκετό δείγμα. Αλλιώς μπαίνει ο ωμός
	// λόγος: «3 από 4» λέει την αλήθεια, το «75%» υπονοεί σταθερότητα που
	// τέσσερις συμβάσεις δεν στηρίζουν.
	var rate, rateFoot;
	if (!s.settled) {
		rate = '—';
		rateFoot = 'καμία σύμβαση δεν έχει κλείσει ακόμη';
	} else if (s.settled < MIN_SAMPLE) {
		rate = s.payable + '/' + s.settled;
		rateFoot = 'πολύ μικρό δείγμα για ποσοστό';
	} else {
		rate = Math.round((s.payable / s.settled) * 100) + '%';
		rateFoot = s.payable + ' από ' + s.settled + ' που έκλεισαν';
	}

	var days, daysFoot;
	if (g.avg === null || !g.sample) {
		days = '—';
		daysFoot = 'καμία καταγεγραμμένη υπογραφή';
	} else {
		days = String(g.avg).replace('.', ',') + (g.avg === 1 ? ' μέρα' : ' μέρες');
		daysFoot = 'από ' + g.sample + (g.sample === 1 ? ' υπογραφή' : ' υπογραφές') +
			(g.sample < MIN_SAMPLE ? ' — μικρό δείγμα' : '');
	}

	return '<div class="ecrm-kpis ecrm-kpis--4">' +
		kpi('Συμβάσεις μήνα', m.month + deltaChip(m.month, m.prev), esc(monthFoot)) +
		kpi('Επιτυχία', rate, esc(rateFoot)) +
		kpi('Μέσος χρόνος ως υπογραφή', days, esc(daysFoot)) +
		kpi('Προμήθεια', euro(c.total),
			c.unpaid > 0
				? esc(euro(c.unpaid) + ' δεν έχει πληρωθεί ακόμη')
				: (c.count ? 'όλα πληρωμένα' : 'καμία πληρωτέα σύμβαση')) +
		'</div>';
}

function recentRows(list, statuses) {
	if (!list.length) {
		return '<tr><td colspan="4" class="ecrm-empty">Καμία σύμβαση ακόμη.</td></tr>';
	}
	return list.map(function (r) {
		return '<tr class="ecrm-rowlink" data-open="' + r.id + '">' +
			'<td><strong>' + esc(r.customer) + '</strong>' +
			(r.code ? '<div class="ecrm-muted ecrm-tlrole">' + esc(r.code) + '</div>' : '') + '</td>' +
			'<td class="ecrm-muted ecrm-col-sec">' + esc(r.provider) + '</td>' +
			'<td><span class="ecrm-badge ecrm-badge--' + esc(r.status) + '">' +
			esc(statuses[r.status] || r.status) + '</span></td>' +
			'<td class="ecrm-muted ecrm-col-sec">' + esc(fmtDate(r.updated)) + '</td>' +
			'</tr>';
	}).join('');
}

/* Χρησιμοποιεί το ήδη υπάρχον euro() παραπάνω για τη μορφή του ποσού --
   ίδιο νόμισμα, ίδια λογική, όχι δεύτερη εκδοχή. */
function commissionRows(rows) {
	if (!rows.length) {
		return '<div class="ecrm-empty">Καμία προμήθεια ακόμη.</div>';
	}
	var body = rows.map(function (r) {
		return '<tr><td><strong>' + esc(r.customer) + '</strong>' +
			(r.code ? '<div class="ecrm-muted ecrm-tlrole">' + esc(r.code) + '</div>' : '') + '</td>' +
			'<td class="ecrm-muted ecrm-col-sec">' + esc(r.provider) + '</td>' +
			'<td class="ecrm-mono" style="text-align:right">' + euro(r.amount) + '</td>' +
			'<td>' + (r.paid
				? '<span class="ecrm-badge ecrm-badge--active">Πληρώθηκε</span>'
				: '<span class="ecrm-badge ecrm-badge--pending">Εκκρεμεί</span>') + '</td></tr>';
	}).join('');
	// Δέκα γραμμές είναι το όριο του ίδιου του backend (TeamController::
	// commissionRows()) -- όχι δικός της υπολογισμός εδώ, άρα δεν ξέρει αν
	// υπάρχουν κι άλλες. Δείχνει τη σημείωση όποτε φτάσει ακριβώς στο όριο,
	// που είναι η μόνη στιγμή που «μπορεί να υπάρχουν κι άλλες» είναι αλήθεια.
	var more = rows.length >= 10
		? '<div class="ecrm-muted" style="text-align:center;padding:9px;font-size:11.5px">+ δες όλες στις «Προμήθειες»</div>'
		: '';
	return '<div class="ecrm-tablewrap"><table class="ecrm-table"><thead><tr>' +
		'<th>Πελάτης</th><th class="ecrm-col-sec">Πάροχος</th><th style="text-align:right">Ποσό</th><th>Κατάσταση</th>' +
		'</tr></thead><tbody>' + body + '</tbody></table></div>' + more;
}

function renderPartner(view, d) {
	var m = d.member || {};
	var statuses = d.statuses || {};
	var down = d.downline || [];
	var commRows = d.commission_rows || [];

	// .ecrm-kv και όχι .ecrm-dl: εκεί το <dt> είναι μικρό κεφαλαίο ΕΤΙΚΕΤΑ και
	// το <dd> η τιμή — δηλαδή το όνομα του ανθρώπου θα γινόταν ψιλό γκρι label
	// και το πλήθος συμβάσεων ο πρωταγωνιστής. Ανάποδη έμφαση, και φάνηκε μόνο
	// στην απόδοση. Εδώ το <b> είναι το όνομα και το <span> το πλήθος.
	var downRows = down.length
		? down.map(function (p) {
			return '<div class="ecrm-kv"><b>' + esc(p.name) + '</b><span>' +
				p.contracts + (p.contracts === 1 ? ' σύμβαση' : ' συμβάσεις') + '</span></div>';
		}).join('')
		: '<div class="ecrm-empty">Κανένα μέλος από κάτω του.</div>';

	view.innerHTML =
		// Το κουμπί επιστροφής ΠΑΝΩ από την κεφαλίδα και όχι μέσα της: το
		// .ecrm-head--row στοιχίζει flex-end, οπότε όσο ψήλωνε η αριστερή
		// στήλη τόσο ξεκρεμόταν το badge στα δεξιά.
		'<div style="margin-bottom:10px">' +
		'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-back>&larr; Η ομάδα μου</button>' +
		'</div>' +
		'<header class="ecrm-head ecrm-head--row">' +
		'<div>' +
		'<h2 class="ecrm-title">' +
		'<span class="ecrm-cell-mark ecrm-cell-mark--cust" style="--h:' + tint(m.name || '') + '">' +
		esc(initials(m.name || '')) + '</span> ' + esc(m.name || '—') +
		(m.is_self ? ' <span class="ecrm-muted">(εσύ)</span>' : '') + '</h2>' +
		'<p class="ecrm-sub">' + esc(m.role_label || '—') + ' · μέλος από ' + esc(fmtDate(m.joined)) + '</p>' +
		'</div>' +
		// Σε κινητό το .ecrm-head--row γίνεται στήλη με align-items:stretch,
		// οπότε ένα γυμνό badge τεντωνόταν σε όλο το πλάτος και διαβαζόταν ως
		// μπάρα. Το περιτύλιγμα τρώει το stretch· το badge μένει στο μέγεθός του.
		'<div>' +
		(m.active
			? '<span class="ecrm-badge ecrm-badge--active">Ενεργός</span>'
			: '<span class="ecrm-badge ecrm-badge--cancelled">Ανενεργός</span>') +
		'</div>' +
		'</header>' +

		kpiCards(d.kpi || {}) +

		'<div class="ecrm-pgrid">' +
		// .ecrm-pgrid__main: η βασική στήλη (1.6fr) έχει πλέον ΔΥΟ κάρτες
		// αντί για μία, άρα χρειάζεται δικό της wrapper -- χωρίς αυτόν το
		// grid θα έβαζε τη δεύτερη κάρτα στη ΣΤΕΝΗ στήλη (1fr, δίπλα) αντί
		// από κάτω στην ίδια, γιατί το .ecrm-pgrid βάζει τα άμεσα παιδιά του
		// ένα ανά στήλη με τη σειρά.
		'<div class="ecrm-pgrid__main">' +
		'<div class="ecrm-card"><div class="ecrm-step">Τελευταίες συμβάσεις</div>' +
		'<div class="ecrm-tablewrap"><table class="ecrm-table"><thead><tr>' +
		// .ecrm-col-sec: η σύμβαση που ήδη έχει ο κώδικας για «φύγε στο κινητό»
		// (@media 767px). Χωρίς αυτήν ο πίνακας κρατούσε και τις τέσσερις στήλες
		// σε 390px και έσπρωχνε την κάρτα 65px έξω από την οθόνη — φάνηκε μόνο
		// σε απόδοση σε πλάτος κινητού. Μένουν Πελάτης και Κατάσταση.
		'<th>Πελάτης</th><th class="ecrm-col-sec">Πάροχος</th><th>Κατάσταση</th>' +
		'<th class="ecrm-col-sec">Ενημερώθηκε</th>' +
		'</tr></thead><tbody>' + recentRows(d.recent || [], statuses) + '</tbody></table></div></div>' +

		// Build queue 07, docs/UI-COMMISSIONS-ROWS.html (§1.8, εγκρίθηκε
		// 25/08) -- δίπλα στις «Τελευταίες συμβάσεις», όχι αντί για αυτές: η
		// μία δείχνει ΤΙ έγινε (κατάσταση), η άλλη ΠΟΣΟ βγάζει (προμήθεια).
		// Ίδιο σχήμα γραμμής με CommissionsController::index(), όχι νέο
		// υπολογισμό -- το πλακίδιο πάνω και αυτή η κάρτα πάντα συμφωνούν
		// γιατί περνούν από το ίδιο CommissionAmount::of() (task 04).
		'<div class="ecrm-card"><div class="ecrm-step">Προμήθεια · ανά σύμβαση</div>' +
		commissionRows(commRows) + '</div>' +
		'</div>' +

		'<div class="ecrm-pgrid__side">' +
		'<div class="ecrm-card"><div class="ecrm-step">Στοιχεία</div>' +
		'<div class="ecrm-kv"><b>' + esc(m.email || '—') + '</b><span>email</span></div>' +
		'<div class="ecrm-kv"><b>' + esc(m.role_label || '—') + '</b><span>ρόλος</span></div>' +
		'<div class="ecrm-kv"><b>' + esc(fmtDate(m.joined)) + '</b><span>ένταξη στην ομάδα</span></div>' +
		'</div>' +
		'<div class="ecrm-card"><div class="ecrm-step">Κάτω δίκτυο</div>' + downRows + '</div>' +
		'</div>' +
		'</div>';

	var back = view.querySelector('[data-back]');
	if (back) { back.addEventListener('click', function () { go('team'); }); }

	view.querySelectorAll('[data-open]').forEach(function (row) {
		row.addEventListener('click', function () { openDetail(this.getAttribute('data-open')); });
	});
}
