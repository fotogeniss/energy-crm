/* Energy CRM — Excel import: read a sheet, map its columns, apply.
 *
 * Everything here is client-side until applyImport(); the file never leaves
 * the browser before the operator has seen the mapping they are about to run. */

import { api, esc, fetch, H, toast, viewEl } from '@energy-crm/util';

var importState = { columns: [], rows: [], total: 0, supplyCol: -1, statusCol: -1, messageCol: -1,
	statusMap: {}, providerId: 0, providers: [], saved: [], guessed: [], hasSaved: false };
export function initImport() {
	var view = viewEl('import');
	// Only render the dropzone once per visit (reset state each entry).
	importState = { columns: [], rows: [], total: 0, supplyCol: -1, statusCol: -1, messageCol: -1,
	statusMap: {}, providerId: 0, providers: [], saved: [], guessed: [], hasSaved: false };
	view.innerHTML =
		'<header class="ecrm-head"><h2 class="ecrm-title">Εισαγωγή Excel παρόχου</h2>' +
		'<p class="ecrm-sub">Ανέβασε το αρχείο του παρόχου, αντιστοίχισε στήλες και ενημέρωσε τα statuses βάσει αριθμού παροχής.</p></header>' +
		'<div class="ecrm-card"><div class="ecrm-step">1 · Αρχείο παρόχου</div>' +
		'<div class="ecrm-row"><span class="ecrm-row__label">Πάροχος</span>' +
		'<select class="ecrm-select" data-iprovider><option value="0">— φόρτωση… —</option></select></div>' +
		'<div class="ecrm-muted" style="margin-top:6px;font-size:12.5px">Χωρίς πάροχο η εισαγωγή ' +
		'δουλεύει κανονικά — απλώς δεν θυμάται τις επιλογές σου και δεν περιορίζει το ταίριασμα.</div></div>' +
		'<div class="ecrm-card ecrm-card--ai">' +
		'<div class="ecrm-drop" data-idrop tabindex="0" role="button">' +
		'<input type="file" data-ifile accept=".xlsx,.csv" hidden>' +
		'<div class="ecrm-drop__icon"><svg class="ecrm-i ecrm-i--drop" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V7m0 0l-4 4m4-4l4 4M4 21h16"/></svg></div><div class="ecrm-drop__title">Σύρε το Excel/CSV εδώ</div>' +
		'<div class="ecrm-drop__hint">ή <button type="button" class="ecrm-link" data-ipick>πάτα για επιλογή</button> · .xlsx ή .csv</div>' +
		'</div><div class="ecrm-ai-status" data-istatus aria-live="polite"></div></div>' +
		'<div data-imap></div>';

	/* Οι πάροχοι έρχονται από την ΙΔΙΑ διαδρομή που ήδη τροφοδοτεί τη φόρμα
	   νέας αίτησης. Δεν προστέθηκε τίποτα στον server γι' αυτό. */
	fetch(api('/providers'), { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			importState.providers = (d && d.providers) || [];
			var sel = view.querySelector('[data-iprovider]');
			if (!sel) { return; }
			sel.innerHTML = '<option value="0">— διάλεξε πάροχο —</option>' +
				importState.providers.map(function (p) {
					return '<option value="' + p.id + '">' + esc(p.name) + '</option>';
				}).join('');
		})
		.catch(function () { /* σιωπηλά: χωρίς πάροχο η εισαγωγή δουλεύει, απλώς δεν θυμάται */ });

	var drop = view.querySelector('[data-idrop]'), input = view.querySelector('[data-ifile]');
	view.querySelector('[data-ipick]').addEventListener('click', function () { input.click(); });
	drop.addEventListener('click', function (e) { if (e.target === drop || e.target.classList.contains('ecrm-drop__title') || e.target.classList.contains('ecrm-drop__icon')) input.click(); });
	['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-drag'); }); });
	['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('is-drag'); }); });
	drop.addEventListener('drop', function (e) { if (e.dataTransfer.files[0]) parseImport(view, e.dataTransfer.files[0]); });
	input.addEventListener('change', function () { if (this.files[0]) parseImport(view, this.files[0]); });

	/* Αλλαγή παρόχου ΜΕΤΑ την ανάγνωση του αρχείου: ξαναρωτάμε τον server, γιατί
	   ο χάρτης είναι άλλου παρόχου πλέον. Πριν το αρχείο δεν υπάρχει τι να λυθεί. */
	view.querySelector('[data-iprovider]').addEventListener('change', function () {
		importState.providerId = parseInt(this.value, 10) || 0;
		if (importState.columns.length) { resolveStatuses(view); }
	});
}

/* Ο server αποφασίζει τι σημαίνει κάθε τιμή, όχι ο browser.
 *
 * Ως τις 28/08 η αντιστοίχιση και οι ευρετικές (guessStatus) ζούσαν ΕΔΩ, σε
 * JavaScript — δηλαδή ήταν απρόσιτες σε οτιδήποτε δεν είναι ανοιχτή καρτέλα.
 * Ένα cron ή ένα webhook παρόχου δεν έχει browser (HANDOVER §1.13). Τώρα η
 * ίδια διαδρομή εξυπηρετεί και τα δύο· εδώ μένει μόνο η ζωγραφική. */
function resolveStatuses(view) {
	var values = distinctStatusValues();

	if (!values.length) { renderMapping(view); return; }

	fetch(api('/providers/' + importState.providerId + '/status-map/resolve'), {
		method: 'POST',
		headers: Object.assign({ 'Content-Type': 'application/json' }, H()),
		body: JSON.stringify({ values: values })
	})
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (!d || !d.ok) { return; }
			importState.statusMap = d.map || {};
			importState.saved = d.saved || [];
			importState.guessed = d.guessed || [];
			importState.hasSaved = !!d.has_saved;
		})
		.catch(function () { /* σιωπηλά: η οθόνη ζωγραφίζει ό,τι έχει */ })
		.finally(function () { renderMapping(view); });
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
			resolveStatuses(view);
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
function providerName() {
	var found = importState.providers.filter(function (p) { return +p.id === importState.providerId; })[0];

	return found ? found.name : 'τον πάροχο';
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
	var savedSet = {}; importState.saved.forEach(function (v) { savedSet[v] = true; });
	var mapRows = distinct.map(function (val) {
		var chosen = importState.statusMap[val] || '';
		var opts = '<option value="">— αγνόησε —</option>' + Object.keys(statusSlugs).map(function (sl) {
			return '<option value="' + sl + '"' + (sl === chosen ? ' selected' : '') + '>' + esc(statusSlugs[sl]) + '</option>';
		}).join('');
		/* Η προέλευση φαίνεται επίτηδες. Χωρίς αυτήν ο χρήστης βλέπει πέντε
		   συμπληρωμένα κουτιά και δεν ξέρει ποιο αποφάσισε άνθρωπος και ποιο
		   μάντεψε μηχανή — δηλαδή ποιο αξίζει να ελέγξει. */
		var origin = savedSet[val] ? 'αποθηκευμένη' : (chosen ? 'εικασία' : 'νέα τιμή');
		return '<tr><td>' + esc(val) + '</td><td><select class="ecrm-select" data-smap="' + esc(val) + '">' + opts + '</select></td>' +
			'<td class="ecrm-muted">' + origin + '</td></tr>';
	}).join('');

	var banner;
	if (!importState.providerId) {
		banner = '<div class="ecrm-import-banner is-mute"><b>Χωρίς πάροχο — μόνο αυτόματη αναγνώριση</b>' +
			'Διάλεξε πάροχο για να θυμάται τις επιλογές σου την επόμενη φορά.</div>';
	} else if (importState.hasSaved) {
		banner = '<div class="ecrm-import-banner is-ok"><b>Αποθηκευμένη αντιστοίχιση · ' + esc(providerName()) + '</b>' +
			importState.saved.length + ' από ' + distinct.length + ' ήρθαν έτοιμες.</div>';
	} else {
		banner = '<div class="ecrm-import-banner is-warn"><b>Πρώτη φορά για ' + esc(providerName()) + '</b>' +
			'Οι επιλογές είναι αυτόματες εικασίες. Θα αποθηκευτούν όταν πατήσεις «Ενημέρωση καταστάσεων».</div>';
	}

	wrap.innerHTML =
		'<div class="ecrm-card"><div class="ecrm-step">2 · Αντιστοίχιση στηλών</div>' +
		'<div class="ecrm-row"><span class="ecrm-row__label">Αριθμός παροχής</span><select class="ecrm-select" data-supplycol>' + colOpts(importState.supplyCol) + '</select></div>' +
		'<div class="ecrm-row"><span class="ecrm-row__label">Κατάσταση</span><select class="ecrm-select" data-statuscol>' + colOpts(importState.statusCol) + '</select></div>' +
		'<div class="ecrm-row"><span class="ecrm-row__label">Σχόλιο / αιτία (προαιρετικό)</span><select class="ecrm-select" data-msgcol>' + msgOpts + '</select></div></div>' +
		'<div class="ecrm-card"><div class="ecrm-step">3 · Αντιστοίχιση καταστάσεων παρόχου → δικές σου</div>' + banner +
		'<div class="ecrm-tablewrap"><table class="ecrm-table"><thead><tr><th>Κατάσταση παρόχου</th><th>Δική σου κατάσταση</th><th>Από πού</th></tr></thead><tbody>' + (mapRows || '<tr><td colspan="3">—</td></tr>') + '</tbody></table></div></div>' +
		'<div class="ecrm-card"><div class="ecrm-step">4 · Εφαρμογή</div>' +
		'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-preview>Προεπισκόπηση</button> ' +
		'<button type="button" class="ecrm-btn ecrm-btn--primary ecrm-btn--sm" data-apply>Ενημέρωση καταστάσεων</button>' +
		'<div class="ecrm-import-report" data-report></div></div>';

	wrap.querySelector('[data-supplycol]').addEventListener('change', function () { importState.supplyCol = +this.value; });
	wrap.querySelector('[data-statuscol]').addEventListener('change', function () { importState.statusCol = +this.value; resolveStatuses(view); });
	wrap.querySelector('[data-msgcol]').addEventListener('change', function () { importState.messageCol = +this.value; });
	wrap.querySelectorAll('[data-smap]').forEach(function (sel) { sel.addEventListener('change', function () { importState.statusMap[this.getAttribute('data-smap')] = this.value; }); });
	wrap.querySelector('[data-preview]').addEventListener('click', function () { applyImport(view, true, this); });
	wrap.querySelector('[data-apply]').addEventListener('click', function () { if (confirm('Ενημέρωση καταστάσεων στις συμβάσεις;')) applyImport(view, false, this); });
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
	fetch(api('/import/apply'), {
		method: 'POST',
		headers: Object.assign({ 'Content-Type': 'application/json' }, H()),
		body: JSON.stringify({ pairs: pairs, dry: dry, provider_id: importState.providerId })
	})
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

			/* Ο χάρτης αποθηκεύεται ΜΟΝΟ σε πραγματική εφαρμογή, όχι σε
			   προεπισκόπηση: το dry run είναι «δείξε μου τι θα γινόταν», και δεν
			   πρέπει να αφήνει τίποτα πίσω του. Και μόνο με επιλεγμένο πάροχο —
			   χωρίς αυτόν δεν υπάρχει κλειδί να αποθηκευτεί.
			   Η αποτυχία σιωπά: οι καταστάσεις ενημερώθηκαν ήδη, και το να χαθεί
			   μια διευκόλυνση δεν είναι λόγος να δει ο χρήστης κόκκινο μήνυμα. */
			if (!dry && importState.providerId) {
				fetch(api('/providers/' + importState.providerId + '/status-map'), {
					method: 'PUT',
					headers: Object.assign({ 'Content-Type': 'application/json' }, H()),
					body: JSON.stringify({ map: importState.statusMap })
				}).catch(function () {});
			}
		})
		.catch(function () { toast('Σφάλμα δικτύου.', false); })
		.finally(function () { btn.disabled = false; btn.textContent = t; });
}
