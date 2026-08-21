/* Energy CRM — team: the members at each role, and adding one. */

import { api, esc, fetch, H, toast, viewEl } from '@energy-crm/util';
import { initials, tint } from '@energy-crm/format';
import { openPartner } from '@energy-crm/navigate';

var teamTab = 'ecrm_seller';
export function loadTeam() {
	var view = viewEl('team');
	fetch(api('/team'), { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) { renderTeam(view, d); })
		.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα φόρτωσης.</div></div>'; });
}
function renderTeam(view, d) {
	var members = d.members || [];
	var roleLabel = { ecrm_seller: 'Πωλητής', ecrm_registrar: 'Καταχωρητής' };
	var plural = { ecrm_seller: 'πωλητές', ecrm_registrar: 'καταχωρητές' };
	var list = members.filter(function (m) { return m.role === teamTab; });

	var tabs = ['ecrm_seller', 'ecrm_registrar'].map(function (r) {
		var n = members.filter(function (m) { return m.role === r; }).length;
		return '<button type="button" class="ecrm-tab2' + (teamTab === r ? ' is-on' : '') + '" data-ttab="' + r + '">' + (r === 'ecrm_seller' ? 'Πωλητές' : 'Καταχωρητές') + ' <span>' + n + '</span></button>';
	}).join('');

	// Η στήλη «Τηλέφωνο» τύπωνε ΠΑΝΤΑ παύλα: δεν υπάρχει τηλέφωνο μέλους
	// πουθενά στη βάση, οπότε ήταν στήλη που δεν μπορούσε να δείξει τίποτα —
	// «διακοσμητικό ψέμα», ίδια οικογένεια με την κάρτα «ΑΙ βοήθεια» του §6β.
	// Στη θέση της μπαίνει το m.contracts, που το /team ΗΔΗ έστελνε και που
	// αυτή η οθόνη πετούσε (το ecrm-view-network.js το χρησιμοποιεί κανονικά).
	// Δεδομένο που υπήρχε και αγνοούνταν, πλάι σε στήλη που δεν είχε τι να πει.
	var rows = list.map(function (m) {
		return '<tr class="ecrm-rowlink" data-member="' + m.id + '">' +
			'<td><span class="ecrm-cell-cust"><span class="ecrm-cell-mark ecrm-cell-mark--cust" style="--h:' + tint(m.name) + '">' + esc(initials(m.name)) + '</span><span>' + esc(m.name) + '</span></span></td>' +
			'<td>' + esc(m.email) + '</td>' +
			'<td class="ecrm-tlnum">' + (m.contracts || 0) + '</td>' +
			'<td>' + (m.active ? '<span class="ecrm-badge ecrm-badge--active">Ενεργός</span>' : '<span class="ecrm-badge ecrm-badge--cancelled">Ανενεργός</span>') + '</td>' +
			'<td><button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-toggle="' + m.id + '">' + (m.active ? 'Απενεργοποίηση' : 'Ενεργοποίηση') + '</button> ' +
			'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-remove="' + m.id + '">Αφαίρεση</button></td>' +
			'</tr>';
	}).join('');

	var table = list.length
		? '<div class="ecrm-tablewrap"><table class="ecrm-table"><thead><tr><th>Ονοματεπώνυμο</th><th>Email</th><th>Συμβάσεις</th><th>Κατάσταση</th><th>Ενέργειες</th></tr></thead><tbody>' + rows + '</tbody></table></div>'
		: '<div class="ecrm-emptybox"><div class="ecrm-emptybox__txt">Κανένα μέλος ' + (teamTab === 'ecrm_seller' ? 'στους πωλητές' : 'στους καταχωρητές') + ' ακόμα.</div></div>';

	var canManage = d.can_manage;
	var addForm = canManage ? (
		'<div class="ecrm-card ecrm-addform" data-addwrap hidden><div class="ecrm-step">Νέο μέλος · ' + roleLabel[teamTab] + '</div>' +
		'<div class="ecrm-grid">' +
		'<label class="ecrm-field"><span class="ecrm-field__label">Ονοματεπώνυμο</span><input class="ecrm-input" data-f="name"></label>' +
		'<label class="ecrm-field"><span class="ecrm-field__label">Email</span><input class="ecrm-input" type="email" data-f="email"></label>' +
		'<label class="ecrm-field"><span class="ecrm-field__label">Κωδικός (προαιρετικό)</span><input class="ecrm-input" data-f="password" placeholder="αυτόματος αν κενό"></label>' +
		'</div><button type="button" class="ecrm-btn ecrm-btn--primary ecrm-btn--sm" data-add-member>+ Προσθήκη</button>' +
		'<div class="ecrm-ai-status" data-member-msg></div></div>'
	) : '';

	view.innerHTML =
		'<header class="ecrm-head"><h2 class="ecrm-title">Η ομάδα μου</h2><p class="ecrm-sub">Διαχείριση πωλητών και καταχωρητών του γραφείου σου.</p></header>' +
		'<div class="ecrm-card">' +
		'<div class="ecrm-tabs2">' + tabs + '</div>' +
		'<div class="ecrm-listhead"><span class="ecrm-listhead__count">' + list.length + ' ' + plural[teamTab] + '</span>' +
		(canManage ? '<button type="button" class="ecrm-btn ecrm-btn--primary ecrm-btn--sm" data-show-add>+ Νέος ' + roleLabel[teamTab] + '</button>' : '') + '</div>' +
		table + '</div>' + addForm;

	view.querySelectorAll('[data-ttab]').forEach(function (b) { b.addEventListener('click', function () { teamTab = this.getAttribute('data-ttab'); renderTeam(view, d); }); });
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
	var addBtn = view.querySelector('[data-add-member]');
	if (addBtn) addBtn.addEventListener('click', function () { addMember(view, this, teamTab); });
}
function teamOp(id, op) {
	fetch(api('/team/' + id), { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H()), body: JSON.stringify({ op: op }) })
		.then(function (r) { return r.json(); })
		.then(function (res) { if (res && res.ok) { toast('Έγινε.'); loadTeam(); } else { toast((res && res.error) || 'Αποτυχία.', false); } })
		.catch(function () { toast('Σφάλμα δικτύου.', false); });
}
function addMember(view, btn, role) {
	var get = function (f) { var el = view.querySelector('[data-f="' + f + '"]'); return el ? el.value : ''; };
	var payload = { name: get('name'), email: get('email'), role: role || get('role') || 'ecrm_seller', password: get('password') };
	if (!payload.name || !payload.email) { toast('Συμπλήρωσε όνομα και email.', false); return; }
	btn.disabled = true;
	fetch(api('/team'), { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H()), body: JSON.stringify(payload) })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (!d || !d.ok) { toast((d && d.error) || 'Αποτυχία.', false); return; }
			var msg = view.querySelector('[data-member-msg]');
			if (msg) msg.textContent = 'Δημιουργήθηκε. Username: ' + d.username + ' · Κωδικός: ' + d.password + ' (κράτησέ τον τώρα)';
			loadTeam();
		})
		.catch(function () { toast('Σφάλμα δικτύου.', false); })
		.finally(function () { btn.disabled = false; });
}
