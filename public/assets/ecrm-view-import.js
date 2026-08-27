/* Energy CRM — Excel import: read a sheet, map its columns, apply.
 *
 * Everything here is client-side until applyImport(); the file never leaves
 * the browser before the operator has seen the mapping they are about to run. */

import { api, esc, fetch, H, toast, viewEl } from '@energy-crm/util';

var importState = { columns: [], rows: [], total: 0, supplyCol: -1, statusCol: -1, messageCol: -1, statusMap: {} };
export function initImport() {
	var view = viewEl('import');
	// Only render the dropzone once per visit (reset state each entry).
	importState = { columns: [], rows: [], total: 0, supplyCol: -1, statusCol: -1, messageCol: -1, statusMap: {} };
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
		// Προαιρετική -- ΔΕΝ παίρνει προεπιλογή σαν τις δύο πάνω. Αν ο πάροχος
		// δεν στέλνει τέτοια στήλη, μένει -1 («— καμία —») και τίποτα δεν
		// αλλάζει σε σχέση με σήμερα.
		if (importState.messageCol < 0 && /(σχόλ|σχολ|παρατηρ|αιτιολ|comment|reason|remark)/.test(l)) importState.messageCol = i;
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
	// Προαιρετική, γι' αυτό έχει δικό της «— καμία —» αντί να πέφτει πάνω σε
	// στήλη που δεν είναι δική της -- ο πάροχος σπάνια στέλνει αιτιολογία, και
	// λάθος επιλεγμένη στήλη θα κατέληγε στο ιστορικό της σύμβασης.
	var msgOpts = '<option value="-1"' + (importState.messageCol < 0 ? ' selected' : '') + '>— καμία —</option>' + colOpts(importState.messageCol);

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
		'<div class="ecrm-row"><span class="ecrm-row__label">Κατάσταση</span><select class="ecrm-select" data-statuscol>' + colOpts(importState.statusCol) + '</select></div>' +
		'<div class="ecrm-row"><span class="ecrm-row__label">Σχόλιο / αιτία (προαιρετικό)</span><select class="ecrm-select" data-msgcol>' + msgOpts + '</select></div></div>' +
		'<div class="ecrm-card"><div class="ecrm-step">2 · Αντιστοίχιση καταστάσεων παρόχου → δικές σου</div>' +
		'<div class="ecrm-tablewrap"><table class="ecrm-table"><thead><tr><th>Κατάσταση παρόχου</th><th>Δική σου κατάσταση</th></tr></thead><tbody>' + (mapRows || '<tr><td colspan="2">—</td></tr>') + '</tbody></table></div></div>' +
		'<div class="ecrm-card"><div class="ecrm-step">3 · Εφαρμογή</div>' +
		'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-preview>Προεπισκόπηση</button> ' +
		'<button type="button" class="ecrm-btn ecrm-btn--primary ecrm-btn--sm" data-apply>Ενημέρωση καταστάσεων</button>' +
		'<div class="ecrm-import-report" data-report></div></div>';

	wrap.querySelector('[data-supplycol]').addEventListener('change', function () { importState.supplyCol = +this.value; });
	wrap.querySelector('[data-statuscol]').addEventListener('change', function () { importState.statusCol = +this.value; renderMapping(view); });
	wrap.querySelector('[data-msgcol]').addEventListener('change', function () { importState.messageCol = +this.value; });
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
	var sc = importState.supplyCol, stc = importState.statusCol, mc = importState.messageCol, pairs = [];
	importState.rows.forEach(function (r) {
		var supply = (r[sc] || '').trim();
		var raw = (r[stc] || '').trim();
		var slug = importState.statusMap[raw] || '';
		if (!supply || !slug) return;
		var pair = { supply: supply, status: slug };
		// Ο server κόβει στους 300 χαρακτήρες ούτως ή άλλως -- εδώ απλώς δεν
		// στέλνεται καθόλου κλειδί όταν το κελί είναι κενό ή δεν επιλέχθηκε
		// στήλη, ώστε ο φύλακας «— καμία —» να είναι πράγματι «τίποτα».
		if (mc >= 0) {
			var note = (r[mc] || '').trim();
			if (note) pair.message = note;
		}
		pairs.push(pair);
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
			var refused = Number(d.rejected_total) || 0;
			var noted = Number(d.noted) || 0;

			/* Οι απορρίψεις εμφανίζονται. Το Excel του παρόχου μπορεί να ζητά
			 * μετάβαση που η ροή δεν επιτρέπει — «ακυρωμένη» πίσω σε «ενεργή»,
			 * λόγου χάρη — και ένας αριθμός που δεν φαίνεται είναι σιωπηλή
			 * απώλεια: ο συνεργάτης νομίζει ότι πέρασε ολόκληρο το αρχείο. */
			rep.innerHTML = '<div class="ecrm-import-stats">' +
				'<span>Βρέθηκαν: <b>' + esc(d.matched) + '</b></span>' +
				'<span>' + (dry ? 'Θα ενημερωθούν' : 'Ενημερώθηκαν') + ': <b>' + esc(d.updated) + '</b></span>' +
				'<span>Ίδια: <b>' + esc(d.unchanged) + '</b></span>' +
				(noted ? '<span>' + (dry ? 'Θα καταγραφεί σχόλιο' : 'Καταγράφηκε σχόλιο') + ': <b>' + noted + '</b></span>' : '') +
				(refused ? '<span class="is-warn">Δεν επιτρέπονται: <b>' + refused + '</b></span>' : '') +
				'<span>Χωρίς αντιστοίχιση: <b>' + esc(d.unmatched_total) + '</b></span></div>' +
				(d.unmatched_total ? '<div class="ecrm-muted" style="margin-top:8px">Δεν βρέθηκαν: ' + d.unmatched.map(esc).join(', ') + (d.unmatched_total > d.unmatched.length ? '…' : '') + '</div>' : '') +
				(refused ? '<div class="ecrm-muted" style="margin-top:8px">Η ροή δεν επιτρέπει τη μετάβαση για: ' + d.rejected.map(esc).join(', ') + (refused > d.rejected.length ? '…' : '') + '</div>' : '');

			toast(
				dry ? 'Προεπισκόπηση έτοιμη.' : ('Ενημερώθηκαν ' + d.updated + ' συμβάσεις.'),
				refused === 0
			);
		})
		.catch(function () { toast('Σφάλμα δικτύου.', false); })
		.finally(function () { btn.disabled = false; btn.textContent = t; });
}
