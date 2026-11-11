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
 *
 * ## (358b) Νέα εμφάνιση «Signature»
 *
 * sigPartnerHTML(): ίδια κεφαλίδα και πλέγμα με την καρτέλα πελάτη, τα ίδια
 * τέσσερα νούμερα με τις ίδιες υποσημειώσεις (kpiParts(), κοινό και για τις
 * δύο εμφανίσεις -- ο κανόνας του παρονομαστή δεν γράφεται δεύτερη φορά), οι
 * συμβάσεις και οι προμήθειες ως λίστες με το λογότυπο του παρόχου. Η κάρτα
 * «Πάροχοι» είναι η ίδια (providersCard), με την ίδια καλωδίωση.
 */

import { api, checkedProviderIds, esc, fetch, H, markSubViewOpen, providerChipsHtml, providerMark, toast, viewEl, wireProviderChips } from '@energy-crm/util';
import { fmtDate, initials, tint } from '@energy-crm/format';
import { go, openDetail } from '@energy-crm/navigate';

/** Κάτω από αυτό, ένα ποσοστό είναι θόρυβος και όχι μέτρηση. */
var MIN_SAMPLE = 5;

export function openPartner(id) {
	go('partner');
	markSubViewOpen();
	loadPartner(id);
}

/**
 * Δύο αιτήματα μαζί (279): η ίδια η καρτέλα (KPI, ιστορικό) και η κάρτα
 * «Πάροχοι» -- δεν έχουν σχέση μεταξύ τους, οπότε δεν περιμένει το ένα το
 * άλλο. Ξεχωριστή συνάρτηση από το openPartner() ώστε η αποθήκευση παρόχων
 * να μπορεί να ξαναφορτώσει την καρτέλα χωρίς να προσθέσει δεύτερη εγγραφή
 * ιστορικού (markSubViewOpen() τρέχει μία φορά, στο άνοιγμα).
 */
function loadPartner(id) {
	var view = viewEl('partner');
	if (!view) { return; }
	view.innerHTML = '<div class="ecrm-loading">Φόρτωση…</div>';
	Promise.all([
		fetch(api('/team/' + id), { headers: H() }).then(function (r) { return r.json(); }),
		fetch(api('/team/' + id + '/providers'), { headers: H() }).then(function (r) { return r.json(); }).catch(function () { return null; }),
	]).then(function (res) {
		var d = res[0], p = res[1];
		if (!d || !d.ok) {
			view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">' +
				esc((d && d.error) || 'Δεν βρέθηκε.') + '</div></div>';
			return;
		}
		renderPartner(view, d, id, p);
	}).catch(function () {
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
	var p = kpiParts(k);

	return '<div class="ecrm-kpis' + (p.money ? ' ecrm-kpis--4' : '') + '">' +
		kpi('Συμβάσεις μήνα', p.month + deltaChip(p.month, p.prev), esc(p.monthFoot)) +
		kpi('Επιτυχία', p.rate, esc(p.rateFoot)) +
		kpi('Μέσος χρόνος ως υπογραφή', p.days, esc(p.daysFoot)) +
		(p.money ? kpi('Προμήθεια', p.total, esc(p.commFoot)) : '') +
		'</div>';
}

/* Τα νούμερα και οι υποσημειώσεις των πλακιδίων, χωρίς markup: τα ίδια και
 * για τις δύο εμφανίσεις. */
function kpiParts(k) {
	var m = k.month || { month: 0, prev: 0 };
	var s = k.success || { payable: 0, settled: 0 };
	var g = k.sign || { avg: null, sample: 0 };
	// (318) null = ο θεατής δεν έχει δικαίωμα προμηθειών (Back office): ο
	// server δεν έστειλε ποσά, και το πλακίδιο «Προμήθεια» δεν μπαίνει.
	var money = k.commission !== null;
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

	return {
		month: m.month, prev: m.prev, monthFoot: monthFoot,
		rate: rate, rateFoot: rateFoot,
		days: days, daysFoot: daysFoot,
		money: money, total: euro(c.total),
		commFoot: c.unpaid > 0
			? euro(c.unpaid) + ' δεν έχει πληρωθεί ακόμη'
			: (c.count ? 'όλα πληρωμένα' : 'καμία πληρωτέα σύμβαση'),
	};
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

/**
 * Η κάρτα «Πάροχοι» (279, §2 της μακέτας) -- τι βλέπει το μέλος αυτό, και αν
 * επιτρέπεται σε ΕΣΕΝΑ να το αλλάξεις. Το `prov` είναι η απάντηση του GET
 * /team/{id}/providers· `null`/`!ok` όταν απέτυχε το αίτημα (δίκτυο, 404 --
 * δεν εμποδίζει την υπόλοιπη καρτέλα, απλά δεν δείχνει τίποτα εδώ.
 */
function providersCard(prov) {
	if (!prov || !prov.ok) { return ''; }

	if (prov.unrestricted) {
		return '<div class="ecrm-card"><div class="ecrm-step">Πάροχοι</div>' +
			'<span class="ecrm-badge ecrm-badge--active">Ολοι (διαχειριστής)</span></div>';
	}

	if (prov.editable) {
		return '<div class="ecrm-card" data-provcard>' +
			'<div class="ecrm-step">Πάροχοι <span><a href="#" class="ecrm-plink" data-pall>Ολοι</a><a href="#" class="ecrm-plink" data-pnone>Κανένας</a></span></div>' +
			providerChipsHtml(prov.providers, prov.granted, false) +
			'<button type="button" class="ecrm-btn ecrm-btn--primary ecrm-btn--sm" data-prov-save>Αποθήκευση</button>' +
			'<div class="ecrm-pconfirm" data-prov-confirm hidden></div>' +
			'</div>';
	}

	return '<div class="ecrm-card"><div class="ecrm-step">Πάροχοι</div>' +
		providerChipsHtml(prov.providers, prov.granted, true) +
		'<div class="ecrm-hint">Τους ορίζει ο υπεύθυνός του.</div></div>';
}

/** Καρφώνει τα κλικ της κάρτας «Πάροχοι» -- μόνο όταν είναι επεξεργάσιμη. */
function wireProvidersCard(view, memberId, memberName, prov) {
	if (!prov || !prov.ok || !prov.editable) { return; }

	var card = view.querySelector('[data-provcard]');
	if (!card) { return; }

	wireProviderChips(card);

	var original = (prov.granted || []).map(function (id) { return parseInt(id, 10); });
	var confirmBox = card.querySelector('[data-prov-confirm]');
	var confirmed = false;

	// Αλλαγή στην επιλογή μετά από confirm ζητά νέα επιβεβαίωση -- αλλιώς ένα
	// «Συνέχεια» θα κάλυπτε και μια εντελώς διαφορετική αφαίρεση.
	card.querySelectorAll('.ecrm-pchip[data-pchip]').forEach(function (b) {
		b.addEventListener('click', function () { confirmed = false; confirmBox.hidden = true; confirmBox.innerHTML = ''; });
	});

	var saveBtn = card.querySelector('[data-prov-save]');
	if (!saveBtn) { return; }

	saveBtn.addEventListener('click', function () {
		var requested = checkedProviderIds(card);
		var removedNames = (prov.providers || [])
			.filter(function (pr) {
				var pid = parseInt(pr.id, 10);
				return original.indexOf(pid) !== -1 && requested.indexOf(pid) === -1;
			})
			.map(function (pr) { return pr.name; });

		if (removedNames.length && !confirmed) {
			confirmBox.hidden = false;
			confirmBox.innerHTML = 'Αφαιρείς <b>' + esc(removedNames.join(', ')) + '</b>. Αν ο/η ' + esc(memberName) +
				' έχει δική του/της ομάδα, το χάνουν και όλοι από κάτω του/της. Οι αιτήσεις που έχουν ήδη γίνει ' +
				'μένουν όπως είναι.<br><button type="button" class="ecrm-btn ecrm-btn--danger ecrm-btn--sm" ' +
				'data-prov-confirm-yes style="margin-top:8px">Συνέχεια</button>';
			var yes = confirmBox.querySelector('[data-prov-confirm-yes]');
			if (yes) yes.addEventListener('click', function () { confirmed = true; saveProviders(memberId, requested); });
			return;
		}

		saveProviders(memberId, requested);
	});
}

function saveProviders(memberId, providerIds) {
	fetch(api('/team/' + memberId + '/providers'), {
		method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H()),
		body: JSON.stringify({ provider_ids: providerIds }),
	})
		.then(function (r) { return r.json(); })
		.then(function (res) {
			if (!res || !res.ok) { toast((res && res.error) || 'Αποτυχία.', false); return; }
			toast(res.below > 0 ? 'Αποθηκεύτηκε -- επηρέασε και ' + res.below + ' από κάτω.' : 'Αποθηκεύτηκε.');
			loadPartner(memberId);
		})
		.catch(function () { toast('Σφάλμα δικτύου.', false); });
}

function renderPartner(view, d, memberId, prov) {
	if (isSignature()) {
		view.innerHTML = sigPartnerHTML(d, prov);
		wirePartner(view, memberId, (d.member || {}).name || '', prov);
		return;
	}
	var m = d.member || {};
	var statuses = d.statuses || {};
	var down = d.downline || [];
	var commRows = d.commission_rows || [];
	// (318) null = χωρίς δικαίωμα προμηθειών· η κάρτα δεν μπαίνει καθόλου.
	var showComm = d.commission_rows !== null;

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

		providersCard(prov) +

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
		(showComm
			? '<div class="ecrm-card"><div class="ecrm-step">Προμήθεια · ανά σύμβαση</div>' +
				commissionRows(commRows) + '</div>'
			: '') +
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

	wirePartner(view, memberId, m.name || '', prov);
}

function wirePartner(view, memberId, name, prov) {
	var back = view.querySelector('[data-back]');
	if (back) { back.addEventListener('click', function () { go('team'); }); }

	view.querySelectorAll('[data-open]').forEach(function (row) {
		row.addEventListener('click', function () { openDetail(this.getAttribute('data-open')); });
	});

	wireProvidersCard(view, memberId, name, prov);
}

// ---------------------------------------------------------------------
// (358b) «Signature»: η καρτέλα συνεργάτη
// ---------------------------------------------------------------------

function isSignature() {
	var app = document.getElementById('ecrm-app');
	return !!app && app.getAttribute('data-ui') === 'signature';
}

function sigFact(label, value) {
	return '<div class="sig-fact' + (value ? '' : ' is-empty') + '"><small>' + esc(label) + '</small><b>' + (value ? esc(value) : '—') + '</b></div>';
}

function sigPartnerHTML(d, prov) {
	var m = d.member || {};
	var statuses = d.statuses || {};
	var down = d.downline || [];
	var recent = d.recent || [];
	var commRows = d.commission_rows || [];
	var showComm = d.commission_rows !== null;
	var p = kpiParts(d.kpi || {});
	var name = m.name || '—';

	var delta = p.month - p.prev;
	var kpis = '<div class="sig-kpis' + (p.money ? '' : ' sig-kpis--3') + '">' +
		'<div><small>Συμβάσεις μήνα</small><b>' + esc(String(p.month)) +
			(delta ? ' <span class="sig-delta' + (delta > 0 ? ' is-up' : ' is-down') + '">' + esc((delta > 0 ? '+' : '') + delta) + '</span>' : '') +
			'</b><em>' + esc(p.monthFoot) + '</em></div>' +
		'<div><small>Επιτυχία</small><b>' + esc(p.rate) + '</b><em>' + esc(p.rateFoot) + '</em></div>' +
		'<div><small>Μέχρι την υπογραφή</small><b>' + esc(p.days) + '</b><em>' + esc(p.daysFoot) + '</em></div>' +
		(p.money ? '<div><small>Προμήθεια</small><b>' + esc(p.total) + '</b><em>' + esc(p.commFoot) + '</em></div>' : '') +
	'</div>';

	var recentList = recent.length
		? '<ul class="sig-paylist sig-custlist">' + recent.map(function (r) {
			return '<li class="ecrm-rowlink" data-open="' + esc(String(r.id)) + '">' +
				'<span class="sig-crow__prov">' + providerMark(r.provider, r.logo) + '</span>' +
				'<span class="sig-paylist__who"><b>' + esc(r.customer) + '</b><small>' + esc([r.code, r.provider].filter(Boolean).join(' · ')) + '</small></span>' +
				'<span class="ecrm-badge ecrm-badge--' + esc(r.status) + '">' + esc(statuses[r.status] || r.status) + '</span>' +
				'<span class="sig-custlist__end">' + esc(fmtDate(r.updated)) + '</span>' +
			'</li>';
		}).join('') + '</ul>'
		: '<div class="sig-tempty"><b>Καμία σύμβαση ακόμη.</b></div>';

	var commList = commRows.length
		? '<ul class="sig-paylist sig-custlist">' + commRows.map(function (r) {
			return '<li>' +
				'<span class="sig-crow__prov">' + providerMark(r.provider, r.logo) + '</span>' +
				'<span class="sig-paylist__who"><b>' + esc(r.customer) + '</b><small>' + esc([r.code, r.provider].filter(Boolean).join(' · ')) + '</small></span>' +
				(r.paid ? '<em class="is-ok">Πληρώθηκε</em>' : '<em>Εκκρεμεί</em>') +
				'<b class="sig-paylist__amt">' + esc(euro(r.amount)) + '</b>' +
			'</li>';
		}).join('') + '</ul>' +
			(commRows.length >= 10 ? '<p class="sig-pmore">Δες όλες στις Προμήθειες.</p>' : '')
		: '<div class="sig-tempty"><b>Καμία προμήθεια ακόμη.</b></div>';

	var downList = down.length
		? '<ul class="sig-pdown">' + down.map(function (x) {
			return '<li><span class="sig-pdown__av" style="--h:' + tint(x.name || '') + '">' + esc(initials(x.name || '')) + '</span>' +
				'<b>' + esc(x.name) + '</b><small>' + esc(String(x.contracts)) + (x.contracts === 1 ? ' σύμβαση' : ' συμβάσεις') + '</small></li>';
		}).join('') + '</ul>'
		: '<div class="sig-tempty"><b>Κανένα μέλος από κάτω του.</b></div>';

	var sub = [m.role_label, m.joined ? 'μέλος από ' + fmtDate(m.joined) : ''].filter(Boolean).join(' · ');

	return '<div class="sig-detail sig-cust sig-partner">' +
		'<button type="button" class="sig-detail__back" data-back><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg> Η ομάδα μου</button>' +
		'<header class="sig-dh">' +
			'<span class="sig-dh__av" style="--h:' + tint(m.name || '') + '">' + esc(initials(m.name || '')) + '</span>' +
			'<div class="sig-dh__who"><h2>' + esc(name) + (m.is_self ? ' <span class="sig-partner__self">εσύ</span>' : '') + '</h2><small>' + esc(sub) + '</small></div>' +
			'<div class="sig-dh__acts">' +
				(m.active ? '<span class="sig-age is-ok">Ενεργός</span>' : '<span class="sig-age">Ανενεργός</span>') +
				(m.email ? '<a class="ecrm-btn ecrm-btn--ghost" href="' + esc('mailto:' + m.email) + '">Email</a>' : '') +
			'</div>' +
		'</header>' +
		kpis +
		'<div class="sig-dgrid"><div class="sig-dmain">' +
			'<section class="ecrm-card sig-dcard"><h3 class="sig-ch">Τελευταίες συμβάσεις</h3>' + recentList + '</section>' +
			(showComm ? '<section class="ecrm-card sig-dcard"><h3 class="sig-ch">Προμήθεια ανά σύμβαση</h3>' + commList + '</section>' : '') +
		'</div><aside class="sig-dside">' +
			'<section class="ecrm-card sig-dcard"><h3 class="sig-ch">Στοιχεία</h3><div class="sig-facts sig-facts--2">' +
				sigFact('Ρόλος', m.role_label) + sigFact('Στην ομάδα από', m.joined ? fmtDate(m.joined) : '') +
				'<div class="sig-fact is-wide' + (m.email ? '' : ' is-empty') + '"><small>Email</small><b>' + esc(m.email || '—') + '</b></div>' +
			'</div></section>' +
			providersCard(prov) +
			'<section class="ecrm-card sig-dcard"><h3 class="sig-ch">Κάτω δίκτυο <b>' + esc(String(down.length)) + '</b></h3>' + downList + '</section>' +
		'</aside></div>' +
	'</div>';
}
