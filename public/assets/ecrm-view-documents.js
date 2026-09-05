/* Energy CRM — Έγγραφα (243): απογραφή, όχι μόνο λίστα προβλημάτων.
 *
 * Τι έχει ανέβει σε κάθε αίτηση, τι είδος αναγνώρισε η AI, τι λείπει --
 * μαζεμένα σε μία οθόνη. Ίδιο σχήμα scope με τις Εκκρεμότητες
 * (@energy-crm/scope). Τα φίλτρα-chips φιλτράρουν ΤΟΠΙΚΑ πάνω στη λίστα που
 * ήδη ήρθε -- η λίστα είναι ήδη μικρή (≤200 γραμμές, ίδιο ταβάνι με τις
 * υπόλοιπες οθόνες), δεν αξίζει δεύτερο request ανά φίλτρο. */

import { api, esc, fetch, H, viewEl } from '@energy-crm/util';
import { initials, tint } from '@energy-crm/format';
import { openDetail } from '@energy-crm/navigate';
import { scope, setScope } from '@energy-crm/scope';

var currentFilter = 'all';
var lastData = null;

export function loadDocuments() {
	var view = viewEl('documents');
	fetch(api('/documents-overview') + '?scope=' + scope(), { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (!d || !d.ok) { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα.</div></div>'; return; }
			lastData = d;
			renderDocuments(view, d);
		})
		.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα φόρτωσης.</div></div>'; });
}

function filteredRows(d) {
	var rows = d.rows || [];
	if (currentFilter === 'missing') return rows.filter(function (r) { return !r.complete; });
	if (currentFilter === 'pending') return rows.filter(function (r) { return r.pending; });
	return rows;
}

function docChip(doc) {
	var cls = 'ecrm-dchip' + (doc.pending ? ' is-wait' : (doc.source === 'ai' || doc.source === 'ai_ok' ? ' is-ai' : ''));
	var mark = doc.pending ? '<span class="ecrm-spin"></span>' : '<span class="ecrm-dchip__dot"></span>';
	return '<span class="' + cls + '">' + mark + esc(doc.label) + '</span>';
}

function renderDocuments(view, d) {
	var rows = filteredRows(d);
	var statuses = (window.ECRM && ECRM.statuses) || {};

	var body = rows.map(function (r) {
		var docs = (r.docs || []).map(docChip).join('');
		var req = r.complete
			? '<span class="ecrm-reqcell is-ok">Ολα παρόντα</span>'
			: '<span class="ecrm-reqcell is-missing">Λείπει: ' + esc((r.missing || []).join(', ')) + '</span>';
		return '<tr>' +
			'<td><span class="ecrm-cell-cust"><span class="ecrm-cell-mark ecrm-cell-mark--cust" style="--h:' + tint(r.customer) + '">' + esc(initials(r.customer)) + '</span><span>' + esc(r.customer) + '</span></span></td>' +
			'<td class="ecrm-col-sec"><span class="ecrm-code">' + esc(r.code || '') + '</span></td>' +
			'<td><span class="ecrm-badge ecrm-badge--' + esc(r.status) + '">' + esc(r.status_label || statuses[r.status] || r.status) + '</span></td>' +
			'<td><div class="ecrm-docchips">' + (docs || '—') + '</div></td>' +
			'<td>' + req + '</td>' +
			'<td><button type="button" class="ecrm-rowbtn" data-id="' + r.id + '">Ανοιγμα</button></td>' +
			'</tr>';
	}).join('');

	var table = rows.length
		? '<div class="ecrm-tablewrap"><table class="ecrm-table"><thead><tr><th>Πελάτης</th><th class="ecrm-col-sec">Κωδικός</th><th>Κατάσταση</th><th>Έγγραφα</th><th>Δικαιολογητικά</th><th></th></tr></thead><tbody>' + body + '</tbody></table></div>'
		: '<div class="ecrm-emptybox ecrm-emptybox--big"><span class="ecrm-emptybox__ico">✓</span><div class="ecrm-emptybox__txt">Καμία αίτηση σε αυτό το φίλτρο.</div></div>';

	var missingCt = d.missing || 0;
	var pendingCt = d.pending || 0;

	function chip(key, label, count) {
		return '<button type="button" class="ecrm-filterchip' + (currentFilter === key ? ' is-on' : '') + '" data-doc-filter="' + key + '">' + label + (count !== null ? ' <span class="ecrm-filterchip__ct">(' + count + ')</span>' : '') + '</button>';
	}

	view.innerHTML =
		'<header class="ecrm-head ecrm-head--row"><div class="ecrm-titlewrap"><span class="ecrm-pageicon">' +
		'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h6"/></svg></span>' +
		'<div><h2 class="ecrm-title">Έγγραφα</h2><p class="ecrm-sub">' + (d.count || 0) + ' αιτήσεις με έγγραφα · ' + missingCt + ' με ελλιπή δικαιολογητικά</p></div></div>' +
		'<div class="ecrm-scope"><button type="button" class="ecrm-scope__b' + (scope()==="own"?" is-on":"") + '" data-dscope="own">Δικά μου</button><button type="button" class="ecrm-scope__b' + (scope()==="team"?" is-on":"") + '" data-dscope="team">Ομάδας</button></div></header>' +
		'<div class="ecrm-toolbar">' +
		'<div class="ecrm-filters">' + chip('all', 'Ολες', null) + chip('missing', 'Μόνο ελλιπή', missingCt) + chip('pending', 'Περιμένουν AI', pendingCt) + '</div>' +
		'<button type="button" class="ecrm-btn ecrm-btn--go" data-doc-reviewall">' +
		'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 3v6h-6"/></svg>' +
		'Ελεγξε τώρα ό,τι εκκρεμεί</button>' +
		'</div>' +
		'<div class="ecrm-card">' + table + '</div>';

	view.querySelectorAll('[data-id]').forEach(function (b) { b.addEventListener('click', function () { openDetail(this.getAttribute('data-id')); }); });
	view.querySelectorAll('[data-dscope]').forEach(function (b) { b.addEventListener('click', function () { setScope(this.getAttribute('data-dscope')); loadDocuments(); }); });
	view.querySelectorAll('[data-doc-filter]').forEach(function (b) {
		b.addEventListener('click', function () { currentFilter = this.getAttribute('data-doc-filter'); if (lastData) renderDocuments(view, lastData); });
	});

	var goBtn = view.querySelector('[data-doc-reviewall]');
	if (goBtn) goBtn.addEventListener('click', function () {
		goBtn.disabled = true;
		var original = goBtn.innerHTML;
		goBtn.innerHTML = '<span class="ecrm-spin"></span> Ελέγχω…';
		fetch(api('/documents-overview/review') + '?scope=' + scope(), { method: 'POST', headers: H() })
			.then(function (r) { return r.json(); })
			.then(function () { loadDocuments(); })
			.catch(function () { goBtn.disabled = false; goBtn.innerHTML = original; });
	});
}
