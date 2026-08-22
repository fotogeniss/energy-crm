/* Energy CRM — leads: the pipeline before a contract exists.
 *
 * Sends the user two ways — to a contract's detail once a lead has been
 * converted, and to the form when it is time to convert one. */

import { api, esc, fetch, H, toast, viewEl } from '@energy-crm/util';
import { fmtDate } from '@energy-crm/format';
import { go, openDetail } from '@energy-crm/navigate';

var leadsState = { stage: '', q: '', editing: null, showForm: false };
export function loadLeads() {
	var view = viewEl('leads');
	var qs = '?stage=' + encodeURIComponent(leadsState.stage) + '&q=' + encodeURIComponent(leadsState.q);
	fetch(api('/leads') + qs, { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (!d || !d.ok) { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα.</div></div>'; return; }
			renderLeads(view, d);
		})
		.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα δικτύου.</div></div>'; });
}
function leadCbInput(v) {
	if (!v) return '';
	// 'YYYY-MM-DD HH:MM:SS' -> 'YYYY-MM-DDTHH:MM'
	return v.replace(' ', 'T').slice(0, 16);
}
function renderLeads(view, d) {
	var stages = d.stages || {}, sources = d.sources || {}, counts = d.counts || {};
	var sChips = [['', 'Όλα']].concat(Object.keys(stages).map(function (k) { return [k, stages[k]]; }))
		.map(function (e) {
			var n = e[0] === '' ? '' : (counts[e[0]] ? ' (' + counts[e[0]] + ')' : '');
			return '<button type="button" class="ecrm-chip2' + (leadsState.stage === e[0] ? ' is-on' : '') + '" data-lstage="' + esc(e[0]) + '">' + esc(e[1]) + n + '</button>';
		}).join('');

	var ed = leadsState.editing || {};
	var opts = function (map, sel) { return '<option value="">—</option>' + Object.keys(map).map(function (k) { return '<option value="' + esc(k) + '"' + (sel === k ? ' selected' : '') + '>' + esc(map[k]) + '</option>'; }).join(''); };
	var energyMap = { power: 'Ρεύμα', gas: 'Αέριο', mobile: 'Κινητή' };

	var form = !leadsState.showForm ? '' :
		'<div class="ecrm-card ecrm-leadform">' +
			'<div class="ecrm-leadform__grid">' +
				'<label class="ecrm-field"><span class="ecrm-field__label">Όνομα *</span><input class="ecrm-input" data-lf="name" value="' + esc(ed.name || '') + '"></label>' +
				'<label class="ecrm-field"><span class="ecrm-field__label">Τηλέφωνο</span><input class="ecrm-input" data-lf="phone" value="' + esc(ed.phone || '') + '"></label>' +
				'<label class="ecrm-field"><span class="ecrm-field__label">Email</span><input class="ecrm-input" data-lf="email" value="' + esc(ed.email || '') + '"></label>' +
				'<label class="ecrm-field"><span class="ecrm-field__label">Πηγή</span><select class="ecrm-input" data-lf="source">' + opts(sources, ed.source) + '</select></label>' +
				'<label class="ecrm-field"><span class="ecrm-field__label">Ενδιαφέρον για</span><select class="ecrm-input" data-lf="energy_type">' + opts(energyMap, ed.energy_type) + '</select></label>' +
				'<label class="ecrm-field"><span class="ecrm-field__label">Στάδιο</span><select class="ecrm-input" data-lf="stage">' + Object.keys(stages).map(function (k) { return '<option value="' + k + '"' + ((ed.stage || 'new') === k ? ' selected' : '') + '>' + esc(stages[k]) + '</option>'; }).join('') + '</select></label>' +
				'<label class="ecrm-field"><span class="ecrm-field__label">Επανάκληση</span><input type="datetime-local" class="ecrm-input" data-lf="callback_at" value="' + esc(leadCbInput(ed.callback_at)) + '"></label>' +
				'<label class="ecrm-field ecrm-field--wide"><span class="ecrm-field__label">Σημείωση ενδιαφέροντος</span><input class="ecrm-input" data-lf="interest" value="' + esc(ed.interest || '') + '" placeholder="π.χ. αλλαγή παρόχου ρεύματος, 2 παροχές"></label>' +
				'<label class="ecrm-field ecrm-field--wide"><span class="ecrm-field__label">Σημειώσεις</span><textarea class="ecrm-textarea" data-lf="notes" rows="2">' + esc(ed.notes || '') + '</textarea></label>' +
			'</div>' +
			'<div class="ecrm-leadform__bar"><button type="button" class="ecrm-btn ecrm-btn--primary" data-lsave>' + (ed.id ? 'Αποθήκευση' : 'Προσθήκη lead') + '</button>' +
				'<button type="button" class="ecrm-btn ecrm-btn--ghost" data-lcancel>Άκυρο</button></div>' +
		'</div>';

	var now = Date.now();
	var cards = (d.leads || []).map(function (l) {
		var cb = '';
		if (l.callback_at) {
			// ΧΩΡΙΣ 'Z'. Το callback_at είναι ΤΟΠΙΚΗ ώρα, όπως όλες οι
			// αποθηκευμένες. Με το 'Z' διαβαζόταν ως UTC, άρα το «ληξιπρόθεσμο»
			// άργουνε 3 ώρες — η κάρτα έδειχνε τη σωστή ώρα (fmtDate) και
			// ταυτόχρονα δεν κοκκίνιζε. Παρτίδα (84), CHANGELOG.
			var due = new Date(l.callback_at.replace(' ', 'T')).getTime();
			var overdue = due <= now && l.stage !== 'won' && l.stage !== 'lost';
			cb = '<span class="ecrm-leadcb' + (overdue ? ' is-over' : '') + '"><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h4l2 5-2.5 1.5a12 12 0 005 5L15 13l5 2v4a1 1 0 01-1 1A16 16 0 014 5a1 1 0 011-1z"/></svg> ' + esc(fmtDate(l.callback_at)) + '</span>';
		}
		var tel = l.phone ? '<a class="ecrm-leadtel" href="tel:' + esc(l.phone) + '">' + esc(l.phone) + '</a>' : '';
		var conv = (l.stage === 'won' && l.contract_id) ?
			'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-lopen="' + l.contract_id + '">Άνοιγμα σύμβασης</button>' :
			'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-lconv="' + l.id + '"><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg> Μετατροπή σε σύμβαση</button>';
		return '<div class="ecrm-leadcard ecrm-stage-' + esc(l.stage) + '">' +
			'<div class="ecrm-leadcard__top"><div><strong>' + esc(l.name) + '</strong> ' + (l.energy_type ? '<span class="ecrm-muted">· ' + esc(energyMap[l.energy_type] || l.energy_type) + '</span>' : '') +
				'<div class="ecrm-leadmeta">' + tel + (l.source_label ? ' <span class="ecrm-muted">· ' + esc(l.source_label) + '</span>' : '') + '</div></div>' +
				'<span class="ecrm-leadstage">' + esc(l.stage_label) + '</span></div>' +
			(l.interest ? '<div class="ecrm-leadint">' + esc(l.interest) + '</div>' : '') +
			'<div class="ecrm-leadcard__bar">' + cb +
				'<span class="ecrm-leadcard__actions">' + conv +
					'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-ledit="' + l.id + '">Επεξεργασία</button>' +
					'<button type="button" class="ecrm-iconbtn" data-ldel="' + l.id + '" title="Διαγραφή"><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V5a1 1 0 011-1h4a1 1 0 011 1v2M6 7l1 13a1 1 0 001 1h8a1 1 0 001-1l1-13"/></svg></button>' +
				'</span></div>' +
		'</div>';
	}).join('');
	if (!(d.leads || []).length) cards = '<div class="ecrm-card"><div class="ecrm-empty">Δεν υπάρχουν leads' + (leadsState.stage ? ' σε αυτό το στάδιο' : '') + '.</div></div>';

	view.innerHTML =
		'<header class="ecrm-head ecrm-head--row"><div><div class="ecrm-eyebrow">Πριν τη σύμβαση</div><h2 class="ecrm-title">Leads</h2>' +
		'<p class="ecrm-sub">Υποψήφιοι πελάτες & επανακλήσεις</p></div>' +
		'<button type="button" class="ecrm-btn ecrm-btn--primary" data-lnew><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg> Νέο Lead</button></header>' +
		'<div class="ecrm-leadfilters"><div class="ecrm-search"><input type="search" class="ecrm-input" placeholder="Αναζήτηση ονόματος, τηλεφώνου, ενδιαφέροντος…" value="' + esc(leadsState.q) + '" data-lq></div></div>' +
		'<div class="ecrm-kbfilter ecrm-leadstages">' + sChips + '</div>' +
		form +
		'<div class="ecrm-leadlist">' + cards + '</div>';

	// wiring
	view.querySelector('[data-lnew]').addEventListener('click', function () { leadsState.editing = {}; leadsState.showForm = true; renderLeads(view, d); });
	var lq = view.querySelector('[data-lq]');
	lq.addEventListener('input', function () { leadsState.q = this.value; clearTimeout(leadsT); leadsT = setTimeout(loadLeads, 300); });
	if (leadsState.q) { lq.focus(); lq.setSelectionRange(lq.value.length, lq.value.length); }
	view.querySelectorAll('[data-lstage]').forEach(function (b) { b.addEventListener('click', function () { leadsState.stage = this.getAttribute('data-lstage'); loadLeads(); }); });

	if (leadsState.showForm) {
		view.querySelector('[data-lcancel]').addEventListener('click', function () { leadsState.showForm = false; leadsState.editing = null; renderLeads(view, d); });
		view.querySelector('[data-lsave]').addEventListener('click', function () {
			var body = {};
			view.querySelectorAll('[data-lf]').forEach(function (el) { body[el.getAttribute('data-lf')] = el.value; });
			if (!body.name || !body.name.trim()) { toast('Το όνομα είναι υποχρεωτικό.', false); return; }
			var url = api('/leads') + (leadsState.editing && leadsState.editing.id ? '/' + leadsState.editing.id : '');
			fetch(url, { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H()), body: JSON.stringify(body) })
				.then(function (r) { return r.json(); })
				.then(function (res) { if (res && res.ok) { toast('Αποθηκεύτηκε.'); leadsState.showForm = false; leadsState.editing = null; loadLeads(); } else { toast((res && res.error) || 'Αποτυχία.', false); } })
				.catch(function () { toast('Σφάλμα δικτύου.', false); });
		});
	}

	view.querySelectorAll('[data-ledit]').forEach(function (b) {
		b.addEventListener('click', function () {
			var id = +this.getAttribute('data-ledit');
			var lead = (d.leads || []).filter(function (x) { return x.id === id; })[0];
			if (lead) { leadsState.editing = lead; leadsState.showForm = true; renderLeads(view, d); window.scrollTo({ top: 0, behavior: 'smooth' }); }
		});
	});
	view.querySelectorAll('[data-ldel]').forEach(function (b) {
		b.addEventListener('click', function () {
			if (!confirm('Διαγραφή lead;')) return;
			fetch(api('/leads/' + this.getAttribute('data-ldel')), { method: 'DELETE', headers: H() })
				.then(function (r) { return r.json(); }).then(function (res) { if (res && res.ok) loadLeads(); });
		});
	});
	view.querySelectorAll('[data-lconv]').forEach(function (b) {
		b.addEventListener('click', function () {
			if (!confirm('Δημιουργία πρόχειρης σύμβασης από αυτό το lead;')) return;
			var bb = this; bb.disabled = true;
			fetch(api('/leads/' + this.getAttribute('data-lconv') + '/convert'), { method: 'POST', headers: H() })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (res && res.ok && res.contract_id) { toast('Δημιουργήθηκε πρόχειρη σύμβαση.'); go('contracts'); setTimeout(function () { openDetail(res.contract_id); }, 60); }
					else { bb.disabled = false; toast((res && res.error) || 'Αποτυχία.', false); }
				})
				.catch(function () { bb.disabled = false; toast('Σφάλμα δικτύου.', false); });
		});
	});
	view.querySelectorAll('[data-lopen]').forEach(function (b) {
		b.addEventListener('click', function () { var id = +this.getAttribute('data-lopen'); go('contracts'); setTimeout(function () { openDetail(id); }, 60); });
	});
}
