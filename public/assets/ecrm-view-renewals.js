/* Energy CRM — renewals: contracts running out, and the ones already past it.
 *
 * The only view that sends the user to the form rather than to a detail
 * screen: renewing is filling in a new contract from an old one. */

import { api, esc, fetch, H, toast, viewEl } from '@energy-crm/util';
import { fmtDate, initials, svgIcon, tint } from '@energy-crm/format';
import { openEdit } from '@energy-crm/navigate';

var renewState = { scope: 'own' };
export function loadRenewals() {
	var view = viewEl('renewals');
	fetch(api('/renewals') + '?scope=' + renewState.scope, { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) { if (!d || !d.ok) { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα.</div></div>'; return; } renderRenewals(view, d); })
		.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα φόρτωσης.</div></div>'; });
}
function renderRenewals(view, d) {
	var rows = d.rows || [];
	var body = rows.map(function (r) {
		var pill, cls;
		if (r.expired) { pill = 'Έληξε πριν ' + Math.abs(r.days_left) + 'η'; cls = 'is-expired'; }
		else if (r.days_left <= 30) { pill = 'Λήγει σε ' + r.days_left + 'η'; cls = 'is-soon'; }
		else { pill = 'Λήγει σε ' + r.days_left + 'η'; cls = ''; }
		return '<tr>' +
			'<td><span class="ecrm-cell-cust"><span class="ecrm-cell-mark ecrm-cell-mark--cust" style="--h:' + tint(r.customer) + '">' + esc(initials(r.customer)) + '</span><span>' + esc(r.customer) + '</span></span></td>' +
			'<td><span class="ecrm-code">' + esc(r.code || '') + '</span></td>' +
			'<td>' + esc(r.provider_name || '—') + '</td>' +
			'<td>' + (r.end_date ? fmtDate(r.end_date) : '—') + '</td>' +
			'<td><span class="ecrm-agepill ' + cls + '">' + pill + '</span></td>' +
			'<td><button type="button" class="ecrm-btn ecrm-btn--primary ecrm-btn--sm" data-renew="' + r.id + '">' + svgIcon('edit') + ' Ανανέωση</button></td>' +
			'</tr>';
	}).join('');
	var table = rows.length
		? '<div class="ecrm-tablewrap"><table class="ecrm-table"><thead><tr><th>Πελάτης</th><th>Κωδικός</th><th>Πάροχος</th><th>Λήξη</th><th>Κατάσταση</th><th></th></tr></thead><tbody>' + body + '</tbody></table></div>'
		: '<div class="ecrm-emptybox ecrm-emptybox--big"><span class="ecrm-emptybox__ico">✓</span><div class="ecrm-emptybox__txt">Καμία σύμβαση δεν λήγει σύντομα.</div></div>';
	view.innerHTML =
		'<header class="ecrm-head ecrm-head--row"><div class="ecrm-titlewrap"><span class="ecrm-pageicon">' +
		'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/><path d="M3 21v-5h5"/></svg></span>' +
		'<div><h2 class="ecrm-title">Λήξεις & Ανανεώσεις</h2><p class="ecrm-sub">' + (d.count || 0) + ' συμβάσεις λήγουν έως ' + d.window + ' ημέρες · ' + (d.soon || 0) + ' άμεσα</p></div></div>' +
		'<div class="ecrm-scope"><button type="button" class="ecrm-scope__b' + (renewState.scope==="own"?" is-on":"") + '" data-rscope="own">Δικά μου</button><button type="button" class="ecrm-scope__b' + (renewState.scope==="team"?" is-on":"") + '" data-rscope="team">Ομάδας</button></div></header>' +
		'<div class="ecrm-card">' + table + '</div>';

	view.querySelectorAll('[data-rscope]').forEach(function (b) { b.addEventListener('click', function () { renewState.scope = this.getAttribute('data-rscope'); loadRenewals(); }); });
	view.querySelectorAll('[data-renew]').forEach(function (b) {
		b.addEventListener('click', function () {
			var id = this.getAttribute('data-renew'); var btn = this; btn.disabled = true;
			fetch(api('/contracts/' + id + '/renew'), { method: 'POST', headers: H(true) })
				.then(function (r) { return r.json(); })
				.then(function (d) {
					btn.disabled = false;
					if (d && d.ok && d.contract_id) {
						toast('Δημιουργήθηκε ανανέωση — άνοιξε για επεξεργασία.');
						// load the new draft into the form
						fetch(api('/contracts/' + d.contract_id), { headers: H() })
							.then(function (r) { return r.json(); })
							.then(function (dd) { var c = dd.contract || dd; openEdit(c); });
					} else { toast((d && d.error) || 'Αποτυχία.', false); }
				})
				.catch(function () { btn.disabled = false; toast('Σφάλμα δικτύου.', false); });
		});
	});
}
