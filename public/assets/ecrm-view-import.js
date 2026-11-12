/* Energy CRM — Excel import: read a sheet, map its columns, apply.
 *
 * Everything here is client-side until applyImport(); the file never leaves
 * the browser before the operator has seen the mapping they are about to run. */

import { api, esc, fetch, H, providerMark, toast, viewEl } from '@energy-crm/util';

var importState = { columns: [], rows: [], total: 0, supplyCol: -1, statusCol: -1, messageCol: -1,
	statusMap: {}, providerId: 0, providers: [], saved: [], guessed: [], hasSaved: false };
export function initImport() {
	var view = viewEl('import');
	// Only render the dropzone once per visit (reset state each entry).
	importState = { columns: [], rows: [], total: 0, supplyCol: -1, statusCol: -1, messageCol: -1,
	statusMap: {}, providerId: 0, providers: [], saved: [], guessed: [], hasSaved: false };
	var sig = isSignature();
	if (sig) {
		view.innerHTML =
			'<header class="sig-cuhead"><div><h2>Εισαγωγή Excel παρόχου</h2>' +
			'<p>Ενημέρωσε τις καταστάσεις από το αρχείο του παρόχου, με βάση τον αριθμό παροχής.</p></div></header>' +
			'<div class="sig-imp"><div class="sig-imp__main">' +
				'<section class="sig-impbox"><h3>Πάροχος</h3><div class="sig-impprovs" data-iprovtiles role="group" aria-label="Πάροχος">' +
					'<span class="sig-impidle">Φόρτωση…</span></div>' +
					'<select data-iprovider hidden aria-hidden="true" tabindex="-1" class="sig-impsel"><option value="0"></option></select>' +
					'<p class="sig-imphint">Χωρίς πάροχο η εισαγωγή δουλεύει κανονικά· απλώς δεν θυμάται τις επιλογές σου.</p></section>' +
				'<div class="sig-impdrop" data-idrop tabindex="0" role="button" aria-label="Επιλογή αρχείου">' +
					'<input type="file" data-ifile accept=".xlsx,.csv" hidden>' +
					'<b class="ecrm-drop__title">Σύρε το Excel ή CSV εδώ</b>' +
					'<span>ή <button type="button" class="ecrm-link" data-ipick>πάτα για επιλογή</button> · .xlsx ή .csv</span>' +
					'<span class="sig-impfile" data-istatus aria-live="polite"></span></div>' +
				'<div data-imap></div>' +
			'</div>' +
			'<aside class="sig-implive" data-ilive aria-live="polite">' + sigLiveIdle('Ανέβασε το αρχείο του παρόχου για να δεις τι θα αλλάξει.') + '</aside></div>';
	} else {
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
	}

	/* Οι πάροχοι έρχονται από την ΙΔΙΑ διαδρομή που ήδη τροφοδοτεί τη φόρμα
	   νέας αίτησης. Δεν προστέθηκε τίποτα στον server γι' αυτό. */
	fetch(api('/providers'), { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			importState.providers = (d && d.providers) || [];
			var sel = view.querySelector('[data-iprovider]');
			if (!sel) { return; }
			if (sig) { paintProviderTiles(view); }
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
	if (isSignature()) { renderSigMapping(view); return; }
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
			if (!dry) { saveMap(); }
		})
		.catch(function () { toast('Σφάλμα δικτύου.', false); })
		.finally(function () { btn.disabled = false; btn.textContent = t; });
}

function saveMap() {
	if (!importState.providerId) { return; }
	fetch(api('/providers/' + importState.providerId + '/status-map'), {
		method: 'PUT',
		headers: Object.assign({ 'Content-Type': 'application/json' }, H()),
		body: JSON.stringify({ map: importState.statusMap })
	}).catch(function () {});
}

/* ═══ (366) «Signature»: ζωντανό αποτέλεσμα ═════════════════════════════════
 * Μία σελίδα: αριστερά πάροχος, αρχείο, στήλες και καταστάσεις· δεξιά σκούρα
 * κάρτα που λέει συνέχεια πόσες αιτήσεις θα αλλάξουν. Η κάρτα ανανεώνεται
 * μόνη της με κάθε αλλαγή, με την ίδια προεπισκόπηση (dry run) που είχε το
 * κουμπί· το κουμπί εφαρμογής γράφει τον αριθμό, οπότε δεν χρειάζεται
 * παράθυρο επιβεβαίωσης. Ίδια διαδρομή στον server.
 * docs/UI-IMPORT-FRESH.html, Β. */
function isSignature() {
	var app = document.getElementById('ecrm-app');
	return !!app && app.getAttribute('data-ui') === 'signature';
}

function sigLiveIdle(text) {
	return '<small>ΑΝ ΤΟ ΕΦΑΡΜΟΣΕΙΣ ΤΩΡΑ</small><p class="sig-implive__idle">' + esc(text) + '</p>';
}

function paintProviderTiles(view) {
	var box = view.querySelector('[data-iprovtiles]');
	if (!box) { return; }
	var tile = function (id, name, logo) {
		var on = importState.providerId === id;
		return '<button type="button" class="sig-impprov' + (on ? ' is-on' : '') + '" data-iprov="' + id + '" aria-pressed="' + on + '">' +
			(id ? providerMark(name, logo) : '') + '<span>' + esc(name) + '</span></button>';
	};
	box.innerHTML = importState.providers.map(function (p) {
		return tile(parseInt(p.id, 10) || 0, p.name, p.logo_url || '');
	}).join('') + tile(0, 'Χωρίς πάροχο', '');
	box.querySelectorAll('[data-iprov]').forEach(function (b) {
		b.addEventListener('click', function () {
			importState.providerId = parseInt(this.getAttribute('data-iprov'), 10) || 0;
			paintProviderTiles(view);
			if (importState.columns.length) { resolveStatuses(view); }
		});
	});
}

/* Ένα παράδειγμα τιμής από το αρχείο, για να φαίνεται ότι η στήλη είναι η σωστή. */
function sampleOf(col) {
	if (col < 0) { return ''; }
	for (var i = 0; i < importState.rows.length; i++) {
		var v = String(importState.rows[i][col] || '').trim();
		if (v) { return v.length > 40 ? v.slice(0, 38) + '…' : v; }
	}
	return '';
}

function renderSigMapping(view) {
	var wrap = view.querySelector('[data-imap]');
	var colOpts = function (sel) {
		return importState.columns.map(function (c, i) {
			return '<option value="' + i + '"' + (i === sel ? ' selected' : '') + '>' + esc(c || ('Στήλη ' + (i + 1))) + '</option>';
		}).join('');
	};
	var msgOpts = '<option value="-1"' + (importState.messageCol < 0 ? ' selected' : '') + '>— καμία —</option>' + colOpts(importState.messageCol);
	var colRow = function (label, attr, opts, col, note) {
		var ex = sampleOf(col);
		return '<div class="sig-impcol"><span class="sig-impcol__l">' + label + '</span>' +
			'<select class="ecrm-select" ' + attr + ' aria-label="' + esc(label) + '">' + opts + '</select>' +
			'<span class="sig-impcol__ex">' + (ex ? 'π.χ. ' + esc(ex) : esc(note || '')) + '</span></div>';
	};

	var statusSlugs = (window.ECRM && window.ECRM.statuses) ? window.ECRM.statuses : {};
	var counts = {};
	importState.rows.forEach(function (r) { var v = String(r[importState.statusCol] || '').trim(); if (v) { counts[v] = (counts[v] || 0) + 1; } });
	var savedSet = {}; importState.saved.forEach(function (v) { savedSet[v] = true; });

	var pairs = distinctStatusValues().map(function (val) {
		var chosen = importState.statusMap[val] || '';
		var opts = '<option value="">— αγνόησε —</option>' + Object.keys(statusSlugs).map(function (sl) {
			return '<option value="' + esc(sl) + '"' + (sl === chosen ? ' selected' : '') + '>' + esc(statusSlugs[sl]) + '</option>';
		}).join('');
		var origin = savedSet[val] ? ['is-saved', 'αποθηκευμένη'] : (chosen ? ['is-guess', 'εικασία'] : ['is-new', 'νέα τιμή']);
		var n = counts[val] || 0;
		return '<div class="sig-imppair' + (chosen ? '' : ' is-ignored') + '">' +
			'<span class="sig-imppair__from"><b>' + esc(val) + '</b><small>' + n + (n === 1 ? ' γραμμή' : ' γραμμές') + '</small></span>' +
			'<span class="sig-imppair__arr" aria-hidden="true">→</span>' +
			'<select class="ecrm-select" data-smap="' + esc(val) + '" aria-label="Δική σου κατάσταση για ' + esc(val) + '">' + opts + '</select>' +
			'<span class="sig-imporg ' + origin[0] + '">' + origin[1] + '</span></div>';
	}).join('');

	var banner = !importState.providerId
		? 'Χωρίς πάροχο: μόνο αυτόματη αναγνώριση, και οι επιλογές δεν θυμούνται.'
		: (importState.hasSaved
			? importState.saved.length + ' από ' + distinctStatusValues().length + ' ήρθαν έτοιμες από την αποθηκευμένη αντιστοίχιση.'
			: 'Πρώτη φορά για ' + providerName() + ': οι επιλογές είναι εικασίες και αποθηκεύονται όταν εφαρμόσεις.');

	wrap.innerHTML =
		'<section class="sig-impbox"><h3>Στήλες</h3>' +
			colRow('Αριθμός παροχής', 'data-supplycol', colOpts(importState.supplyCol), importState.supplyCol) +
			colRow('Κατάσταση', 'data-statuscol', colOpts(importState.statusCol), importState.statusCol) +
			colRow('Σχόλιο', 'data-msgcol', msgOpts, importState.messageCol, 'προαιρετικό') +
		'</section>' +
		'<section class="sig-impbox"><h3>Καταστάσεις</h3><p class="sig-imphint">' + esc(banner) + '</p>' +
			'<div class="sig-imppairs">' + (pairs || '<p class="sig-imphint">Η στήλη κατάστασης είναι κενή.</p>') + '</div></section>';

	wrap.querySelector('[data-supplycol]').addEventListener('change', function () { importState.supplyCol = +this.value; renderSigMapping(view); });
	wrap.querySelector('[data-statuscol]').addEventListener('change', function () { importState.statusCol = +this.value; resolveStatuses(view); });
	wrap.querySelector('[data-msgcol]').addEventListener('change', function () { importState.messageCol = +this.value; renderSigMapping(view); });
	wrap.querySelectorAll('[data-smap]').forEach(function (sel) {
		sel.addEventListener('change', function () {
			importState.statusMap[this.getAttribute('data-smap')] = this.value;
			renderSigMapping(view);
		});
	});

	sigPreview(view);
}

/* Η προεπισκόπηση τρέχει μόνη της, λίγο μετά την τελευταία αλλαγή. Μόνο η πιο
 * πρόσφατη απάντηση ζωγραφίζεται, ώστε μια αργή παλιά να μη σβήνει τη νέα. */
var previewTimer = 0;
var previewSeq = 0;
function sigPreview(view) {
	clearTimeout(previewTimer);
	var live = view.querySelector('[data-ilive]');
	if (!live) { return; }
	var pairs = buildPairs();
	if (!pairs.length) {
		live.innerHTML = sigLiveIdle('Καμία γραμμή δεν αντιστοιχίζεται ακόμα σε δική σου κατάσταση. Διάλεξε κατάσταση στα ζευγάρια αριστερά.');
		return;
	}
	live.classList.add('is-busy');
	previewTimer = setTimeout(function () {
		var seq = ++previewSeq;
		postApply(pairs, true)
			.then(function (d) { if (seq === previewSeq) { paintLive(view, d, true); } })
			.catch(function () { if (seq === previewSeq) { live.innerHTML = sigLiveIdle('Η προεπισκόπηση δεν ήρθε (σφάλμα δικτύου).'); } })
			.finally(function () { if (seq === previewSeq) { live.classList.remove('is-busy'); } });
	}, 350);
}

function postApply(pairs, dry) {
	return fetch(api('/import/apply'), {
		method: 'POST',
		headers: Object.assign({ 'Content-Type': 'application/json' }, H()),
		body: JSON.stringify({ pairs: pairs, dry: dry, provider_id: importState.providerId })
	}).then(function (r) { return r.json(); }).then(function (d) {
		if (!d || !d.ok) { throw new Error((d && d.error) || 'Αποτυχία.'); }
		return d;
	});
}

function paintLive(view, d, dry, done) {
	var live = view.querySelector('[data-ilive]');
	if (!live) { return; }
	var n = Number(d.updated) || 0;
	var refused = Number(d.rejected_total) || 0;
	var missing = Number(d.unmatched_total) || 0;
	var noted = Number(d.noted) || 0;
	var line = function (label, v, cls) { return '<div class="sig-implive__ln' + (cls ? ' ' + cls : '') + '"><span>' + label + '</span><b>' + v + '</b></div>'; };
	var list = function (arr, total) { return esc((arr || []).join(', ')) + (total > (arr || []).length ? '…' : ''); };

	live.innerHTML =
		(done ? '<div class="sig-implive__done">✓ Ενημερώθηκαν ' + esc(String(done)) + (done === 1 ? ' αίτηση' : ' αιτήσεις') + '</div>' : '') +
		'<small>ΑΝ ΤΟ ΕΦΑΡΜΟΣΕΙΣ ΤΩΡΑ</small>' +
		'<div class="sig-implive__big">' + n + '</div>' +
		'<p>' + (n === 1 ? 'αίτηση αλλάζει κατάσταση' : 'αιτήσεις αλλάζουν κατάσταση') + '</p>' +
		line('Βρέθηκαν στο CRM', Number(d.matched) || 0) +
		line('Ίδιες ήδη', Number(d.unchanged) || 0) +
		(noted ? line('Με σχόλιο', noted) : '') +
		line('Δεν επιτρέπονται', refused, refused ? 'is-warn' : '') +
		line('Δεν βρέθηκαν', missing) +
		(missing ? '<details class="sig-implive__more"><summary>Ποιοι δεν βρέθηκαν</summary><p>' + list(d.unmatched, missing) + '</p></details>' : '') +
		(refused ? '<details class="sig-implive__more"><summary>Τι δεν επιτρέπει η ροή</summary><p>' + list(d.rejected, refused) + '</p></details>' : '') +
		'<button type="button" class="ecrm-btn ecrm-btn--primary" data-apply' + (n ? '' : ' disabled') + '>' +
			(n ? 'Ενημέρωσε ' + n + (n === 1 ? ' αίτηση' : ' αιτήσεις') : 'Τίποτα να ενημερωθεί') + '</button>' +
		(importState.providerId ? '<span class="sig-implive__note">Η αντιστοίχιση θα αποθηκευτεί για την επόμενη φορά.</span>' : '');

	var btn = live.querySelector('[data-apply]');
	if (btn && n) {
		btn.addEventListener('click', function () {
			var pairs = buildPairs();
			btn.disabled = true; btn.textContent = 'Ενημέρωση…';
			postApply(pairs, false)
				.then(function (res) {
					saveMap();
					toast('Ενημερώθηκαν ' + res.updated + ' αιτήσεις.', !(Number(res.rejected_total) > 0));
					// Η νέα εικόνα: μετά την εφαρμογή, τι θα άλλαζε τώρα (κανονικά τίποτα).
					return postApply(pairs, true).then(function (d2) { paintLive(view, d2, true, Number(res.updated) || 0); });
				})
				.catch(function (e) { toast((e && e.message) || 'Σφάλμα δικτύου.', false); btn.disabled = false; btn.textContent = 'Ενημέρωσε ' + n + ' αιτήσεις'; });
		});
	}
}
