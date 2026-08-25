/* Energy CRM — network: the partner tree below the acting user. */

import { api, esc, fetch, H, toast, viewEl } from '@energy-crm/util';
import { initials, tint } from '@energy-crm/format';
import { openPartner } from '@energy-crm/navigate';

export function loadNetwork() {
	var view = viewEl('network');
	fetch(api('/network'), { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) { renderNetwork(view, (d && d.partners) || []); })
		.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα φόρτωσης.</div></div>'; });
}
function networkIcon() {
	return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="12" r="2.4"/><circle cx="18" cy="6" r="2.4"/><circle cx="18" cy="18" r="2.4"/><path d="M8.2 10.8 15.8 7.2"/><path d="M8.2 13.2 15.8 16.8"/></svg>';
}
function renderNetwork(view, partners) {
	var head =
		'<header class="ecrm-head ecrm-head--row"><div class="ecrm-titlewrap"><span class="ecrm-pageicon">' + networkIcon() + '</span>' +
		'<div><h2 class="ecrm-title">Το δίκτυό μου</h2><p class="ecrm-sub">Οι υποσυνεργάτες σου. Πάτα σε έναν για να δεις τα στατιστικά του.</p></div></div>' +
		'<button type="button" class="ecrm-btn ecrm-btn--amber ecrm-btn--sm" data-show-invite>+ Νέος υποσυνεργάτης</button></header>';

	var bodyCard;
	if (partners.length) {
		// Ο υπότιτλος λέει ρητά «Πάτα σε έναν για να δεις τα στατιστικά του»,
		// αλλά καμία γραμμή δεν είχε ποτέ click handler -- η υπόσχεση της
		// οθόνης της ίδιας δεν εκτελούνταν. Ίδιο μοτίβο με το
		// ecrm-view-team.js: data-member + openPartner(), προϋπάρχουσα
		// συμπεριφορά που έλειπε από εδώ, όχι νέα σχεδιαστική απόφαση.
		var rows = partners.map(function (p) {
			return '<tr class="ecrm-rowlink" data-member="' + p.id + '"><td><span class="ecrm-cell-cust"><span class="ecrm-cell-mark ecrm-cell-mark--cust" style="--h:' + tint(p.name) + '">' + esc(initials(p.name)) + '</span><span>' + esc(p.name) + '</span></span></td>' +
				'<td class="ecrm-col-sec">' + esc(p.email) + '</td><td>' + (p.team_size || 0) + '</td><td>' + (p.contracts || 0) + '</td></tr>';
		}).join('');
		bodyCard = '<div class="ecrm-card"><div class="ecrm-tablewrap"><table class="ecrm-table"><thead><tr><th>Συνεργάτης</th><th class="ecrm-col-sec">Email</th><th>Ομάδα</th><th>Αιτήσεις</th></tr></thead><tbody>' + rows + '</tbody></table></div></div>';
	} else {
		bodyCard = '<div class="ecrm-card"><div class="ecrm-emptybox ecrm-emptybox--big">' +
			'<span class="ecrm-emptybox__ico">' + networkIcon() + '</span>' +
			'<div class="ecrm-emptybox__txt">Δεν έχεις υποσυνεργάτες ακόμα.</div>' +
			'<button type="button" class="ecrm-btn ecrm-btn--amber ecrm-btn--sm" data-show-invite>+ Πρόσκληση νέου υποσυνεργάτη</button></div></div>';
	}

	var inviteForm =
		'<div class="ecrm-card ecrm-addform" data-invitewrap hidden><div class="ecrm-step">Νέος υποσυνεργάτης</div>' +
		'<div class="ecrm-grid">' +
		'<label class="ecrm-field"><span class="ecrm-field__label">Ονοματεπώνυμο</span><input class="ecrm-input" data-nf="name"></label>' +
		'<label class="ecrm-field"><span class="ecrm-field__label">Email</span><input class="ecrm-input" type="email" data-nf="email"></label>' +
		'<label class="ecrm-field"><span class="ecrm-field__label">Κωδικός (προαιρετικό)</span><input class="ecrm-input" data-nf="password" placeholder="αυτόματος αν κενό"></label>' +
		'</div><button type="button" class="ecrm-btn ecrm-btn--primary ecrm-btn--sm" data-invite>+ Πρόσκληση</button>' +
		'<div class="ecrm-ai-status" data-invite-msg></div></div>';

	view.innerHTML = head + bodyCard + inviteForm;

	view.querySelectorAll('[data-member]').forEach(function (row) {
		row.addEventListener('click', function (ev) {
			if (ev.target.closest('button')) { return; }
			openPartner(this.getAttribute('data-member'));
		});
	});
	view.querySelectorAll('[data-show-invite]').forEach(function (b) {
		b.addEventListener('click', function () { var w = view.querySelector('[data-invitewrap]'); if (w) { w.hidden = !w.hidden; if (!w.hidden) w.scrollIntoView({ behavior: 'smooth' }); } });
	});
	var inv = view.querySelector('[data-invite]');
	if (inv) inv.addEventListener('click', function () {
		var get = function (f) { var el = view.querySelector('[data-nf="' + f + '"]'); return el ? el.value : ''; };
		var payload = { name: get('name'), email: get('email'), role: 'ecrm_partner', password: get('password') };
		if (!payload.name || !payload.email) { toast('Συμπλήρωσε όνομα και email.', false); return; }
		this.disabled = true; var b = this;
		fetch(api('/team'), { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H()), body: JSON.stringify(payload) })
			.then(function (r) { return r.json(); })
			.then(function (d) {
				if (!d || !d.ok) { toast((d && d.error) || 'Αποτυχία.', false); return; }
				var msg = view.querySelector('[data-invite-msg]');
				if (msg) msg.textContent = 'Δημιουργήθηκε. Username: ' + d.username + ' · Κωδικός: ' + d.password;
				loadNetwork();
			})
			.catch(function () { toast('Σφάλμα δικτύου.', false); })
			.finally(function () { b.disabled = false; });
	});
}
