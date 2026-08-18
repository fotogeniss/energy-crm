/* Energy CRM — knowledge base: provider paperwork, filtered. */

import { api, esc, fetch, H, toast, viewEl } from '@energy-crm/util';

var kbState = { q: '', energy: '', section: '', type: '', provider: 0 };
export function loadKB() {
	var view = viewEl('kb');
	var qs = '?q=' + encodeURIComponent(kbState.q) + '&energy=' + kbState.energy + '&section=' + kbState.section + '&type=' + kbState.type + '&provider=' + kbState.provider;
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
function renderKB(view, d) {
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

	if (!(d.groups || []).length) { groupsHTML = '<div class="ecrm-card"><div class="ecrm-empty">Δεν βρέθηκαν ενότητες.</div></div>'; }

	view.innerHTML =
		'<header class="ecrm-head"><div class="ecrm-eyebrow">Αναφορά</div><h2 class="ecrm-title">Βάση Γνώσης</h2>' +
		'<p class="ecrm-sub">Δικαιολογητικά, εγγυήσεις & χρεώσεις ανά πάροχο</p></header>' +
		'<div class="ecrm-kbsearchbox">' +
			'<div class="ecrm-kbsearchbox__title">Τι χρειάζεσαι για να κλείσεις τη σύμβαση;</div>' +
			'<div class="ecrm-kbsearchbox__q"><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg> <input type="text" data-kbq placeholder="Αναζήτηση ή ρώτησε τη Λίτσα…" value="' + esc(kbState.q) + '">' +
				'<button type="button" class="ecrm-kbask" data-kbask><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l1.6 4.4L18 9l-4.4 1.6L12 15l-1.6-4.4L6 9l4.4-1.6z"/><path d="M18 15l.8 2.2 2.2.8-2.2.8-.8 2.2-.8-2.2-2.2-.8 2.2-.8z"/></svg> Ρώτησε τη Λίτσα</button></div>' +
			'<div class="ecrm-kbanswer" data-kbanswer hidden></div>' +
			'<div class="ecrm-kbfilters">' +
				'<div class="ecrm-kbfilter"><span>Ενέργεια</span>' + energyChips + '</div>' +
				'<div class="ecrm-kbfilter"><span>Ενότητα</span>' + sectionChips + '</div>' +
				'<div class="ecrm-kbfilter"><span>Τύπος</span>' + typeChips + '</div>' +
			'</div>' +
		'</div>' +
		'<div class="ecrm-kblist">' + groupsHTML + '</div>';

	// wiring
	var qEl = view.querySelector('[data-kbq]');
	qEl.addEventListener('input', function () { kbState.q = this.value; clearTimeout(kbT); kbT = setTimeout(loadKB, 300); });

	function kbAsk() {
		var q = (qEl.value || '').trim();
		var box = view.querySelector('[data-kbanswer]');
		if (!q) { toast('Γράψε μια ερώτηση.', false); return; }
		box.hidden = false;
		box.innerHTML = '<div class="ecrm-kbanswer__loading">Η Λίτσα ψάχνει στη Βάση Γνώσης…</div>';
		fetch(api('/kb/ask'), { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H()), body: JSON.stringify({ q: q }) })
			.then(function (r) { return r.json(); })
			.then(function (d) {
				if (!d || !d.ok) { box.innerHTML = '<div class="ecrm-kbanswer__err">' + esc((d && d.error) || 'Αποτυχία.') + '</div>'; return; }
				var html = esc(d.reply).replace(/\n/g, '<br>');
				box.innerHTML = '<div class="ecrm-kbanswer__head"><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l1.6 4.4L18 9l-4.4 1.6L12 15l-1.6-4.4L6 9l4.4-1.6z"/><path d="M18 15l.8 2.2 2.2.8-2.2.8-.8 2.2-.8-2.2-2.2-.8 2.2-.8z"/></svg> Λίτσα</div><div class="ecrm-kbanswer__body">' + html + '</div>' +
					'<button type="button" class="ecrm-kbanswer__close" data-kbclose>Κλείσιμο</button>';
				box.querySelector('[data-kbclose]').addEventListener('click', function () { box.hidden = true; box.innerHTML = ''; });
			})
			.catch(function () { box.innerHTML = '<div class="ecrm-kbanswer__err">Σφάλμα δικτύου.</div>'; });
	}
	view.querySelector('[data-kbask]').addEventListener('click', kbAsk);
	qEl.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); kbAsk(); } });
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
	// keep focus in the search field after re-render
	if (document.activeElement !== qEl && kbState.q) { qEl.focus(); qEl.setSelectionRange(qEl.value.length, qEl.value.length); }
}
