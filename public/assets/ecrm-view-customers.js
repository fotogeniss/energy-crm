/* Energy CRM — customers: who the partner has signed, and how often.
 *
 * (333) Καρτέλες «Χωρίς αέριο» / «Χωρίς ρεύμα»: πελάτες με ενεργή σύμβαση
 * της μίας ενέργειας και καμία της άλλης (GET /cross-sell). Το «+ Αέριο»
 * είναι η ίδια δίδυμη αίτηση του (326). Mockup: docs/UI-CROSS-SELL.html.
 *
 * (353i) Στη νέα εμφάνιση «Signature» ο πίνακας γίνεται πλέγμα από κάρτες
 * (sigCustomersHTML). Ίδια δεδομένα, ίδιο data-open-customer, ίδια καρτέλα πελάτη.
 *
 * (377) Οι κάρτες δεν κλιμακώνονταν: 6 σε μια οθόνη, και ο server σταματούσε
 * σιωπηλά στους 500. Στη νέα εμφάνιση η λίστα γίνεται πυκνός πίνακας με
 * σελίδες των 50, ταξινόμηση και φίλτρα με μετρητές από τον server
 * (GET /customers?page=…). docs/UI-CUSTOMERS-SCALE.html, κατεύθυνση Α.
 */

import { api, esc, fetch, H, toast, viewEl } from '@energy-crm/util';
import { fmtDate, initials, tint } from '@energy-crm/format';
import { openCustomerCard, openEdit } from '@energy-crm/navigate';

var customersState = { q: '', scope: 'own', tab: 'all', city: '', category: '', page: 1, sort: 'recent', filter: 'all' };
var SIG_PER = 50;
export function loadCustomers() {
	var view = viewEl('customers');
	if (customersState.tab !== 'all') { loadCrossSell(view); return; }
	if (isSignature()) { loadSigCustomers(view); return; }
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
			var empty = '<div class="ecrm-empty">' + (customersState.q
					? 'Δεν βρέθηκαν πελάτες για «' + esc(customersState.q) + '».'
					: 'Δεν έχεις κανέναν πελάτη ακόμα. Ο πρώτος πελάτης προστίθεται αυτόματα με τη «Νέα αίτηση».') + '</div>';
			if (isSignature()) {
				view.innerHTML = sigCustomersHTML(d, empty);
				wireCustomers(view);
				return;
			}
			var table = (d.rows && d.rows.length)
				? '<div class="ecrm-tablewrap"><table class="ecrm-table"><thead><tr><th>Πελάτης</th><th class="ecrm-col-sec">ΑΦΜ</th><th>Τηλέφωνο</th><th class="ecrm-col-sec">Email</th><th>Συμβάσεις</th><th class="ecrm-col-sec">Τελευταία</th></tr></thead><tbody>' + rows + '</tbody></table></div>'
				: empty;
			view.innerHTML =
				'<header class="ecrm-head ecrm-head--row"><div class="ecrm-titlewrap"><span class="ecrm-pageicon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8" cy="11" r="2"/><path d="M5 16.5a3.5 3.5 0 0 1 6 0"/><path d="M14 9h4"/><path d="M14 13h4"/></svg></span><div><h2 class="ecrm-title">Πελάτες</h2><p class="ecrm-sub">' + (d.count || 0) + ' πελάτες · μοναδικοί ανά ΑΦΜ</p></div></div>' +
				'<div class="ecrm-scope"><button type="button" class="ecrm-scope__b' + (customersState.scope==="own"?" is-on":"") + '" data-cuscope="own">Δικά μου</button><button type="button" class="ecrm-scope__b' + (customersState.scope==="team"?" is-on":"") + '" data-cuscope="team">Ομάδας</button></div></header>' +
				tabsMarkup(null) +
				'<div class="ecrm-card"><div class="ecrm-search-row"><div class="ecrm-search"><input type="search" class="ecrm-input" placeholder="Αναζήτηση ονόματος, ΑΦΜ, τηλεφώνου…" value="' + esc(customersState.q) + '" data-cusearch></div></div>' + table + '</div>';
			wireCustomers(view);
		})
		.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα φόρτωσης.</div></div>'; });
}

function wireCustomers(view) {
	var se = view.querySelector('[data-cusearch]');
	if (se) {
		var t;
		se.addEventListener('input', function () {
			clearTimeout(t);
			var v = this.value;
			t = setTimeout(function () {
				customersState.q = v;
				customersState.page = 1;
				loadCustomers();
			}, 350);
		});
		// (353i) Η λίστα ξαναζωγραφίζεται σε κάθε αναζήτηση: ο κέρσορας μένει στο πεδίο.
		if (customersState.q) { se.focus(); se.setSelectionRange(se.value.length, se.value.length); }
	}
	view.querySelectorAll('[data-cuscope]').forEach(function (b) { b.addEventListener('click', function () { customersState.scope = this.getAttribute('data-cuscope'); customersState.page = 1; loadCustomers(); }); });
	wireTabs(view);
	wireSigList(view);
	view.querySelectorAll('[data-open-customer]').forEach(function (row) {
		row.addEventListener('click', function (e) {
			// Το «Κλήση» μέσα στην κάρτα καλεί, δεν ανοίγει τον πελάτη.
			if (e.target.closest && e.target.closest('a[href]')) { return; }
			openCustomerCard(this.getAttribute('data-open-customer'));
		});
		row.addEventListener('keydown', function (e) {
			if ((e.key === 'Enter' || e.key === ' ') && e.target === this) { e.preventDefault(); this.click(); }
		});
	});
}

// ---------------------------------------------------------------------
// (353i) «Signature»: οι πελάτες ως κάρτες
// ---------------------------------------------------------------------

function isSignature() {
	var app = document.getElementById('ecrm-app');
	return !!app && app.getAttribute('data-ui') === 'signature';
}

function telHref(phone) {
	return 'tel:' + String(phone || '').replace(/[^\d+]/g, '');
}

/* Ένα πλέγμα από κάρτες αντί για πίνακα έξι στηλών: όνομα και ΑΦΜ, πόσες
 * αιτήσεις και πότε η τελευταία, τηλέφωνο με κουμπί κλήσης. Η σειρά μένει
 * του server (πιο πρόσφατη κίνηση πρώτα). Πάτημα → καρτέλα πελάτη. */
function sigCustomersHTML(d, empty) {
	var rows = d.rows || [];
	var cards = rows.map(function (c) {
		var n = Number(c.contracts) || 0;
		return '<div class="sig-cucard" data-open-customer="' + esc(String(c.id)) + '" role="button" tabindex="0">' +
			'<div class="sig-cucard__top">' +
				'<span class="sig-cucard__av" style="--h:' + tint(c.name) + '">' + esc(initials(c.name)) + '</span>' +
				'<span class="sig-cucard__who"><b>' + esc(c.name) + '</b><small>' + (c.afm ? 'ΑΦΜ ' + esc(c.afm) : 'χωρίς ΑΦΜ') + '</small></span>' +
				(c.phone ? '<a class="sig-cucard__call" href="' + esc(telHref(c.phone)) + '" aria-label="Κλήση ' + esc(c.name) + '" title="Κλήση"><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 005 5L15 13l5 2v4a2 2 0 01-2 2A16 16 0 013 6a2 2 0 012-2"/></svg></a>' : '') +
			'</div>' +
			'<div class="sig-cucard__facts">' +
				'<span><small>Αιτήσεις</small><b>' + esc(String(n)) + '</b></span>' +
				'<span><small>Τελευταία κίνηση</small><b>' + (c.last_at ? esc(fmtDate(c.last_at)) : '—') + '</b></span>' +
			'</div>' +
			'<div class="sig-cucard__contact">' + esc([c.phone, c.email].filter(Boolean).join(' · ') || 'Χωρίς στοιχεία επικοινωνίας') + '</div>' +
		'</div>';
	}).join('');

	return sigHeadHTML((d.count || 0) + (d.count === 1 ? ' πελάτης' : ' πελάτες') + ', ένας ανά ΑΦΜ') +
		'<div class="sig-cubar">' + tabsMarkup(null) +
		'<label class="sig-cusearch"><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>' +
		'<input type="search" placeholder="Όνομα, ΑΦΜ ή τηλέφωνο" value="' + esc(customersState.q) + '" data-cusearch aria-label="Αναζήτηση πελάτη"></label></div>' +
		(cards ? '<div class="sig-cugrid">' + cards + '</div>' : '<div class="ecrm-card">' + empty + '</div>');
}

/* Μεγάλος τίτλος και «Δικά μου / Ομάδας» -- κοινός για «Όλοι» και τις καρτέλες cross-sell. */
function sigHeadHTML(sub) {
	return '<header class="sig-cuhead"><div><h2>Πελάτες</h2><p>' + esc(sub) + '</p></div>' +
		'<div class="ecrm-scope"><button type="button" class="ecrm-scope__b' + (customersState.scope === 'own' ? ' is-on' : '') + '" data-cuscope="own">Δικά μου</button><button type="button" class="ecrm-scope__b' + (customersState.scope === 'team' ? ' is-on' : '') + '" data-cuscope="team">Ομάδας</button></div></header>';
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
						(c.twin ? '<button type="button" class="ecrm-btn ecrm-btn--sm ecrm-btn--companion" data-xs-add="' + c.contract_id + '">' + plus + '</button>' : '') +
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
			var xsub = ((d.counts && d.counts[want]) || 0) + ' πελάτες για ' + (want === 'gas' ? 'αέριο' : 'ρεύμα');
			view.innerHTML = (isSignature() ? sigHeadHTML(xsub) + sigChipsHTML(null, d.counts) : headMarkup(xsub) + tabsMarkup(d.counts)) +
				'<div class="ecrm-card"><div class="ecrm-xsell__flt"><select class="ecrm-input" data-xs-city>' + cityOpts + '</select><select class="ecrm-input" data-xs-cat>' + catOpts + '</select></div>' + rows + '</div>';

			wireTabs(view);
			wireSigList(view);
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

// ---------------------------------------------------------------------
// (377) «Signature»: πελάτες σε πυκνό πίνακα με σελίδες
// ---------------------------------------------------------------------

var SUPPLY = { power: 'Ρεύμα', gas: 'Αέριο' };

function loadSigCustomers(view) {
	var st = customersState;
	var qs = '?scope=' + encodeURIComponent(st.scope) + '&q=' + encodeURIComponent(st.q) +
		'&page=' + encodeURIComponent(st.page) + '&per=' + SIG_PER +
		'&sort=' + encodeURIComponent(st.sort) + '&filter=' + encodeURIComponent(st.filter);
	fetch(api('/customers') + qs, { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (!d || !d.ok) { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα.</div></div>'; return; }
			// Σελίδα πέρα από το τέλος (π.χ. μετά από αναζήτηση): πίσω στην τελευταία.
			var last = Math.max(1, Math.ceil((d.total || 0) / SIG_PER));
			if (st.page > last) { st.page = last; loadSigCustomers(view); return; }
			view.innerHTML = sigListHTML(d);
			wireCustomers(view);
		})
		.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα φόρτωσης.</div></div>'; });
}

function num(n) {
	return Number(n || 0).toLocaleString('el-GR');
}

/* Φίλτρα σε μία σειρά. «Χωρίς αέριο / ρεύμα» είναι οι καρτέλες cross-sell (333). */
function sigChipsHTML(counts, xsell) {
	var c = counts || {};
	var x = xsell || {};
	var st = customersState;
	var chip = function (attr, key, label, n, on) {
		return '<button type="button" class="sig-cuchip' + (on ? ' is-on' : '') + '" ' + attr + '="' + key + '">' + esc(label) +
			(n != null ? '<small>' + esc(num(n)) + '</small>' : '') + '</button>';
	};
	var inAll = st.tab === 'all';
	return '<div class="sig-cuchips">' +
		chip('data-cufilter', 'all', 'Όλοι', c.all, inAll && st.filter === 'all') +
		chip('data-cufilter', 'home', 'Οικιακοί', c.home, inAll && st.filter === 'home') +
		chip('data-cufilter', 'business', 'Επαγγελματικοί', c.business, inAll && st.filter === 'business') +
		chip('data-cutab', 'gas', 'Χωρίς αέριο', x.gas, st.tab === 'gas') +
		chip('data-cutab', 'power', 'Χωρίς ρεύμα', x.power, st.tab === 'power') +
		chip('data-cufilter', 'nophone', 'Χωρίς τηλέφωνο', c.nophone, inAll && st.filter === 'nophone') +
	'</div>';
}

function sigListHTML(d) {
	var st = customersState;
	var rows = d.rows || [];
	var total = d.total || 0;
	var all = (d.counts && d.counts.all) || 0;
	var sub = num(all) + (all === 1 ? ' πελάτης' : ' πελάτες') + ', ένας ανά ΑΦΜ';

	var sortOpt = function (key, label) {
		return '<option value="' + key + '"' + (st.sort === key ? ' selected' : '') + '>' + label + '</option>';
	};
	var bar = '<div class="sig-cubar">' +
		'<label class="sig-cusearch"><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>' +
		'<input type="search" placeholder="Όνομα, ΑΦΜ, τηλέφωνο ή email" value="' + esc(st.q) + '" data-cusearch aria-label="Αναζήτηση πελάτη"></label>' +
		'<label class="sig-cusort"><span>Ταξινόμηση</span><select data-cusort aria-label="Ταξινόμηση">' +
			sortOpt('recent', 'Τελευταία κίνηση') + sortOpt('name', 'Όνομα Α–Ω') + sortOpt('contracts', 'Περισσότερες αιτήσεις') +
		'</select></label></div>';

	var head = function (key, label, cls) {
		var on = st.sort === key;
		return '<button type="button" class="sig-cut__h' + (cls ? ' ' + cls : '') + (on ? ' is-on' : '') + '" data-cusorthead="' + key + '">' +
			esc(label) + (on ? ' ▾' : '') + '</button>';
	};

	var body = rows.map(function (c) {
		var sup = (c.supplies || []).map(function (k) {
			return '<span class="sig-cut__sp is-' + esc(k) + '">' + esc(SUPPLY[k] || k) + '</span>';
		}).join('');
		var call = c.phone
			? '<a class="sig-cut__act" href="' + esc(telHref(c.phone)) + '" aria-label="Κλήση ' + esc(c.name) + '" title="Κλήση"><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 005 5L15 13l5 2v4a2 2 0 01-2 2A16 16 0 013 6a2 2 0 012-2"/></svg></a>'
			: '';
		return '<div class="sig-cut__r" data-open-customer="' + esc(String(c.id)) + '" role="button" tabindex="0">' +
			'<span class="sig-cut__av" style="--h:' + tint(c.name) + '">' + esc(initials(c.name)) + '</span>' +
			'<span class="sig-cut__nm"><b>' + esc(c.name) + '</b><small>' + (c.afm ? 'ΑΦΜ ' + esc(c.afm) : 'χωρίς ΑΦΜ') +
				' · ' + (c.business ? 'Επαγγελματικός' : 'Οικιακός') + '</small></span>' +
			'<span class="sig-cut__tel">' + (c.phone ? esc(c.phone) : '<em>χωρίς τηλέφωνο</em>') + '</span>' +
			'<span class="sig-cut__sup">' + sup + '</span>' +
			'<span class="sig-cut__n">' + esc(String(Number(c.contracts) || 0)) + '</span>' +
			'<span class="sig-cut__dt">' + (c.last_at ? esc(fmtDate(c.last_at)) : '—') + '</span>' +
			'<span class="sig-cut__acts">' + call + '<span class="sig-cut__act" aria-hidden="true">›</span></span>' +
		'</div>';
	}).join('');

	var empty = '<div class="ecrm-empty">' + (st.q
		? 'Δεν βρέθηκαν πελάτες για «' + esc(st.q) + '».'
		: (st.filter !== 'all' ? 'Κανένας πελάτης σε αυτό το φίλτρο.' : 'Δεν έχεις κανέναν πελάτη ακόμα. Ο πρώτος πελάτης προστίθεται αυτόματα με τη «Νέα αίτηση».')) + '</div>';

	var table = body
		? '<div class="sig-cut">' +
			// Κεφαλαία γραμμένα ρητά, χωρίς τόνους (κανόνας ιδιοκτήτη), όχι με text-transform.
			'<div class="sig-cut__head"><span></span>' + head('name', 'ΠΕΛΑΤΗΣ') + '<span>ΤΗΛΕΦΩΝΟ</span><span>ΠΑΡΟΧΕΣ</span>' +
				head('contracts', 'ΑΙΤΗΣΕΙΣ', 'is-c') + head('recent', 'ΚΙΝΗΣΗ') + '<span></span></div>' +
			body + sigPagerHTML(total) + '</div>'
		: '<div class="ecrm-card">' + empty + '</div>';

	return sigHeadHTML(sub) + bar + sigChipsHTML(d.counts, null) + table;
}

/* «1–50 από 1.284», σελίδες 1 2 3 … τελευταία, Προηγούμενη / Επόμενη. */
function sigPagerHTML(total) {
	var st = customersState;
	var pages = Math.max(1, Math.ceil(total / SIG_PER));
	var from = total ? (st.page - 1) * SIG_PER + 1 : 0;
	var to = Math.min(total, st.page * SIG_PER);
	if (pages === 1) {
		return '<div class="sig-cut__pg"><span>' + esc(num(total)) + (total === 1 ? ' πελάτης' : ' πελάτες') + '</span></div>';
	}
	var want = [1, st.page - 1, st.page, st.page + 1, pages].filter(function (p, i, a) { return p >= 1 && p <= pages && a.indexOf(p) === i; }).sort(function (a, b) { return a - b; });
	var nums = '';
	want.forEach(function (p, i) {
		if (i > 0 && p - want[i - 1] > 1) { nums += '<span class="sig-cut__gap">…</span>'; }
		nums += '<button type="button" class="sig-cut__p' + (p === st.page ? ' is-on' : '') + '" data-cupage="' + p + '"' + (p === st.page ? ' aria-current="page"' : '') + '>' + p + '</button>';
	});
	return '<div class="sig-cut__pg"><span>' + esc(num(from)) + '–' + esc(num(to)) + ' από ' + esc(num(total)) + '</span>' +
		'<div class="sig-cut__pages">' + nums + '</div>' +
		'<div class="sig-cut__nav">' +
			'<button type="button" class="ecrm-btn ecrm-btn--ghost" data-cupage="' + (st.page - 1) + '"' + (st.page <= 1 ? ' disabled' : '') + '>‹ Προηγούμενη</button>' +
			'<button type="button" class="ecrm-btn ecrm-btn--ghost" data-cupage="' + (st.page + 1) + '"' + (st.page >= pages ? ' disabled' : '') + '>Επόμενη ›</button>' +
		'</div></div>';
}

function wireSigList(view) {
	var st = customersState;
	view.querySelectorAll('[data-cufilter]').forEach(function (b) {
		b.addEventListener('click', function () { st.tab = 'all'; st.filter = b.getAttribute('data-cufilter'); st.page = 1; loadCustomers(); });
	});
	var sel = view.querySelector('[data-cusort]');
	if (sel) { sel.addEventListener('change', function () { st.sort = sel.value; st.page = 1; loadCustomers(); }); }
	view.querySelectorAll('[data-cusorthead]').forEach(function (b) {
		b.addEventListener('click', function () { st.sort = b.getAttribute('data-cusorthead'); st.page = 1; loadCustomers(); });
	});
	view.querySelectorAll('[data-cupage]').forEach(function (b) {
		b.addEventListener('click', function () {
			var p = +b.getAttribute('data-cupage');
			if (!(p >= 1) || p === st.page) { return; }
			st.page = p;
			loadCustomers();
			var top = view.getBoundingClientRect().top + window.scrollY - 90;
			window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
		});
	});
}
