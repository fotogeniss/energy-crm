/* Energy CRM — Εκκρεμότητες: οι αιτήσεις που κάθονται ανοιχτές πολύ καιρό.
 *
 * Μία κλήση, ένας πίνακας, και ο κοινός διακόπτης «Δικά μου / Ομάδας». Ο
 * διακόπτης είναι ο ΙΔΙΟΣ με της λίστας συμβάσεων — δες @energy-crm/scope. */

import { api, esc, fetch, H, viewEl } from '@energy-crm/util';
import { initials, tint } from '@energy-crm/format';
import { openDetail } from '@energy-crm/navigate';
import { scope, setScope } from '@energy-crm/scope';

export function loadPending() {
	var view = viewEl('pending');
	fetch(api('/notifications') + '?scope=' + scope(), { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) { if (!d || !d.ok) { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα.</div></div>'; return; } renderPending(view, d); })
		.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα φόρτωσης.</div></div>'; });
}

function renderPending(view, d) {
	var rows = d.rows || [];
	var statuses = (window.ECRM && ECRM.statuses) || {};
	var body = rows.map(function (r) {
		return '<tr class="ecrm-rowlink" data-id="' + r.id + '">' +
			'<td><span class="ecrm-cell-cust"><span class="ecrm-cell-mark ecrm-cell-mark--cust" style="--h:' + tint(r.customer) + '">' + esc(initials(r.customer)) + '</span><span>' + esc(r.customer) + '</span></span></td>' +
			'<td class="ecrm-col-sec"><span class="ecrm-code">' + esc(r.code || '') + '</span></td>' +
			'<td><span class="ecrm-badge ecrm-badge--' + esc(r.status) + '">' + esc(r.status_label || statuses[r.status] || r.status) + '</span></td>' +
			'<td><span class="ecrm-agepill' + (r.stale ? ' is-stale' : '') + '">' + r.age_days + ' ημέρες</span></td>' +
			'</tr>';
	}).join('');
	var table = rows.length
		? '<div class="ecrm-tablewrap"><table class="ecrm-table"><thead><tr><th>Πελάτης</th><th class="ecrm-col-sec">Κωδικός</th><th>Κατάσταση</th><th>Ανοιχτή για</th></tr></thead><tbody>' + body + '</tbody></table></div>'
		: '<div class="ecrm-emptybox ecrm-emptybox--big"><span class="ecrm-emptybox__ico">✓</span><div class="ecrm-emptybox__txt">Καμία εκκρεμότητα — όλα εντάξει!</div></div>';

	view.innerHTML =
		'<header class="ecrm-head ecrm-head--row"><div class="ecrm-titlewrap"><span class="ecrm-pageicon">' +
		'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v5"/><circle cx="12" cy="16.5" r=".6"/><path d="M10.3 3.8 2.6 17.5A2 2 0 0 0 4.3 20.5h15.4a2 2 0 0 0 1.7-3L13.7 3.8a2 2 0 0 0-3.4 0z"/></svg></span>' +
		'<div><h2 class="ecrm-title">Εκκρεμότητες</h2><p class="ecrm-sub">' + (d.count || 0) + ' ανοιχτές · ' + (d.stale || 0) + ' πάνω από ' + d.threshold + ' ημέρες</p></div></div>' +
		'<div class="ecrm-scope"><button type="button" class="ecrm-scope__b' + (scope()==="own"?" is-on":"") + '" data-pscope="own">Δικά μου</button><button type="button" class="ecrm-scope__b' + (scope()==="team"?" is-on":"") + '" data-pscope="team">Ομάδας</button></div></header>' +
		'<div class="ecrm-card">' + table + '</div>';

	view.querySelectorAll('.ecrm-rowlink').forEach(function (tr) { tr.addEventListener('click', function () { openDetail(this.getAttribute('data-id')); }); });
	view.querySelectorAll('[data-pscope]').forEach(function (b) { b.addEventListener('click', function () { setScope(this.getAttribute('data-pscope')); loadPending(); }); });
}
