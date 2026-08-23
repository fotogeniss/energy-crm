/* Energy CRM — customers: who the partner has signed, and how often. */

import { api, esc, fetch, H, viewEl } from '@energy-crm/util';
import { fmtDate, initials, tint } from '@energy-crm/format';

var customersState = { q: '', scope: 'own' };
export function loadCustomers() {
	var view = viewEl('customers');
	fetch(api('/customers') + '?scope=' + customersState.scope + '&q=' + encodeURIComponent(customersState.q), { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (!d || !d.ok) { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα.</div></div>'; return; }
			var rows = (d.rows || []).map(function (c) {
				return '<tr>' +
					'<td><span class="ecrm-cell-cust"><span class="ecrm-cell-mark ecrm-cell-mark--cust" style="--h:' + tint(c.name) + '">' + esc(initials(c.name)) + '</span><span>' + esc(c.name) + '</span></span></td>' +
					'<td class="ecrm-mono">' + esc(c.afm || '—') + '</td>' +
					'<td>' + esc(c.phone || '—') + '</td>' +
					'<td>' + esc(c.email || '—') + '</td>' +
					'<td><span class="ecrm-tariff">' + c.contracts + '</span></td>' +
					'<td class="ecrm-muted">' + (c.last_at ? fmtDate(c.last_at) : '—') + '</td>' +
					'</tr>';
			}).join('');
			var table = (d.rows && d.rows.length)
				? '<div class="ecrm-tablewrap"><table class="ecrm-table"><thead><tr><th>Πελάτης</th><th>ΑΦΜ</th><th>Τηλέφωνο</th><th>Email</th><th>Συμβάσεις</th><th>Τελευταία</th></tr></thead><tbody>' + rows + '</tbody></table></div>'
				: '<div class="ecrm-empty">' + (customersState.q
					? 'Δεν βρέθηκαν πελάτες για «' + esc(customersState.q) + '».'
					: 'Δεν έχεις κανέναν πελάτη ακόμα. Ο πρώτος πελάτης προστίθεται αυτόματα με τη «Νέα αίτηση».') + '</div>';
			view.innerHTML =
				'<header class="ecrm-head ecrm-head--row"><div class="ecrm-titlewrap"><span class="ecrm-pageicon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8" cy="11" r="2"/><path d="M5 16.5a3.5 3.5 0 0 1 6 0"/><path d="M14 9h4"/><path d="M14 13h4"/></svg></span><div><h2 class="ecrm-title">Πελάτες</h2><p class="ecrm-sub">' + (d.count || 0) + ' πελάτες · μοναδικοί ανά ΑΦΜ</p></div></div>' +
				'<div class="ecrm-scope"><button type="button" class="ecrm-scope__b' + (customersState.scope==="own"?" is-on":"") + '" data-cuscope="own">Δικά μου</button><button type="button" class="ecrm-scope__b' + (customersState.scope==="team"?" is-on":"") + '" data-cuscope="team">Ομάδας</button></div></header>' +
				'<div class="ecrm-card"><div class="ecrm-search-row"><div class="ecrm-search"><input type="search" class="ecrm-input" placeholder="Αναζήτηση ονόματος, ΑΦΜ, τηλεφώνου…" value="' + esc(customersState.q) + '" data-cusearch></div></div>' + table + '</div>';
			var se = view.querySelector('[data-cusearch]');
			if (se) { var t; se.addEventListener('input', function () { clearTimeout(t); var v = this.value; t = setTimeout(function () { customersState.q = v; loadCustomers(); }, 350); }); }
			view.querySelectorAll('[data-cuscope]').forEach(function (b) { b.addEventListener('click', function () { customersState.scope = this.getAttribute('data-cuscope'); loadCustomers(); }); });
		})
		.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα φόρτωσης.</div></div>'; });
}
