/* Energy CRM — knowledge base: provider paperwork, filtered. */

import { api, esc, fetch, H, providerMark, toast, viewEl } from '@energy-crm/util';

var kbState = { q: '', energy: '', section: '', type: '', provider: 0, sigProv: '', sigTab: '', refocus: false };

// (362) Η καθυστέρηση της αναζήτησης όσο γράφεις. Δεν δηλωνόταν πουθενά: σε
// module (strict mode) το clearTimeout(kbT) πετούσε ReferenceError με το
// πρώτο γράμμα, οπότε η αναζήτηση δεν έτρεχε ποτέ.
var kbT = 0;

/* (362) «Signature»: ο φάκελος του παρόχου. Αριστερά οι πάροχοι, δεξιά ο
 * φάκελος του επιλεγμένου με καρτέλες ανά ενότητα και όλα ανοιχτά για
 * διάβασμα. Ο πάροχος και η καρτέλα διαλέγονται εδώ, όχι στον server: η
 * λίστα φέρνει όλους, ώστε να φαίνεται πόσα έχει ο καθένας.
 * docs/UI-KB-FRESH.html, Β. */
function isSignature() {
	var app = document.getElementById('ecrm-app');
	return !!app && app.getAttribute('data-ui') === 'signature';
}

export function loadKB() {
	var view = viewEl('kb');
	var sig = isSignature();
	var qs = '?q=' + encodeURIComponent(kbState.q) + '&energy=' + kbState.energy +
		'&section=' + (sig ? '' : kbState.section) + '&type=' + kbState.type + '&provider=' + (sig ? 0 : kbState.provider);
	fetch(api('/kb') + qs, { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (!d || !d.ok) { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα.</div></div>'; return; }
			renderKB(view, d);
		})
		.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα δικτύου.</div></div>'; });
}
function kbChip(group, val, label, active) {
	return '<button type="button" class="ecrm-chip2' + (active ? ' is-on' : '') + '" data-kbf="' + group + '" data-kbv="' + esc(val) + '">' + esc(label) + '</button>';
}
var SIG_KB_ICON = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l1.6 4.4L18 9l-4.4 1.6L12 15l-1.6-4.4L6 9l4.4-1.6z"/><path d="M18 15l.8 2.2 2.2.8-2.2.8-.8 2.2-.8-2.2-2.2-.8 2.2-.8z"/></svg>';

function sigSeg(group, options, current) {
	return '<div class="sig-kbseg" role="group">' + options.map(function (o) {
		return '<button type="button" class="' + (current === o[0] ? 'is-on' : '') + '" data-kbf="' + group + '" data-kbv="' + esc(o[0]) + '" aria-pressed="' + (current === o[0]) + '">' + esc(o[1]) + '</button>';
	}).join('') + '</div>';
}

/* Όταν η αναζήτηση δεν βρίσκει τίποτα: πρόταση να ρωτήσει τον AIde, με τη
 * λέξη που έψαξε. Μόνο αν έγραψε κάτι — με κενό κουτί δεν έχει τι να ρωτήσει. */
function askNotFound() {
	var q = String(kbState.q || '').trim();
	if (!q) { return ''; }
	return '<button type="button" class="ecrm-btn ecrm-btn--primary ecrm-btn--sm sig-kbnf" data-kbask data-aide-ask>' +
		'Ρώτησε τον AIde για «' + esc(q.length > 40 ? q.slice(0, 38) + '…' : q) + '»</button>';
}

function sigKbHTML(d) {
	var groups = d.groups || [];
	var sections = d.sections || {};
	var types = d.types || {};

	var keyOf = function (g) { return String(g.provider_id || g.provider); };
	var cur = groups.filter(function (g) { return keyOf(g) === kbState.sigProv; })[0] || groups[0] || null;
	if (cur) { kbState.sigProv = keyOf(cur); }

	var list = groups.map(function (g) {
		var on = cur && keyOf(g) === keyOf(cur);
		return '<button type="button" class="sig-kbprov' + (on ? ' is-on' : '') + '" data-kbprov="' + esc(keyOf(g)) + '" aria-pressed="' + !!on + '">' +
			providerMark(g.provider, g.logo) + '<b>' + esc(g.provider) + '</b><span>' + g.entries.length + '</span></button>';
	}).join('');

	var search = '<div class="sig-kbq"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>' +
		'<input type="text" data-kbq placeholder="Αναζήτηση ή ερώτηση…" value="' + esc(kbState.q) + '" aria-label="Αναζήτηση ή ερώτηση">' +
		'<button type="button" class="sig-kbask" data-kbask data-aide-ask>' + SIG_KB_ICON + '<span>Ρώτησε τον AIde</span></button></div>';

	var head = '<header class="sig-cuhead"><div><h2>Βάση Γνώσης</h2><p>Δικαιολογητικά, εγγυήσεις και χρεώσεις ανά πάροχο</p></div></header>';

	if (!cur) {
		return head + search + '<div class="sig-kbsheet sig-kbsheet--empty"><b>Δεν βρέθηκαν ενότητες.</b>' +
			(kbState.q || kbState.energy || kbState.type ? '<span>Δοκίμασε άλλη λέξη ή βγάλε τα φίλτρα.</span>' : '') +
			askNotFound() + '</div>';
	}

	// Οι καρτέλες: όσες ενότητες έχει αυτός ο πάροχος, με τη σειρά του λεξιλογίου.
	var counts = {};
	cur.entries.forEach(function (e) { counts[e.section] = (counts[e.section] || 0) + 1; });
	var order = Object.keys(sections).concat(Object.keys(counts).filter(function (k) { return !(k in sections); }));
	var tabs = order.filter(function (k) { return counts[k]; });
	if (tabs.indexOf(kbState.sigTab) < 0) { kbState.sigTab = tabs[0] || ''; }

	var tabsHTML = tabs.map(function (k) {
		var on = k === kbState.sigTab;
		return '<button type="button" role="tab" class="' + (on ? 'is-on' : '') + '" data-kbtab="' + esc(k) + '" aria-selected="' + on + '">' +
			esc(sections[k] || k) + '<em>' + counts[k] + '</em></button>';
	}).join('');

	var energies = {};
	cur.entries.forEach(function (e) { if (e.energy_label) { energies[e.energy_label] = 1; } });
	var energyLine = Object.keys(energies).join(' και ').toLowerCase();

	var items = cur.entries.filter(function (e) { return e.section === kbState.sigTab; }).map(function (e) {
		var tags = (e.energy_label ? '<span class="sig-kbtag is-' + esc(e.energy || 'all') + '">' + esc(e.energy_label) + '</span>' : '') +
			(e.type_label ? '<span class="sig-kbtag">' + esc(e.type_label) + '</span>' : '');
		return '<article class="sig-kbitem"><h4>' + esc(e.title) + tags + '</h4><div class="sig-kbbody">' + (e.body || '') + '</div></article>';
	}).join('');

	var n = cur.entries.length;
	return head + search +
		'<div class="sig-kbfilters">' +
			sigSeg('energy', [['', 'Όλα'], ['power', 'Ρεύμα'], ['gas', 'Αέριο']], kbState.energy) +
			sigSeg('type', [['', 'Όλοι']].concat(Object.keys(types).map(function (k) { return [k, types[k]]; })), kbState.type) +
		'</div>' +
		'<div class="sig-kb">' +
			'<nav class="sig-kbprovs" aria-label="Πάροχοι">' + list + '</nav>' +
			'<section class="sig-kbsheet">' +
				'<div class="sig-kbsheet__head">' + providerMark(cur.provider, cur.logo) +
					'<div><h3>' + esc(cur.provider) + '</h3><p>' + n + (n === 1 ? ' ενότητα' : ' ενότητες') + (energyLine ? ' · ' + esc(energyLine) : '') + '</p></div></div>' +
				'<div class="sig-kbtabs" role="tablist">' + tabsHTML + '</div>' +
				'<div class="sig-kbsheet__body">' + items + '</div>' +
			'</section>' +
		'</div>';
}

function renderKB(view, d) {
	if (isSignature()) {
		view.innerHTML = sigKbHTML(d);
		wireKB(view);
		wireSigKB();
		return;
	}

	function wireSigKB() {
		view.querySelectorAll('[data-kbprov]').forEach(function (b) {
			b.addEventListener('click', function () {
				kbState.sigProv = this.getAttribute('data-kbprov');
				kbState.sigTab = '';
				view.innerHTML = sigKbHTML(d);
				wireKB(view);
				wireSigKB();
			});
		});
		view.querySelectorAll('[data-kbtab]').forEach(function (b) {
			b.addEventListener('click', function () {
				kbState.sigTab = this.getAttribute('data-kbtab');
				view.innerHTML = sigKbHTML(d);
				wireKB(view);
				wireSigKB();
			});
		});
	}

	var sections = d.sections || {};
	var types = d.types || {};
	var energyChips = [['', 'Όλα'], ['power', 'Ρεύμα'], ['gas', 'Αέριο']]
		.map(function (e) { return kbChip('energy', e[0], e[1], kbState.energy === e[0]); }).join('');
	var sectionChips = [['', 'Όλες']].concat(Object.keys(sections).map(function (k) { return [k, sections[k]]; }))
		.map(function (e) { return kbChip('section', e[0], e[1], kbState.section === e[0]); }).join('');
	var typeChips = [['', 'Όλοι']].concat(Object.keys(types).map(function (k) { return [k, types[k]]; }))
		.map(function (e) { return kbChip('type', e[0], e[1], kbState.type === e[0]); }).join('');

	var badge = function (cls, txt) { return txt ? '<span class="ecrm-kbbadge ' + cls + '">' + esc(txt) + '</span>' : ''; };

	var groupsHTML = (d.groups || []).map(function (g) {
		var entries = g.entries.map(function (e) {
			return '<div class="ecrm-kbentry" data-kbentry>' +
				'<button type="button" class="ecrm-kbentry__head" data-kbtoggle>' +
					'<span class="ecrm-kbentry__title">' + esc(e.title) + '</span>' +
					'<span class="ecrm-kbentry__badges">' +
						badge('is-energy-' + (e.energy || 'all'), e.energy_label) +
						badge('is-section', e.section_label) +
						badge('is-type', e.type_label) +
					'</span><span class="ecrm-kbentry__chev">›</span>' +
				'</button>' +
				'<div class="ecrm-kbentry__body" hidden>' + (e.body || '') + '</div>' +
			'</div>';
		}).join('');
		return '<div class="ecrm-kbgroup"><div class="ecrm-kbgroup__head">' + esc(g.provider) +
			' <span class="ecrm-kbgroup__count">' + g.entries.length + ' ενότητες</span></div>' + entries + '</div>';
	}).join('');

	if (!(d.groups || []).length) { groupsHTML = '<div class="ecrm-card"><div class="ecrm-empty">Δεν βρέθηκαν ενότητες.' + askNotFound() + '</div></div>'; }

	view.innerHTML =
		'<header class="ecrm-head"><div class="ecrm-eyebrow">Αναφορά</div><h2 class="ecrm-title">Βάση Γνώσης</h2>' +
		'<p class="ecrm-sub">Δικαιολογητικά, εγγυήσεις & χρεώσεις ανά πάροχο</p></header>' +
		'<div class="ecrm-kbsearchbox">' +
			'<div class="ecrm-kbsearchbox__title">Τι χρειάζεσαι για να κλείσεις τη σύμβαση;</div>' +
			'<div class="ecrm-kbsearchbox__q"><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg> <input type="text" data-kbq placeholder="Αναζήτηση ή ρώτησε τον AIde…" value="' + esc(kbState.q) + '">' +
				'<button type="button" class="ecrm-kbask" data-kbask data-aide-ask><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l1.6 4.4L18 9l-4.4 1.6L12 15l-1.6-4.4L6 9l4.4-1.6z"/><path d="M18 15l.8 2.2 2.2.8-2.2.8-.8 2.2-.8-2.2-2.2-.8 2.2-.8z"/></svg> Ρώτησε τον AIde</button></div>' +
			
			'<div class="ecrm-kbfilters">' +
				'<div class="ecrm-kbfilter"><span>Ενέργεια</span>' + energyChips + '</div>' +
				'<div class="ecrm-kbfilter"><span>Ενότητα</span>' + sectionChips + '</div>' +
				'<div class="ecrm-kbfilter"><span>Τύπος</span>' + typeChips + '</div>' +
			'</div>' +
		'</div>' +
		'<div class="ecrm-kblist">' + groupsHTML + '</div>';

	wireKB(view);
}

/** Αναζήτηση, AIde, φίλτρα και άνοιγμα ενότητας. Ίδια data-* και στις δύο εμφανίσεις. */
function wireKB(view) {
	var qEl = view.querySelector('[data-kbq]');
	qEl.addEventListener('input', function () { kbState.q = this.value; kbState.refocus = true; clearTimeout(kbT); kbT = setTimeout(loadKB, 300); });

	/* (367b) Η ερώτηση ανοίγει το πάνελ του AIde αντί να απαντά από κάτω: εκεί
	 * η απάντηση βγαίνει με μορφοποίηση και η συζήτηση συνεχίζεται. */
	function kbAsk() {
		var q = (qEl.value || '').trim();
		if (!q) { toast('Γράψε μια ερώτηση.', false); return; }
		// Μία-τρεις λέξεις χωρίς ερωτηματικό («nrg») δεν είναι ερώτηση: ο AIde
		// τις διάβαζε σαν χαιρετισμό και απαντούσε γενικότητες. Στέλνεται ως
		// ερώτηση για τη Βάση Γνώσης, και φαίνεται έτσι και στο πάνελ.
		if (q.indexOf('?') < 0 && q.indexOf(';') < 0 && q.split(/\s+/).length <= 3) {
			q = 'Τι λέει η Βάση Γνώσης για «' + q + '»;';
		}
		document.dispatchEvent(new CustomEvent('ecrm:ask-aide', { detail: { q: q } }));
	}
	view.querySelectorAll('[data-kbask]').forEach(function (b) { b.addEventListener('click', kbAsk); });
	// (367c) Το Enter ψάχνει στη Βάση Γνώσης, αμέσως· ΔΕΝ ρωτά τον AIde. Μέχρι
	// τώρα κάθε Enter πήγαινε στο AI και ξόδευε κλήσεις για κάτι που η απλή
	// αναζήτηση βρίσκει μόνη της. Ο AIde μόνο με το κουμπί του, ή από το
	// «Δεν βρέθηκε» όταν η αναζήτηση δεν φέρει τίποτα.
	qEl.addEventListener('keydown', function (e) {
		if (e.key !== 'Enter') return;
		e.preventDefault();
		clearTimeout(kbT);
		kbState.q = qEl.value;
		kbState.refocus = true;
		loadKB();
	});
	view.querySelectorAll('[data-kbf]').forEach(function (b) {
		b.addEventListener('click', function () { kbState[this.getAttribute('data-kbf')] = this.getAttribute('data-kbv'); loadKB(); });
	});
	view.querySelectorAll('[data-kbtoggle]').forEach(function (b) {
		b.addEventListener('click', function () {
			var body = this.parentNode.querySelector('.ecrm-kbentry__body');
			var open = !body.hidden; body.hidden = open;
			this.parentNode.classList.toggle('is-open', !open);
		});
	});
	// Η εστίαση γυρίζει στην αναζήτηση μόνο όταν το ξαναζωγράφισμα το
	// προκάλεσε το γράψιμο· όχι μετά από πάτημα σε φίλτρο ή καρτέλα, που στο
	// κινητό θα άνοιγε το πληκτρολόγιο χωρίς λόγο.
	if (kbState.refocus && document.activeElement !== qEl) { qEl.focus(); qEl.setSelectionRange(qEl.value.length, qEl.value.length); }
	kbState.refocus = false;
}
