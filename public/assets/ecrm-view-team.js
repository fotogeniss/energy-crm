/* Energy CRM — team: the members at each role, and adding one. */

import { api, esc, fetch, H, toast, viewEl } from '@energy-crm/util';
import { initials, tint } from '@energy-crm/format';
import { openPartner } from '@energy-crm/navigate';

export function loadTeam() {
	var view = viewEl('team');
	fetch(api('/team'), { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) { renderTeam(view, d); })
		.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα φόρτωσης.</div></div>'; });
}
function renderTeam(view, d) {
	// Ο Καταχωρητής συγχωνεύτηκε στον Πωλητή (Roles::matrix() v6, 25/08) --
	// το /team επιστρέφει πλέον μόνο Πωλητές (οι Συνεργάτες φιλτράρονται ήδη
	// στο TeamController::index() και ζουν στο /network), οπότε τα δύο tabs
	// δεν χώριζαν πια τίποτα. Ένας πίνακας, χωρίς tabs -- δες
	// docs/UI-TEAM-MERGE-PROMOTE.html (§1.8, εγκρίθηκε 25/08).
	var members = d.members || [];

	// Η στήλη «Τηλέφωνο» τύπωνε ΠΑΝΤΑ παύλα: δεν υπάρχει τηλέφωνο μέλους
	// πουθενά στη βάση, οπότε ήταν στήλη που δεν μπορούσε να δείξει τίποτα —
	// «διακοσμητικό ψέμα», ίδια οικογένεια με την κάρτα «ΑΙ βοήθεια» του §6β.
	// Στη θέση της μπαίνει το m.contracts, που το /team ΗΔΗ έστελνε και που
	// αυτή η οθόνη πετούσε (το ecrm-view-network.js το χρησιμοποιεί κανονικά).
	// Δεδομένο που υπήρχε και αγνοούνταν, πλάι σε στήλη που δεν είχε τι να πει.
	var rows = members.map(function (m) {
		// Η προαγωγή είναι μη αναστρέψιμη με ένα κλικ (αλλάζει ρόλο, όχι
		// κατάσταση) -- εμφανίζεται μόνο σε ενεργά μέλη, ίδιο σύνορο με το
		// mockup. Ο ίδιος confirm() που ήδη χρησιμοποιεί η «Αφαίρεση» παρακάτω,
		// με το ίδιο μήνυμα που εγκρίθηκε στο mockup.
		var promoteBtn = m.active
			? '<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-promote="' + m.id + '" data-name="' + esc(m.name) + '">Προαγωγή σε Συνεργάτη</button> '
			: '';

		return '<tr class="ecrm-rowlink" data-member="' + m.id + '">' +
			'<td><span class="ecrm-cell-cust"><span class="ecrm-cell-mark ecrm-cell-mark--cust" style="--h:' + tint(m.name) + '">' + esc(initials(m.name)) + '</span><span>' + esc(m.name) + '</span></span></td>' +
			'<td class="ecrm-col-sec">' + esc(m.email) + '</td>' +
			'<td class="ecrm-tlnum">' + (m.contracts || 0) + '</td>' +
			'<td>' + (m.active ? '<span class="ecrm-badge ecrm-badge--active">Ενεργός</span>' : '<span class="ecrm-badge ecrm-badge--cancelled">Ανενεργός</span>') + '</td>' +
			'<td>' + promoteBtn +
			'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-toggle="' + m.id + '">' + (m.active ? 'Απενεργοποίηση' : 'Ενεργοποίηση') + '</button> ' +
			'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-remove="' + m.id + '">Αφαίρεση</button></td>' +
			'</tr>';
	}).join('');

	var table = members.length
		? '<div class="ecrm-tablewrap"><table class="ecrm-table"><thead><tr><th>Ονοματεπώνυμο</th><th class="ecrm-col-sec">Email</th><th>Συμβάσεις</th><th>Κατάσταση</th><th>Ενέργειες</th></tr></thead><tbody>' + rows + '</tbody></table></div>'
		: '<div class="ecrm-emptybox"><div class="ecrm-emptybox__txt">Κανένα μέλος στην ομάδα ακόμα.</div></div>';

	var canManage = d.can_manage;
	var addForm = canManage ? (
		'<div class="ecrm-card ecrm-addform" data-addwrap hidden><div class="ecrm-step">Νέο μέλος · Πωλητής</div>' +
		'<div class="ecrm-grid">' +
		'<label class="ecrm-field"><span class="ecrm-field__label">Ονοματεπώνυμο</span><input class="ecrm-input" data-f="name"></label>' +
		'<label class="ecrm-field"><span class="ecrm-field__label">Email</span><input class="ecrm-input" type="email" data-f="email"></label>' +
		'</div><button type="button" class="ecrm-btn ecrm-btn--primary ecrm-btn--sm" data-add-member>+ Προσθήκη</button>' +
		'<div class="ecrm-ai-status" data-member-msg></div></div>'
	) : '';

	view.innerHTML =
		'<header class="ecrm-head"><h2 class="ecrm-title">Η ομάδα μου</h2><p class="ecrm-sub">Διαχείριση πωλητών του γραφείου σου.</p></header>' +
		'<div class="ecrm-card">' +
		'<div class="ecrm-listhead"><span class="ecrm-listhead__count">' + members.length + ' πωλητές</span>' +
		(canManage ? '<button type="button" class="ecrm-btn ecrm-btn--primary ecrm-btn--sm" data-show-add>+ Νέος Πωλητής</button>' : '') + '</div>' +
		table + '</div>' + addForm;

	var showAdd = view.querySelector('[data-show-add]');
	if (showAdd) showAdd.addEventListener('click', function () { var w = view.querySelector('[data-addwrap]'); if (w) { w.hidden = !w.hidden; if (!w.hidden) w.scrollIntoView({ behavior: 'smooth' }); } });
	// Η γραμμή ανοίγει την καρτέλα, ΕΚΤΟΣ αν πατήθηκε κουμπί μέσα της: τα
	// «Απενεργοποίηση» και «Αφαίρεση» κάθονται στην ίδια γραμμή, και χωρίς
	// αυτόν τον έλεγχο κάθε τους πάτημα θα άνοιγε και την καρτέλα από κάτω.
	view.querySelectorAll('[data-member]').forEach(function (row) {
		row.addEventListener('click', function (ev) {
			if (ev.target.closest('button')) { return; }
			openPartner(this.getAttribute('data-member'));
		});
	});
	view.querySelectorAll('[data-toggle]').forEach(function (b) { b.addEventListener('click', function () { teamOp(this.getAttribute('data-toggle'), 'toggle'); }); });
	view.querySelectorAll('[data-remove]').forEach(function (b) { b.addEventListener('click', function () { if (confirm('Αφαίρεση μέλους από την ομάδα;')) teamOp(this.getAttribute('data-remove'), 'remove'); }); });
	view.querySelectorAll('[data-promote]').forEach(function (b) {
		b.addEventListener('click', function () {
			var name = this.getAttribute('data-name');
			var msg = name + ' θα αποκτήσει δικαίωμα να προσκαλεί δικά της/του μέλη και θα βλέπει τη δική ' +
				'της/του ομάδα από κάτω. Παραμένει στη δική σου ομάδα -- δεν φεύγει πουθενά.\n\n' +
				'Προαγωγή σε Συνεργάτη;';
			if (confirm(msg)) teamOp(this.getAttribute('data-promote'), 'promote');
		});
	});
	var addBtn = view.querySelector('[data-add-member]');
	if (addBtn) addBtn.addEventListener('click', function () { addMember(view, this); });
}
function teamOp(id, op) {
	fetch(api('/team/' + id), { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H()), body: JSON.stringify({ op: op }) })
		.then(function (r) { return r.json(); })
		.then(function (res) {
			if (res && res.ok) {
				// Η προαγωγή βγάζει το μέλος από αυτή τη λίστα (πάει στο
				// /network, όπως κάθε Συνεργάτης) -- ίδιο μήνυμα μοτίβο με τα
				// άλλα δύο, χωρίς ξεχωριστό κλάδο για το toast.
				toast(op === 'promote' ? 'Έγινε Συνεργάτης.' : 'Έγινε.');
				loadTeam();
			} else {
				toast((res && res.error) || 'Αποτυχία.', false);
			}
		})
		.catch(function () { toast('Σφάλμα δικτύου.', false); });
}
function addMember(view, btn) {
	var get = function (f) { var el = view.querySelector('[data-f="' + f + '"]'); return el ? el.value : ''; };
	var payload = { name: get('name'), email: get('email'), role: 'ecrm_seller' };
	if (!payload.name || !payload.email) { toast('Συμπλήρωσε όνομα και email.', false); return; }
	btn.disabled = true;
	fetch(api('/team'), { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H()), body: JSON.stringify(payload) })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (!d || !d.ok) { toast((d && d.error) || 'Αποτυχία.', false); return; }
			var msg = view.querySelector('[data-member-msg]');
			// invited:false δεν σημαίνει αποτυχία -- ο λογαριασμός υπάρχει κανονικά,
			// απλά δεν έφυγε το email (π.χ. SMTP δεν έχει ρυθμιστεί ακόμα στην
			// παραγωγή). Ο manager πρέπει να το μάθει ρητά, όχι να υποθέσει ότι
			// στάλθηκε -- βλ. docs/UI-TEAM-INVITE.html (§1.8, εγκρίθηκε).
			if (msg) msg.textContent = d.invited
				? 'Δημιουργήθηκε. Username: ' + d.username + '. Στάλθηκε email πρόσκλησης με σύνδεσμο ορισμού κωδικού (ισχύει 24 ώρες).'
				: 'Δημιουργήθηκε ο λογαριασμός ' + d.username + ', αλλά το email πρόσκλησης ΔΕΝ στάλθηκε. Δώσε του το username και πες του να πατήσει «Ξέχασα τον κωδικό» στην οθόνη σύνδεσης.';
			loadTeam();
		})
		.catch(function () { toast('Σφάλμα δικτύου.', false); })
		.finally(function () { btn.disabled = false; });
}
