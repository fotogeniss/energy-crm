/* Energy CRM — customers: who the partner has signed, and how often.
 *
 * (333) Καρτέλες «Χωρίς αέριο» / «Χωρίς ρεύμα»: πελάτες με ενεργή σύμβαση
 * της μίας ενέργειας και καμία της άλλης (GET /cross-sell). Το «+ Αέριο»
 * είναι η ίδια δίδυμη αίτηση του (326). Mockup: docs/UI-CROSS-SELL.html.
 */

import { api, esc, fetch, H, toast, viewEl } from '@energy-crm/util';
import { fmtDate, initials, tint } from '@energy-crm/format';
import { openCustomerCard, openEdit } from '@energy-crm/navigate';

var customersState = { q: '', scope: 'own', tab: 'all', city: '', category: '' };
export function loadCustomers() {
	var view = viewEl('customers');
	if (customersState.tab !== 'all') { loadCrossSell(view); return; }
	fetch(api('/customers') + '?scope=' + customersState.scope + '&q=' + encodeURIComponent(customersState.q), { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (!d || !d.ok) { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα.</div></div>'; return; }
			var rows = (d.rows || []).map(function (c) {
				return '<tr class="ecrm-rowlink" data-open-customer="' + c.id + '">' +
					'<td><span class="ecrm-cell-cust"><span class="ecrm-cell-mark ecrm-cell-mark--cust" style="--h:' + tint(c.name) + '">' + esc(initials(c.name)) + '</span><span>' + esc(c.name) + '</span></span></td>' +
					'<td class="ecrm-mono ecrm-col-sec">' + esc(c.afm || '—') + '</td>' +
					'<td>' + esc(c.phone || '—') + '</td>' +
					'<td class="ecrm-col-sec">' + esc(c.email || '—') + '</td>' +
					'<td><span class="ecrm-tariff">' + c.contracts + '</span></td>' +
					'<td class="ecrm-muted ecrm-col-sec">' + (c.last_at ? fmtDate(c.last_at) : '—') + '</td>' +
					'</tr>';
			}).join('');
			var table = (d.rows && d.rows.length)
				? '<div class="ecrm-tablewrap"><table class="ecrm-table"><thead><tr><th>Πελάτης</th><th class="ecrm-col-sec">ΑΦΜ</th><th>Τηλέφωνο</th><th class="ecrm-col-sec">Email</th><th>Συμβάσεις</th><th class="ecrm-col-sec">Τελευταία</th></tr></thead><tbody>' + rows + '</tbody></table></div>'
				: '<div class="ecrm-empty">' + (customersState.q
					? 'Δεν βρέθηκαν πελάτες για «' + esc(customersState.q) + '».'
					: 'Δεν έχεις κανέναν πελάτη ακόμα. Ο πρώτος πελάτης προστίθεται αυτόματα με τη «Νέα αίτηση».') + '</div>';
			view.innerHTML =
				'<header class="ecrm-head ecrm-head--row"><div class="ecrm-titlewrap"><span class="ecrm-pageicon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8" cy="11" r="2"/><path d="M5 16.5a3.5 3.5 0 0 1 6 0"/><path d="M14 9h4"/><path d="M14 13h4"/></svg></span><div><h2 class="ecrm-title">Πελάτες</h2><p class="ecrm-sub">' + (d.count || 0) + ' πελάτες · μοναδικοί ανά ΑΦΜ</p></div></div>' +
				'<div class="ecrm-scope"><button type="button" class="ecrm-scope__b' + (customersState.scope==="own"?" is-on":"") + '" data-cuscope="own">Δικά μου</button><button type="button" class="ecrm-scope__b' + (customersState.scope==="team"?" is-on":"") + '" data-cuscope="team">Ομάδας</button></div></header>' +
				tabsMarkup(null) +
				'<div class="ecrm-card"><div class="ecrm-search-row"><div class="ecrm-search"><input type="search" class="ecrm-input" placeholder="Αναζήτηση ονόματος, ΑΦΜ, τηλεφώνου…" value="' + esc(customersState.q) + '" data-cusearch></div></div>' + table + '</div>';
			var se = view.querySelector('[data-cusearch]');
			if (se) { var t; se.addEventListener('input', function () { clearTimeout(t); var v = this.value; t = setTimeout(function () { customersState.q = v; loadCustomers(); }, 350); }); }
			view.querySelectorAll('[data-cuscope]').forEach(function (b) { b.addEventListener('click', function () { customersState.scope = this.getAttribute('data-cuscope'); loadCustomers(); }); });
			wireTabs(view);
			view.querySelectorAll('[data-open-customer]').forEach(function (row) {
				row.addEventListener('click', function () { openCustomerCard(this.getAttribute('data-open-customer')); });
			});
		})
		.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα φόρτωσης.</div></div>'; });
}

// ---------------------------------------------------------------------
// (333) «Χωρίς αέριο / Χωρίς ρεύμα»
// ---------------------------------------------------------------------

var CATEGORY = { home: 'Οικιακό', business: 'Επαγγελματικό', communal: 'Κοινόχρηστο' };

function tabsMarkup(counts) {
	var c = counts || {};
	var tab = function (key, label, n) {
		return '<button type="button" class="ecrm-xsell__tab' + (customersState.tab === key ? ' is-on' : '') + '" data-cutab="' + key + '">' +
			esc(label) + (n != null ? ' <small>' + n + '</small>' : '') + '</button>';
	};
	return '<div class="ecrm-xsell__tabs">' + tab('all', 'Όλοι οι πελάτες') + tab('gas', 'Χωρίς αέριο', c.gas) + tab('power', 'Χωρίς ρεύμα', c.power) + '</div>';
}

function wireTabs(view) {
	view.querySelectorAll('[data-cutab]').forEach(function (b) {
		b.addEventListener('click', function () { customersState.tab = this.getAttribute('data-cutab'); loadCustomers(); });
	});
}

function headMarkup(sub) {
	return '<header class="ecrm-head ecrm-head--row"><div class="ecrm-titlewrap"><div><h2 class="ecrm-title">Πελάτες</h2><p class="ecrm-sub">' + esc(sub) + '</p></div></div>' +
		'<div class="ecrm-scope"><button type="button" class="ecrm-scope__b' + (customersState.scope === 'own' ? ' is-on' : '') + '" data-cuscope="own">Δικά μου</button><button type="button" class="ecrm-scope__b' + (customersState.scope === 'team' ? ' is-on' : '') + '" data-cuscope="team">Ομάδας</button></div></header>';
}

function loadCrossSell(view) {
	var want = customersState.tab;
	var qs = '?want=' + want + '&scope=' + customersState.scope + '&city=' + encodeURIComponent(customersState.city) + '&category=' + encodeURIComponent(customersState.category);
	fetch(api('/cross-sell') + qs, { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (!d || !d.ok) { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα.</div></div>'; return; }
			var has = want === 'gas' ? 'Ρεύμα' : 'Αέριο';
			var plus = want === 'gas' ? '＋ Αέριο' : '＋ Ρεύμα';
			var cityOpts = '<option value="">Πόλη: όλες</option>' + (d.cities || []).map(function (c) {
				return '<option value="' + esc(c) + '"' + (c === customersState.city ? ' selected' : '') + '>' + esc(c) + '</option>';
			}).join('');
			var catOpts = '<option value="">Κατηγορία: όλες</option>' + Object.keys(CATEGORY).map(function (k) {
				return '<option value="' + k + '"' + (k === customersState.category ? ' selected' : '') + '>' + CATEGORY[k] + '</option>';
			}).join('');
			var rows = (d.rows || []).map(function (c) {
				var meta = [c.city, CATEGORY[c.category] || '', c.active_since ? 'ενεργή από ' + fmtDate(c.active_since) : '', c.phone].filter(Boolean).join(' · ');
				var acts = c.draft_id
					? '<button type="button" class="ecrm-btn ecrm-btn--sm" data-xs-draft="' + esc(c.draft_id) + '">Άνοιγμα πρόχειρου</button>'
					: (c.phone ? '<a class="ecrm-btn ecrm-btn--sm" href="tel:' + esc(String(c.phone).replace(/[^\d+]/g, '')) + '">📞 Κλήση</a>' : '') +
						'<button type="button" class="ecrm-btn ecrm-btn--sm ecrm-btn--companion" data-xs-add="' + c.contract_id + '">' + plus + '</button>' +
						'<button type="button" class="ecrm-btn ecrm-btn--sm ecrm-btn--ghost" data-xs-snooze="' + c.id + '">Όχι τώρα</button>';
				return '<div class="ecrm-xsell__row"><div><div class="ecrm-xsell__nm"><a href="#" data-open-customer="' + c.id + '">' + esc(c.name) + '</a>' +
					' <span class="ecrm-xsell__pill">' + esc(has) + '</span>' +
					(c.draft_id ? ' <span class="ecrm-xsell__pill is-draft">αίτηση σε πρόχειρο</span>' : '') + '</div>' +
					'<div class="ecrm-xsell__mt">' + esc(meta) + '</div></div>' +
					'<div class="ecrm-xsell__acts">' + acts + '</div></div>';
			}).join('');
			if (!rows) {
				rows = '<div class="ecrm-empty">Κανένας πελάτης ' + (want === 'gas' ? 'με ρεύμα χωρίς αέριο' : 'με αέριο χωρίς ρεύμα') + ' αυτή τη στιγμή.</div>';
			}
			view.innerHTML = headMarkup((d.counts && d.counts[want]) + ' πελάτες για ' + (want === 'gas' ? 'αέριο' : 'ρεύμα')) +
				tabsMarkup(d.counts) +
				'<div class="ecrm-card"><div class="ecrm-xsell__flt"><select class="ecrm-input" data-xs-city>' + cityOpts + '</select><select class="ecrm-input" data-xs-cat>' + catOpts + '</select></div>' + rows + '</div>';

			wireTabs(view);
			view.querySelectorAll('[data-cuscope]').forEach(function (b) { b.addEventListener('click', function () { customersState.scope = this.getAttribute('data-cuscope'); loadCustomers(); }); });
			view.querySelector('[data-xs-city]').addEventListener('change', function () { customersState.city = this.value; loadCustomers(); });
			view.querySelector('[data-xs-cat]').addEventListener('change', function () { customersState.category = this.value; loadCustomers(); });
			view.querySelectorAll('[data-open-customer]').forEach(function (a) {
				a.addEventListener('click', function (e) { e.preventDefault(); openCustomerCard(this.getAttribute('data-open-customer')); });
			});
			view.querySelectorAll('[data-xs-add]').forEach(function (b) {
				b.addEventListener('click', function () { addTwin(this, this.getAttribute('data-xs-add')); });
			});
			view.querySelectorAll('[data-xs-draft]').forEach(function (b) {
				b.addEventListener('click', function () { openContract(this.getAttribute('data-xs-draft')); });
			});
			view.querySelectorAll('[data-xs-snooze]').forEach(function (b) {
				b.addEventListener('click', function () { snooze(view, this.getAttribute('data-xs-snooze'), want); });
			});
		})
		.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα φόρτωσης.</div></div>'; });
}

function openContract(id) {
	return fetch(api('/contracts/' + id), { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (dd) { openEdit(dd.contract || dd); });
}

// Ίδια διαδρομή με το «+ Αέριο / + Ρεύμα» της καρτέλας αίτησης (326).
function addTwin(btn, contractId) {
	btn.disabled = true;
	fetch(api('/contracts/' + contractId + '/companion'), { method: 'POST', headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (!d || !d.ok || !d.contract_id) { toast((d && d.error) || 'Αποτυχία.', false); btn.disabled = false; return; }
			toast(d.existing ? 'Υπάρχει ήδη: ' + (d.code || '') + '.' : 'Δημιουργήθηκε ' + (d.code || '') + ' — συμπλήρωσε πρόγραμμα και μετρητή.');
			return openContract(d.contract_id);
		})
		.catch(function () { toast('Σφάλμα δικτύου.', false); btn.disabled = false; });
}

function snooze(view, customerId, want) {
	var note = window.prompt('Όχι τώρα — γιατί; (προαιρετικό, π.χ. «δεν έχει δίκτυο αερίου»)', '');
	if (note === null) { return; }
	fetch(api('/customers/' + customerId + '/cross-sell-snooze'), {
		method: 'POST',
		headers: Object.assign({ 'Content-Type': 'application/json' }, H()),
		body: JSON.stringify({ want: want, note: note })
	})
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (!d || !d.ok) { toast((d && d.error) || 'Αποτυχία.', false); return; }
			toast('Δεν θα εμφανιστεί για 90 μέρες.');
			loadCustomers();
		})
		.catch(function () { toast('Σφάλμα δικτύου.', false); });
}
