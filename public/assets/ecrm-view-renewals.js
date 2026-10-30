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
/**
 * Ο πελάτης της γραμμής, από τις στήλες που ήδη έρχονται.
 *
 * Το /renewals επιστρέφει τις ωμές γραμμές της ContractQueries::expiring(), που
 * κάνει LEFT JOIN στον πελάτη και δίνει first_name, last_name και
 * company_name — ποτέ έτοιμο «customer». Μέχρι τις 2026-08-14 η οθόνη διάβαζε
 * r.customer, οπότε η στήλη Πελάτης ήταν ΠΑΝΤΑ κενή — χωρίς εξαίρεση,
 * γιατί esc(undefined) είναι κενό string.
 *
 * Ο ίδιος κανόνας με τη λίστα συμβάσεων και με το taskCustomer() των
 * Εργασιών: εταιρεία αν υπάρχει, αλλιώς ονοματεπώνυμο.
 *
 * Καμία από τις τρεις στήλες δεν είναι στο CustomerFields::ENCRYPTED.
 */
function renewalCustomer(r) {
	return r.company_name || ((r.first_name || '') + ' ' + (r.last_name || '')).trim();
}

function renderRenewals(view, d) {
	var rows = d.rows || [];
	var soon = 0;
	var body = rows.map(function (r) {
		// Το SQL δίνει days_left (DATEDIFF), όχι έτοιμο 'expired'. Η οθόνη
		// διάβαζε r.expired, που δεν στέλνεται ποτέ: κάθε ληγμένη σύμβαση
		// έγραφε «Λήγει σε -12η» με το χρώμα του «σύντομα».
		var left = +r.days_left;
		var expired = left < 0;
		var customer = renewalCustomer(r);
		var pill, cls;
		if (expired) { pill = 'Έληξε πριν ' + Math.abs(left) + 'η'; cls = 'is-expired'; }
		else if (left <= 30) { pill = 'Λήγει σε ' + left + 'η'; cls = 'is-soon'; }
		else { pill = 'Λήγει σε ' + left + 'η'; cls = ''; }
		if (left <= 30) soon++;
		return '<tr>' +
			'<td><span class="ecrm-cell-cust"><span class="ecrm-cell-mark ecrm-cell-mark--cust" style="--h:' + tint(customer) + '">' + esc(initials(customer)) + '</span><span>' + esc(customer) + '</span></span></td>' +
			'<td class="ecrm-col-sec"><span class="ecrm-code">' + esc(r.code || '') + '</span></td>' +
			'<td class="ecrm-col-sec">' + esc(r.provider_name || '—') + '</td>' +
			'<td class="ecrm-col-sec">' + (r.end_date ? fmtDate(r.end_date) : '—') + '</td>' +
			'<td><span class="ecrm-agepill ' + cls + '">' + pill + '</span></td>' +
			'<td><button type="button" class="ecrm-btn ecrm-btn--primary ecrm-btn--sm" data-renew="' + r.id + '">' + svgIcon('edit') + ' Ανανέωση</button></td>' +
			'</tr>' + offerRow(r);
	}).join('');
	var table = rows.length
		? '<div class="ecrm-tablewrap"><table class="ecrm-table"><thead><tr><th>Πελάτης</th><th class="ecrm-col-sec">Κωδικός</th><th class="ecrm-col-sec">Πάροχος</th><th class="ecrm-col-sec">Λήξη</th><th>Κατάσταση</th><th></th></tr></thead><tbody>' + body + '</tbody></table></div>'
		: '<div class="ecrm-emptybox ecrm-emptybox--big"><span class="ecrm-emptybox__ico">✓</span><div class="ecrm-emptybox__txt">Καμία σύμβαση δεν λήγει σύντομα.</div></div>';
	view.innerHTML =
		'<header class="ecrm-head ecrm-head--row"><div class="ecrm-titlewrap"><span class="ecrm-pageicon">' +
		'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/><path d="M3 21v-5h5"/></svg></span>' +
		'<div><h2 class="ecrm-title">Λήξεις & Ανανεώσεις</h2><p class="ecrm-sub">' + rows.length + ' συμβάσεις λήγουν έως ' + (d.days || 0) + ' ημέρες · ' + soon + ' άμεσα</p></div></div>' +
		'<div class="ecrm-scope"><button type="button" class="ecrm-scope__b' + (renewState.scope==="own"?" is-on":"") + '" data-rscope="own">Δικά μου</button><button type="button" class="ecrm-scope__b' + (renewState.scope==="team"?" is-on":"") + '" data-rscope="team">Ομάδας</button></div></header>' +
		'<div class="ecrm-card">' + table + '</div>';

	view.querySelectorAll('[data-rscope]').forEach(function (b) { b.addEventListener('click', function () { renewState.scope = this.getAttribute('data-rscope'); loadRenewals(); }); });
	view.querySelectorAll('[data-renew]').forEach(function (b) {
		b.addEventListener('click', function () { renew(this, this.getAttribute('data-renew'), 0); });
	});
	view.querySelectorAll('[data-renew-offer]').forEach(function (b) {
		b.addEventListener('click', function () { renew(this, this.getAttribute('data-renew-offer'), +this.getAttribute('data-program')); });
	});
	view.querySelectorAll('[data-kwh-save]').forEach(function (b) {
		b.addEventListener('click', function () {
			var id = this.getAttribute('data-kwh-save');
			var input = view.querySelector('[data-kwh-for="' + id + '"]');
			var kwh = Math.round(+(input && input.value) || 0);
			if (kwh <= 0) { toast('Γράψε την κατανάλωση του χρόνου σε kWh.', false); return; }
			var btn = this; btn.disabled = true;
			fetch(api('/contracts/' + id + '/annual-kwh'), {
				method: 'POST',
				headers: Object.assign({ 'Content-Type': 'application/json' }, H()),
				body: JSON.stringify({ kwh: kwh })
			})
				.then(function (r) { return r.json(); })
				.then(function (d) { if (d && d.ok) { loadRenewals(); } else { btn.disabled = false; toast((d && d.error) || 'Αποτυχία.', false); } })
				.catch(function () { btn.disabled = false; toast('Σφάλμα δικτύου.', false); });
		});
	});
}

// (335) Η πρόταση κάτω από τη σύμβαση. Mockup: docs/UI-RENEWAL-OFFER.html.
function offerRow(r) {
	if (['power', 'gas'].indexOf(r.energy_type) < 0) { return ''; }
	var money = function (v) { return Math.round(v).toLocaleString('el-GR') + ' €'; };
	var inner;
	if (r.annual_kwh == null) {
		inner = '<span class="ecrm-muted">Δεν ξέρουμε την κατανάλωσή του.</span>' +
			'<span class="ecrm-renew-offer__kwh"><input type="number" min="1" class="ecrm-input" placeholder="kWh / έτος" data-kwh-for="' + esc(r.id) + '">' +
			'<button type="button" class="ecrm-btn ecrm-btn--sm" data-kwh-save="' + esc(r.id) + '">Σύγκρινε</button></span>';
	} else if (r.offer && r.offer.state === 'cheaper' && r.offer.best) {
		var o = r.offer;
		inner = 'Φθηνότερο σήμερα για ' + Math.round(r.annual_kwh).toLocaleString('el-GR') + ' kWh: <b>' + esc(o.best.name) + '</b>' +
			(o.saving != null && o.saving > 0
				? ' <span class="ecrm-renew-offer__save">−' + money(o.saving) + '/έτος</span> <span class="ecrm-muted">(' + money(o.best_annual) + ' αντί ' + money(o.current_annual) + ')</span>'
				: ' <span class="ecrm-muted">(' + money(o.best_annual) + '/έτος· η σημερινή τιμή του προγράμματός του δεν είναι γνωστή)</span>') +
			' <button type="button" class="ecrm-btn ecrm-btn--primary ecrm-btn--sm" data-renew-offer="' + esc(r.id) + '" data-program="' + esc(o.best.id) + '">Ανανέωση με αυτό</button>';
	} else if (r.offer && r.offer.state === 'best') {
		inner = '<span class="ecrm-muted">✓ Το πρόγραμμά του είναι ήδη το φθηνότερο για ' + Math.round(r.annual_kwh).toLocaleString('el-GR') + ' kWh.</span>';
	} else {
		inner = '<span class="ecrm-muted">Δεν υπάρχει πρόγραμμα με πρόσφατη τιμή για σύγκριση.</span>';
	}
	return '<tr class="ecrm-renew-offer"><td colspan="6"><div class="ecrm-renew-offer__in">' + inner + '</div></td></tr>';
}

function renew(btn, id, programId) {
	btn.disabled = true;
	fetch(api('/contracts/' + id + '/renew'), {
		method: 'POST',
		headers: Object.assign({ 'Content-Type': 'application/json' }, H(true)),
		body: JSON.stringify(programId ? { program_id: programId } : {})
	})
		.then(function (r) { return r.json(); })
		.then(function (d) {
			btn.disabled = false;
			if (d && d.ok && d.contract_id) {
				toast('Δημιουργήθηκε ανανέωση — άνοιξε για επεξεργασία.');
				// load the new draft into the form
				fetch(api('/contracts/' + d.contract_id), { headers: H() })
					.then(function (r) { return r.json(); })
					.then(function (dd) { var c = dd.contract || dd; openEdit(c); })
					.catch(function () { toast('Η ανανέωση δημιουργήθηκε, αλλά απέτυχε το άνοιγμα για επεξεργασία — βρες τη στη λίστα.', false); });
			} else { toast((d && d.error) || 'Αποτυχία.', false); }
		})
		.catch(function () { btn.disabled = false; toast('Σφάλμα δικτύου.', false); });
}
