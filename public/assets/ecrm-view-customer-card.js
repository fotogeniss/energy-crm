/* Energy CRM — καρτέλα πελάτη: όλα τα στοιχεία ενός πελάτη σε μία οθόνη.
 *
 * Build queue 09, 05/09 (247). Στάδιο 1 από τρία (docs/UI-CUSTOMER-CARD.html,
 * εγκρίθηκε 05/09): μόνο ανάγνωση. Πριν από αυτή την οθόνη, «άνοιξε τον
 * πελάτη» σήμαινε openCustomerContracts() -- τη λίστα «Συμβάσεις» φιλτραρισμένη
 * στο ΑΦΜ του (βλ. σχόλιο εκεί, build queue 08). Αυτό παραμένει, αλλά δεν
 * έλυνε ποτέ το ίδιο πρόβλημα: εδώ ο συνεργάτης βλέπει τον πελάτη, όχι μια
 * λίστα σχεδόν τυχαία φιλτραρισμένη γύρω του.
 *
 * Ενα μόνο fetch (CustomersController::card()) φέρνει πελάτη + συμβάσεις +
 * έγγραφα + τα τρία KPI μαζί -- τα KPI υπολογισμένα ΕΚΕΙ, όχι εδώ, ώστε το
 * "τι είναι ενεργό" να περνά πάντα από το ίδιο ContractStatus::isTerminal()
 * που ξέρει ήδη ο υπόλοιπος κώδικας. Ιδια στήλη-κατάστασης (ecrm-badge--*) με
 * τη λίστα «Συμβάσεις» -- ο συνεργάτης δεν μαθαίνει δεύτερη γλώσσα χρωμάτων.
 */

import { api, esc, fetch, H, viewEl } from '@energy-crm/util';
import { fmtDate, initials, tint } from '@energy-crm/format';
import { go, openDetail } from '@energy-crm/navigate';

var TYPE_LABEL = { individual: 'ΙΔΙΩΤΗΣ', company: 'ΕΤΑΙΡΕΙΑ', sole_prop: 'ΑΤΟΜΙΚΗ' };

export function openCustomerCard(id) {
	go('customer-detail');
	var view = viewEl('customer-detail');
	if (!view) { return; }
	view.innerHTML = '<div class="ecrm-loading">Φόρτωση…</div>';
	fetch(api('/customers/' + id + '/card'), { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (!d || !d.ok) {
				view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">' +
					esc((d && d.error) || 'Δεν βρέθηκε.') + '</div></div>';
				return;
			}
			renderCard(view, id, d);
		})
		.catch(function () {
			view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα φόρτωσης.</div></div>';
		});
}

function customerName(c) {
	var name = c.company_name || ((c.first_name || '') + ' ' + (c.last_name || '')).trim();
	return name || '—';
}

function kv(label, value) {
	return '<div class="ecrm-kv"><b>' + (value ? esc(value) : '<span class="ecrm-muted">—</span>') + '</b><span>' + esc(label) + '</span></div>';
}

function contractsTable(contracts, statuses) {
	if (!contracts.length) {
		return '<div class="ecrm-empty">Καμία σύμβαση ακόμη.</div>';
	}
	var rows = contracts.map(function (c) {
		return '<tr class="ecrm-rowlink" data-open="' + c.id + '">' +
			'<td><strong>' + esc(c.code || '—') + '</strong></td>' +
			'<td class="ecrm-muted ecrm-col-sec">' + esc(c.provider_name || '—') + '</td>' +
			'<td class="ecrm-muted ecrm-col-sec">' + esc(c.program_name || '—') + '</td>' +
			'<td><span class="ecrm-badge ecrm-badge--' + esc(c.status) + '">' + esc(statuses[c.status] || c.status) + '</span></td>' +
			'<td class="ecrm-muted ecrm-col-sec">' + (c.end_date ? fmtDate(c.end_date) : '—') + '</td>' +
			'</tr>';
	}).join('');
	return '<div class="ecrm-tablewrap"><table class="ecrm-table"><thead><tr>' +
		'<th>Κωδικός</th><th class="ecrm-col-sec">Πάροχος</th><th class="ecrm-col-sec">Πρόγραμμα</th><th>Κατάσταση</th><th class="ecrm-col-sec">Λήξη</th>' +
		'</tr></thead><tbody>' + rows + '</tbody></table></div>';
}

function expiringRows(contracts) {
	// Ιδιο κριτήριο με CustomersController::card()/ContractQueries::expiring():
	// days_left έρχεται έτοιμο από το backend, δεν ξαναϋπολογίζεται εδώ.
	var upcoming = contracts.filter(function (c) {
		return c.end_date && c.days_left != null && c.status !== 'draft'
			&& c.status !== 'cancelled' && c.status !== 'terminated' && c.status !== 'rejected';
	}).sort(function (a, b) { return a.days_left - b.days_left; });

	if (!upcoming.length) {
		return '<div class="ecrm-empty">Καμία επερχόμενη λήξη.</div>';
	}

	var rows = upcoming.map(function (c) {
		var left = +c.days_left;
		var cls = left <= 30 ? 'is-down' : '';
		return '<tr><td><strong>' + esc(c.code || '—') + '</strong>' +
			(c.program_name ? '<div class="ecrm-muted ecrm-tlrole">' + esc(c.program_name) + '</div>' : '') + '</td>' +
			'<td class="ecrm-muted ecrm-col-sec">' + fmtDate(c.end_date) + '</td>' +
			'<td><span class="ecrm-delta ' + cls + '">' + (left < 0 ? 'έληξε' : 'σε ' + left + (left === 1 ? ' ημέρα' : ' ημέρες')) + '</span></td>' +
			'</tr>';
	}).join('');
	return '<div class="ecrm-tablewrap"><table class="ecrm-table"><thead><tr>' +
		'<th>Σύμβαση</th><th class="ecrm-col-sec">Λήγει</th><th>Σε</th>' +
		'</tr></thead><tbody>' + rows + '</tbody></table></div>';
}

function documentsList(documents, docKinds, contracts) {
	if (!documents.length) {
		return '<div class="ecrm-empty">Κανένα έγγραφο ακόμη.</div>';
	}
	var codeById = {};
	contracts.forEach(function (c) { codeById[c.id] = c.code; });

	// Ιδιες κλάσεις (.ecrm-files/.ecrm-file) με τη «Συμβάσεις → Εγγραφα»,
	// έτσι ένα έγγραφο δείχνει ίδιο σε δύο σημεία -- μόνη προσθήκη εδώ είναι
	// ποια σύμβαση το έφερε, που δεν έχει νόημα στην κάρτα μιας σύμβασης.
	return '<div class="ecrm-files">' + documents.map(function (doc) {
		var label = (docKinds && docKinds[doc.doc_kind]) || doc.doc_kind || 'Έγγραφο';
		var from = codeById[doc.contract_id] || '';
		var thumb = doc.is_image && doc.url ? '<img src="' + esc(doc.url) + '" alt="">' : '<span class="ecrm-file__ext">' + (doc.mime === 'application/pdf' ? 'PDF' : 'DOC') + '</span>';
		return '<a class="ecrm-file" href="' + esc(doc.url || '#') + '" target="_blank" rel="noopener">' +
			'<span class="ecrm-file__thumb">' + thumb + '</span>' +
			'<span class="ecrm-file__meta"><span class="ecrm-file__name">' + esc(doc.filename || 'έγγραφο') + '</span>' +
			'<span class="ecrm-file__kind">' + esc(label) + (from ? ' · ' + esc(from) : '') + '</span></span>' +
			'</a>';
	}).join('') + '</div>';
}

function kpiBlock(kpi) {
	var next = kpi.next_expiry;
	var nextLabel = '—';
	var nextFoot = 'καμία επερχόμενη';
	if (next && next.days_left != null) {
		var left = +next.days_left;
		nextLabel = left < 0 ? 'έληξε' : 'σε ' + left + (left === 1 ? ' ημέρα' : ' ημέρες');
		nextFoot = next.code || '';
	}
	return '<div class="ecrm-kpis ecrm-kpis--4">' +
		'<div class="ecrm-kpi"><div class="ecrm-kpi__k">Συμβάσεις</div><div class="ecrm-kpi__v">' + esc(kpi.contracts_count) + '</div><div class="ecrm-kpi__f"></div></div>' +
		'<div class="ecrm-kpi"><div class="ecrm-kpi__k">Ενεργές</div><div class="ecrm-kpi__v">' + esc(kpi.active_count) + '</div><div class="ecrm-kpi__f"></div></div>' +
		'<div class="ecrm-kpi"><div class="ecrm-kpi__k">Επόμενη λήξη</div><div class="ecrm-kpi__v">' + esc(nextLabel) + '</div><div class="ecrm-kpi__f">' + esc(nextFoot) + '</div></div>' +
		'<div class="ecrm-kpi"><div class="ecrm-kpi__k">Τελευταία κίνηση</div><div class="ecrm-kpi__v">' + (kpi.last_active ? fmtDate(kpi.last_active) : '—') + '</div><div class="ecrm-kpi__f"></div></div>' +
		'</div>';
}

function renderCard(view, id, d) {
	var c = d.customer || {};
	var contracts = d.contracts || [];
	var documents = d.documents || [];
	var statuses = d.statuses || {};
	var name = customerName(c);

	view.innerHTML =
		'<div style="margin-bottom:10px">' +
		'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-back>&larr; Πελάτες</button>' +
		'</div>' +
		'<header class="ecrm-head ecrm-head--row">' +
		'<div>' +
		'<h2 class="ecrm-title">' +
		'<span class="ecrm-cell-mark ecrm-cell-mark--cust" style="--h:' + tint(name) + '">' + esc(initials(name)) + '</span> ' +
		esc(name) + '</h2>' +
		'<p class="ecrm-sub">' + esc(TYPE_LABEL[c.customer_type] || '') +
		(c.afm ? ' · ΑΦΜ ' + esc(c.afm) : '') +
		(c.created_at ? ' · πελάτης από ' + esc(fmtDate(c.created_at)) : '') + '</p>' +
		'</div>' +
		'</header>' +

		kpiBlock(d.kpi || {}) +

		'<div class="ecrm-pgrid">' +
		'<div class="ecrm-pgrid__side">' +
		'<div class="ecrm-card"><div class="ecrm-step">Ταυτότητα</div>' +
		kv('Όνομα', c.first_name) + kv('Επώνυμο', c.last_name) + kv('Πατρώνυμο', c.father_name) +
		kv('Α.Φ.Μ.', c.afm) + kv('Δ.Ο.Υ.', c.doy) + kv('Α.Δ.Τ.', c.adt) + kv('Ημ. Γέννησης', c.birth_date ? fmtDate(c.birth_date) : '') +
		'</div>' +
		'<div class="ecrm-card"><div class="ecrm-step">Διεύθυνση</div>' +
		kv('Οδός', [c.street, c.street_no].filter(Boolean).join(' ')) + kv('Πόλη', c.city) + kv('Τ.Κ.', c.postal_code) + kv('Περιοχή', c.region) +
		'</div>' +
		'<div class="ecrm-card"><div class="ecrm-step">Επικοινωνία</div>' +
		kv('Τηλέφωνο', c.phone) + kv('Κινητό', c.mobile) + kv('Email', c.email) +
		'</div>' +
		'</div>' +

		'<div class="ecrm-pgrid__main">' +
		'<div class="ecrm-card"><div class="ecrm-step">Συμβάσεις</div>' + contractsTable(contracts, statuses) + '</div>' +
		'<div class="ecrm-card"><div class="ecrm-step">Λήξεις &amp; ανανεώσεις</div>' + expiringRows(contracts) + '</div>' +
		'<div class="ecrm-card"><div class="ecrm-step">Έγγραφα όλων των συμβάσεων</div>' + documentsList(documents, d.doc_kinds, contracts) + '</div>' +
		'</div>' +
		'</div>';

	var back = view.querySelector('[data-back]');
	if (back) { back.addEventListener('click', function () { go('customers'); }); }

	view.querySelectorAll('[data-open]').forEach(function (row) {
		row.addEventListener('click', function () { openDetail(this.getAttribute('data-open')); });
	});
}
