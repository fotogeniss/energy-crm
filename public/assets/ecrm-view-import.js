/* Energy CRM — Excel import: read a sheet, map its columns, apply.
 *
 * Everything here is client-side until applyImport(); the file never leaves
 * the browser before the operator has seen the mapping they are about to run. */

import { api, esc, fetch, H, toast, viewEl } from '@energy-crm/util';

var importState = { columns: [], rows: [], total: 0, supplyCol: -1, statusCol: -1, statusMap: {} };
export function initImport() {
	var view = viewEl('import');
	// Only render the dropzone once per visit (reset state each entry).
	importState = { columns: [], rows: [], total: 0, supplyCol: -1, statusCol: -1, statusMap: {} };
	view.innerHTML =
		'<header class="ecrm-head"><h2 class="ecrm-title">Εισαγωγή Excel παρόχου</h2>' +
		'<p class="ecrm-sub">Ανέβασε το αρχείο του παρόχου, αντιστοίχισε στήλες και ενημέρωσε τα statuses βάσει αριθμού παροχής.</p></header>' +
		'<div class="ecrm-card ecrm-card--ai">' +
		'<div class="ecrm-drop" data-idrop tabindex="0" role="button">' +
		'<input type="file" data-ifile accept=".xlsx,.csv" hidden>' +
		'<div class="ecrm-drop__icon"><svg class="ecrm-i ecrm-i--drop" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V7m0 0l-4 4m4-4l4 4M4 21h16"/></svg></div><div class="ecrm-drop__title">Σύρε το Excel/CSV εδώ</div>' +
		'<div class="ecrm-drop__hint">ή <button type="button" class="ecrm-link" data-ipick>πάτα για επιλογή</button> · .xlsx ή .csv</div>' +
		'</div><div class="ecrm-ai-status" data-istatus aria-live="polite"></div></div>' +
		'<div data-imap></div>';

	var drop = view.querySelector('[data-idrop]'), input = view.querySelector('[data-ifile]');
	view.querySelector('[data-ipick]').addEventListener('click', function () { input.click(); });
	drop.addEventListener('click', function (e) { if (e.target === drop || e.target.classList.contains('ecrm-drop__title') || e.target.classList.contains('ecrm-drop__icon')) input.click(); });
	['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-drag'); }); });
	['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('is-drag'); }); });
	drop.addEventListener('drop', function (e) { if (e.dataTransfer.files[0]) parseImport(view, e.dataTransfer.files[0]); });
	input.addEventListener('change', function () { if (this.files[0]) parseImport(view, this.files[0]); });
}
function parseImport(view, file) {
	var st = view.querySelector('[data-istatus]');
	st.textContent = 'Ανάγνωση αρχείου…';
	var fd = new FormData(); fd.append('file', file);
	fetch(api('/import/parse'), { method: 'POST', headers: H(), body: fd })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (!d || !d.ok) { st.textContent = ''; toast((d && d.error) || 'Αποτυχία ανάγνωσης.', false); return; }
			importState.columns = d.columns; importState.rows = d.rows; importState.total = d.total;
			st.textContent = 'Διαβάστηκαν ' + d.total + ' γραμμές.';
			autodetect();
			renderMapping(view);
		})
		.catch(function () { st.textContent = ''; toast('Σφάλμα δικτύου.', false); });
}
function autodetect() {
	var cols = importState.columns;
	cols.forEach(function (c, i) {
		var l = (c || '').toLowerCase();
		if (importState.supplyCol < 0 && /(παροχ|ηκασπ|supply)/.test(l)) importState.supplyCol = i;
		if (importState.statusCol < 0 && /(κατάστ|καταστ|status|στάδιο|σταδιο)/.test(l)) importState.statusCol = i;
	});
	if (importState.supplyCol < 0) importState.supplyCol = 0;
	if (importState.statusCol < 0) importState.statusCol = Math.min(2, cols.length - 1);
}
function distinctStatusValues() {
	var sc = importState.statusCol, set = {};
	importState.rows.forEach(function (r) { var v = (r[sc] || '').trim(); if (v) set[v] = true; });
	return Object.keys(set);
}
function renderMapping(view) {
	var wrap = view.querySelector('[data-imap]');
	var colOpts = function (sel) { return importState.columns.map(function (c, i) { return '<option value="' + i + '"' + (i === sel ? ' selected' : '') + '>' + esc(c || ('Στήλη ' + (i + 1))) + '</option>'; }).join(''); };

	var statusSlugs = (ECRM && ECRM.statuses) ? ECRM.statuses : {}; // slug->label
	var distinct = distinctStatusValues();
	var mapRows = distinct.map(function (val) {
		var guess = guessStatus(val);
		var opts = '<option value="">— αγνόησε —</option>' + Object.keys(statusSlugs).map(function (sl) {
			return '<option value="' + sl + '"' + (sl === guess ? ' selected' : '') + '>' + esc(statusSlugs[sl]) + '</option>';
		}).join('');
		importState.statusMap[val] = guess || '';
		return '<tr><td>' + esc(val) + '</td><td><select class="ecrm-select" data-smap="' + esc(val) + '">' + opts + '</select></td></tr>';
	}).join('');

	wrap.innerHTML =
		'<div class="ecrm-card"><div class="ecrm-step">1 · Αντιστοίχιση στηλών</div>' +
		'<div class="ecrm-row"><span class="ecrm-row__label">Αριθμός παροχής</span><select class="ecrm-select" data-supplycol>' + colOpts(importState.supplyCol) + '</select></div>' +
		'<div class="ecrm-row"><span class="ecrm-row__label">Κατάσταση</span><select class="ecrm-select" data-statuscol>' + colOpts(importState.statusCol) + '</select></div></div>' +
		'<div class="ecrm-card"><div class="ecrm-step">2 · Αντιστοίχιση καταστάσεων παρόχου → δικές σου</div>' +
		'<div class="ecrm-tablewrap"><table class="ecrm-table"><thead><tr><th>Κατάσταση παρόχου</th><th>Δική σου κατάσταση</th></tr></thead><tbody>' + (mapRows || '<tr><td colspan="2">—</td></tr>') + '</tbody></table></div></div>' +
		'<div class="ecrm-card"><div class="ecrm-step">3 · Εφαρμογή</div>' +
		'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-preview>Προεπισκόπηση</button> ' +
		'<button type="button" class="ecrm-btn ecrm-btn--primary ecrm-btn--sm" data-apply>Ενημέρωση καταστάσεων</button>' +
		'<div class="ecrm-import-report" data-report></div></div>';

	wrap.querySelector('[data-supplycol]').addEventListener('change', function () { importState.supplyCol = +this.value; });
	wrap.querySelector('[data-statuscol]').addEventListener('change', function () { importState.statusCol = +this.value; renderMapping(view); });
	wrap.querySelectorAll('[data-smap]').forEach(function (sel) { sel.addEventListener('change', function () { importState.statusMap[this.getAttribute('data-smap')] = this.value; }); });
	wrap.querySelector('[data-preview]').addEventListener('click', function () { applyImport(view, true, this); });
	wrap.querySelector('[data-apply]').addEventListener('click', function () { if (confirm('Ενημέρωση καταστάσεων στις συμβάσεις;')) applyImport(view, false, this); });
}
function guessStatus(val) {
	var l = (val || '').toLowerCase();
	if (/(ενεργ|active)/.test(l)) return 'active';
	if (/(ακυρ|cancel)/.test(l)) return 'cancelled';
	if (/(εκκρεμ|pending)/.test(l)) return 'pending';
	if (/(δρομολ|rout)/.test(l)) return 'routed';
	if (/(επιλ|resolv)/.test(l)) return 'resolved';
	if (/(υπογρ|sign)/.test(l)) return 'pending_signature';
	if (/(επεξεργ|process)/.test(l)) return 'processing';
	if (/(τερματ|terminat)/.test(l)) return 'terminated';
	if (/(νέα|νεα|new)/.test(l)) return 'new';
	return '';
}
function buildPairs() {
	var sc = importState.supplyCol, stc = importState.statusCol, pairs = [];
	importState.rows.forEach(function (r) {
		var supply = (r[sc] || '').trim();
		var raw = (r[stc] || '').trim();
		var slug = importState.statusMap[raw] || '';
		if (supply && slug) pairs.push({ supply: supply, status: slug });
	});
	return pairs;
}
function applyImport(view, dry, btn) {
	var pairs = buildPairs();
	if (!pairs.length) { toast('Δεν υπάρχουν αντιστοιχισμένες εγγραφές.', false); return; }
	btn.disabled = true; var t = btn.textContent; btn.textContent = '…';
	fetch(api('/import/apply'), { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H()), body: JSON.stringify({ pairs: pairs, dry: dry }) })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (!d || !d.ok) { toast((d && d.error) || 'Αποτυχία.', false); return; }
			var rep = view.querySelector('[data-report]');
			rep.innerHTML = '<div class="ecrm-import-stats">' +
				'<span>Βρέθηκαν: <b>' + d.matched + '</b></span>' +
				'<span>' + (dry ? 'Θα ενημερωθούν' : 'Ενημερώθηκαν') + ': <b>' + d.updated + '</b></span>' +
				'<span>Ίδια: <b>' + d.unchanged + '</b></span>' +
				'<span>Χωρίς αντιστοίχιση: <b>' + d.unmatched_total + '</b></span></div>' +
				(d.unmatched_total ? '<div class="ecrm-muted" style="margin-top:8px">Δεν βρέθηκαν: ' + d.unmatched.map(esc).join(', ') + (d.unmatched_total > d.unmatched.length ? '…' : '') + '</div>' : '');
			toast(dry ? 'Προεπισκόπηση έτοιμη.' : ('Ενημερώθηκαν ' + d.updated + ' συμβάσεις.'));
		})
		.catch(function () { toast('Σφάλμα δικτύου.', false); })
		.finally(function () { btn.disabled = false; btn.textContent = t; });
}
