/* Energy CRM — dashboard: η πρώτη οθόνη, και τι χρειάζεται εσένα σήμερα.
 *
 * Ξαναγράφτηκε 2026-08-18 σε ΕΝΑ ηρωικό νούμερο + level· ξαναγράφτηκε ΠΑΛΙ
 * 2026-08-25, τα τέσσερα πλακίδια ήρθαν πίσω — απόφαση ιδιοκτήτη, ευθυγράμμιση
 * με το `docs/UI-UX-KIT.html` A1, μετά από σύγκριση δίπλα-δίπλα
 * (`docs/UI-DASHBOARD-VS-KIT.html`). Η τάση (γράφημα 12 μηνών) και το
 * ανά-πάροχο ραβδόγραμμα ΔΕΝ έφυγαν — ρητή απόφαση να μείνουν κάτω από τα
 * πλακίδια, το kit δεν τα έχει καθόλου. Το level (Bronze/Silver) επίσης
 * ΔΕΝ έφυγε — ο κάρτα-ήρωας που το κουβαλούσε έφυγε, οπότε πήρε δική του
 * μικρή κάρτα αμέσως μετά τα πλακίδια, αντί να χαθεί σιωπηλά.
 *
 * Οι μορφές δεν διαλέχτηκαν στο μάτι:
 *   · Γραμμή και όχι στήλες για τους 12 μήνες: μία σειρά είναι τάση.
 *   · ΕΝΑ χρώμα για όλες τις ράβδους παρόχων — είναι μέγεθος, όχι ταυτότητα,
 *     και χρώμα ανά πάροχο θα ξόδευε το μοναδικό ελεύθερο κανάλι για
 *     πληροφορία που το μήκος ήδη δείχνει.
 *   · Οι ράβδοι είναι --p500 και η γραμμή --p600, ΟΧΙ το accent: το #16c217
 *     δίνει 2,33:1 σε λευκό, κάτω από το 3:1 που θέλουν τα γραφικά.
 */

import { api, can, esc, fetch, H, toast, viewEl } from '@energy-crm/util';
import { minutesAgo, timeAgo } from '@energy-crm/format';
import { openDetail, openPartner, openStoreList, go } from '@energy-crm/navigate';
import { openDialog } from '@energy-crm/dialog';

var MONTHS = ['Ιαν', 'Φεβ', 'Μαρ', 'Απρ', 'Μάι', 'Ιουν', 'Ιουλ', 'Αυγ', 'Σεπ', 'Οκτ', 'Νοε', 'Δεκ'];
var MONTHS_LONG_NOM = ['Ιανουάριος', 'Φεβρουάριος', 'Μάρτιος', 'Απρίλιος', 'Μάιος', 'Ιούνιος', 'Ιούλιος',
	'Αύγουστος', 'Σεπτέμβριος', 'Οκτώβριος', 'Νοέμβριος', 'Δεκέμβριος'];
var MONTHS_LONG = ['Ιανουαρίου', 'Φεβρουαρίου', 'Μαρτίου', 'Απριλίου', 'Μαΐου', 'Ιουνίου', 'Ιουλίου',
	'Αυγούστου', 'Σεπτεμβρίου', 'Οκτωβρίου', 'Νοεμβρίου', 'Δεκεμβρίου'];

/* (343) «Εγώ | Κατάστημα»: η δεύτερη καρτέλα μόνο για όποιον έχει ομάδα.
 * Η επιλογή μένει στον browser -- απλή ευκολία, τίποτα από τα δεδομένα. */
var STORE_KEY = 'ecrm-home-tab';

function hasStore() {
	return can('ecrm_manage_team');
}

function storeTab() {
	if (!hasStore()) return false;
	try { return localStorage.getItem(STORE_KEY) === 'store'; } catch (e) { return false; }
}

function setStoreTab(on) {
	try { localStorage.setItem(STORE_KEY, on ? 'store' : 'me'); } catch (e) { /* ιδιωτική περιήγηση */ }
}

function tabsHTML(store) {
	if (!hasStore()) return '';
	return '<div class="ecrm-scope ecrm-hometabs">' +
		'<button type="button" class="ecrm-scope__b' + (store ? '' : ' is-on') + '" data-hometab="me">Εγώ</button>' +
		'<button type="button" class="ecrm-scope__b' + (store ? ' is-on' : '') + '" data-hometab="store">Κατάστημα</button></div>';
}

function wireTabs(view) {
	view.querySelectorAll('[data-hometab]').forEach(function (b) {
		b.addEventListener('click', function () {
			setStoreTab(b.getAttribute('data-hometab') === 'store');
			storeMonth = '';
			loadDashboard();
		});
	});
}

export function loadDashboard() {
	var view = viewEl('dashboard');
	if (storeTab()) { loadStore(view); return; }
	fetch(api('/dashboard'), { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) { view.innerHTML = dashboardHTML(d); wire(view, d); wireTabs(view); })
		.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα φόρτωσης dashboard.</div></div>'; });
}

/* (345) Ποιος μήνας φαίνεται στο «Κατάστημα»: '' = τώρα, αλλιώς «YYYY-MM». */
var storeMonth = '';
var storeMonths = [];

/* (349) Από την ειδοποίηση της μηνιαίας αναφοράς (`#store/2026-10`): ανοίγει
 * το «Κατάστημα» σε εκείνον τον μήνα. Δεν φορτώνει· το go('dashboard') που
 * ακολουθεί φορτώνει. */
export function openStoreMonth(month) {
	if (!hasStore()) return;
	setStoreTab(true);
	storeMonth = /^\d{4}-\d{2}$/.test(month || '') ? month : '';
}

function loadStore(view) {
	// Μήνας χωρίς τη λίστα μηνών (ήρθε από ειδοποίηση): πρώτα η λίστα, για
	// να υπάρχει το «Τώρα» και οι υπόλοιποι μήνες.
	if (storeMonth && !storeMonths.length) {
		fetch(api('/store'), { headers: H() })
			.then(function (r) { return r.json(); })
			.then(function (d) { storeMonths = (d && d.months) || []; loadStoreMonth(view); })
			.catch(function () { loadStoreMonth(view); });
		return;
	}
	if (storeMonth) { loadStoreMonth(view); return; }
	fetch(api('/store'), { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (!d || !d.ok) { throw new Error('store'); }
			storeMonths = d.months || [];
			view.innerHTML = storeHTML(d);
			wireStore(view);
			wireTabs(view);
		})
		.catch(function () {
			view.innerHTML = tabsHTML(true) + '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα φόρτωσης.</div></div>';
			wireTabs(view);
		});
}

/* «+2 από χθες», «+12% από ίδιο διάστημα Σεπτεμβρίου». */
function delta(n, unit) {
	if (n === null || n === undefined || isNaN(n)) return '';
	var cls = n > 0 ? ' is-up' : (n < 0 ? ' is-down' : '');
	return '<span class="ecrm-storechg' + cls + '">' + (n > 0 ? '+' : '') + Number(n) + (unit === '%' ? '%' : '') + '</span>';
}

function storeTilesHTML(t) {
	var prev = MONTHS_LONG[(new Date().getMonth() + 11) % 12];
	var today = Number(t.today) || 0;
	var late = Number(t.awaiting_late) || 0;
	var change = t.month_change === null || t.month_change === undefined ? null : Number(t.month_change);
	var tiles = [
		{ label: 'Αιτήσεις σήμερα', v: today, delta: today - (Number(t.yesterday) || 0), unit: '', text: ' από χθες' },
		{
			label: 'Αιτήσεις μήνα',
			v: Number(t.month) || 0,
			delta: change, unit: '%',
			text: (change === null ? '' : ' από ίδιο διάστημα ' + prev) +
				(t.target ? (change === null ? '' : ' · ') + 'στόχος ' + t.target + ' · ' + t.of_target + '%' : '')
		},
		{
			label: 'Περιμένουν υπογραφή',
			v: Number(t.awaiting) || 0,
			delta: null, text: late > 0 ? late + ' πάνω από 2 μέρες' : '',
			warn: late > 0
		},
		{
			label: 'Απορρίψεις 30 ημ.',
			v: Number(t.lost) || 0,
			delta: null, text: t.top_reason ? 'κυρίως: ' + t.top_reason : ''
		}
	];

	return '<div class="ecrm-kpis ecrm-kpis--4">' + tiles.map(function (x) {
		var hasFoot = x.delta !== null || x.text !== '';
		return '<div class="ecrm-kpi"><div class="ecrm-kpi__k">' + esc(x.label) + '</div>' +
			'<div class="ecrm-kpi__v">' + x.v + '</div>' +
			(hasFoot
				? '<div class="ecrm-kpi__f' + (x.warn ? ' is-warn' : '') + '">' + delta(x.delta, x.unit) + esc(x.text) + '</div>'
				: '') +
			'</div>';
	}).join('') + '</div>';
}

var TODO = {
	signatures: { label: 'Υπογραφές που περιμένουν πάνω από 2 μέρες', tone: 'bad' },
	docs: { label: 'Λείπουν δικαιολογητικά', tone: 'warn' },
	stuck: { label: 'Κολλημένες αιτήσεις', tone: 'warn' },
	leads: { label: 'Ραντεβού επανάκλησης για σήμερα ή περασμένα', tone: 'info' },
	expiring: { label: 'Λήγουν τις επόμενες 30 μέρες', tone: 'info' }
};

function todoHTML(list) {
	var rows = (list || []).filter(function (x) { return x.count > 0 && TODO[x.key]; });
	if (!rows.length) {
		return '<div class="ecrm-card"><div class="ecrm-step">Θέλει κίνηση σήμερα</div>' +
			'<div class="ecrm-empty">Τίποτα δεν περιμένει την ομάδα αυτή τη στιγμή.</div></div>';
	}
	return '<div class="ecrm-card"><div class="ecrm-step">Θέλει κίνηση σήμερα</div>' + rows.map(function (x) {
		var who = (x.split || []).slice(0, 4).map(function (s) { return esc(s.name) + ' ' + s.count; }).join(' · ');
		return '<div class="ecrm-storedo">' +
			'<span class="ecrm-storedo__n is-' + TODO[x.key].tone + '">' + x.count + '</span>' +
			'<span class="ecrm-storedo__m"><b>' + esc(TODO[x.key].label) + '</b><small>' + who + '</small></span>' +
			'<button type="button" class="ecrm-storedo__a" data-store-go="' + esc(x.key) + '">Δες</button></div>';
	}).join('') + '</div>';
}

function membersHTML(list) {
	var rows = (list || []).map(function (m) {
		var dot = '<span class="ecrm-tldot' + (m.online ? ' is-on' : '') + '" title="' +
			(m.online ? 'Ενεργός τελευταίο 30λεπτο' : 'Ανενεργός') + '"></span>';
		return '<tr class="ecrm-rowlink" data-store-member="' + esc(m.id) + '">' +
			'<td>' + dot + '<strong>' + esc(m.name) + '</strong>' + (m.is_self ? ' <span class="ecrm-muted">(εσύ)</span>' : '') + '</td>' +
			'<td class="ecrm-tlnum">' + m.today + '</td>' +
			'<td>' + targetCell(m.month, m.target, m.behind) + '</td>' +
			'<td class="ecrm-tlnum ecrm-col-sec">' + esc(m.activated) + '</td>' +
			'<td class="ecrm-tlnum ecrm-col-sec">' + esc(m.awaiting) + '</td>' +
			'<td class="ecrm-tlnum">' + (m.lost >= 3 ? '<span class="ecrm-storebad">' + esc(m.lost) + '</span>' : esc(m.lost)) + '</td>' +
			'<td class="ecrm-muted ecrm-col-sec">' + (m.last ? esc(timeAgo(m.last)) : '—') + '</td></tr>';
	}).join('');
	if (!rows) rows = '<tr><td colspan="7" class="ecrm-empty">Δεν υπάρχουν μέλη ομάδας.</td></tr>';

	return '<div class="ecrm-card"><div class="ecrm-step ecrm-step--row"><span>Πωλητές</span>' +
		'<button type="button" class="ecrm-btn ecrm-btn--sm" data-store-targets>Στόχοι μήνα</button></div>' +
		'<div class="ecrm-tablewrap"><table class="ecrm-table"><thead><tr>' +
		'<th>Πωλητής</th><th class="ecrm-tlnum">Σήμερα</th><th class="ecrm-tgtcol">Μήνας / στόχος</th>' +
		'<th class="ecrm-tlnum ecrm-col-sec">Ενεργές μήνα</th><th class="ecrm-tlnum ecrm-col-sec">Περ. υπογρ.</th>' +
		'<th class="ecrm-tlnum">Απορρ. 30 ημ.</th><th class="ecrm-col-sec">Τελευταία κίνηση</th>' +
		'</tr></thead><tbody>' + rows + '</tbody></table></div></div>';
}

function storeHTML(d) {
	return storeHeadHTML() +
		storeTilesHTML(d.tiles || {}) +
		todoHTML(d.todo) +
		membersHTML(d.members);
}

function storeHeadHTML() {
	return '<header class="ecrm-head ecrm-head--row"><div><div class="ecrm-eyebrow">' +
		new Date().toLocaleDateString('el-GR', { weekday: 'long', day: 'numeric', month: 'long' }) + '</div>' +
		'<h2 class="ecrm-title">Κατάστημα</h2></div>' + tabsHTML(true) + '</header>' +
		monthsHTML();
}

/* (345) «Τώρα · Οκτώβριος · Σεπτέμβριος · Αύγουστος», και οι παλαιότεροι σε λίστα. */
function monthsHTML() {
	if (storeMonths.length < 2) return '';
	var past = storeMonths.slice(1);
	var shown = past.slice(0, 3);
	var older = past.slice(3);
	var seg = '<div class="ecrm-scope ecrm-storemonths">' +
		'<button type="button" class="ecrm-scope__b' + (storeMonth ? '' : ' is-on') + '" data-store-month="">Τώρα</button>' +
		shown.map(function (m) {
			return '<button type="button" class="ecrm-scope__b' + (storeMonth === m.month ? ' is-on' : '') + '" data-store-month="' +
				esc(m.month) + '">' + esc(m.name) + '</button>';
		}).join('') + '</div>';
	var sel = older.length
		? '<select class="ecrm-input ecrm-storemonths__older" data-store-older><option value="">Παλαιότερος μήνας…</option>' +
			older.map(function (m) {
				return '<option value="' + esc(m.month) + '"' + (storeMonth === m.month ? ' selected' : '') + '>' + esc(m.label) + '</option>';
			}).join('') + '</select>'
		: '';
	return '<div class="ecrm-storemonthbar">' + seg + sel + '</div>';
}

/* «31/40» με μπάρα· πορτοκαλί όταν είναι πίσω από τον ρυθμό του μήνα. */
function targetCell(done, target, behind) {
	done = Number(done) || 0;
	if (!target) return '<span class="ecrm-tgt"><b>' + done + '</b><span class="ecrm-muted"> · χωρίς στόχο</span></span>';
	var pct = Math.round(done * 100 / Number(target));
	return '<span class="ecrm-tgt' + (behind ? ' is-behind' : '') + '"><b>' + done + '</b>/' + Number(target) +
		'<span class="ecrm-tgt__track"><span class="ecrm-tgt__fill" style="width:' + Math.min(100, pct) + '%"></span></span>' +
		'<span class="ecrm-muted">' + pct + '%</span></span>';
}

function wireMonths(view) {
	view.querySelectorAll('[data-store-month]').forEach(function (b) {
		b.addEventListener('click', function () { storeMonth = b.getAttribute('data-store-month') || ''; loadStore(view); });
	});
	var older = view.querySelector('[data-store-older]');
	if (older) older.addEventListener('change', function () { if (older.value) { storeMonth = older.value; loadStore(view); } });
}

function loadStoreMonth(view) {
	fetch(api('/store/month') + '?month=' + encodeURIComponent(storeMonth), { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (!d || !d.ok || !d.report) { throw new Error('month'); }
			view.innerHTML = storeHeadHTML() + reportHTML(d.report);
			wireMonths(view);
			wireTabs(view);
			var print = view.querySelector('[data-store-print]');
			if (print) print.addEventListener('click', function () {
				if (!openPrintable(d.report)) toast('Ο browser μπλόκαρε το νέο παράθυρο. Επίτρεψέ το και ξαναπάτα.', false);
			});
		})
		.catch(function () {
			storeMonth = '';
			view.innerHTML = tabsHTML(true) + '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα φόρτωσης.</div></div>';
			wireTabs(view);
		});
}

function reportTilesHTML(r) {
	var t = r.tiles || {};
	var change = t.change === null || t.change === undefined ? null : Number(t.change);
	var actDelta = (Number(t.activated) || 0) - (Number(t.activated_before) || 0);
	var tiles = [
		{
			label: 'Αιτήσεις', v: Number(t.applications) || 0, delta: change, unit: '%',
			text: (change === null ? '' : ' από ' + r.previous) + (t.target ? (change === null ? '' : ' · ') + 'στόχος ' + t.target + ' (' + t.of_target + '%)' : '')
		},
		{ label: 'Έγιναν ενεργές', v: Number(t.activated) || 0, delta: actDelta, unit: '', text: ' από ' + r.previous },
		{
			label: 'Απορρίψεις / ακυρώσεις', v: Number(t.lost) || 0, delta: null,
			text: t.top_reason ? 'κυρίως: ' + t.top_reason + ' (' + t.top_reason_count + ')' : ''
		},
		{
			label: 'Υποψήφιοι', v: Number(t.leads) || 0, delta: null,
			text: (Number(t.leads) || 0) > 0
				? 'έγιναν αίτηση: ' + t.leads_converted + ' (' + Math.round(t.leads_converted * 100 / t.leads) + '%)' + (t.leads_qr ? ' · από QR code: ' + t.leads_qr : '')
				: ''
		}
	];
	return '<div class="ecrm-kpis ecrm-kpis--4">' + tiles.map(function (x) {
		var hasFoot = x.delta !== null || x.text !== '';
		return '<div class="ecrm-kpi"><div class="ecrm-kpi__k">' + esc(x.label) + '</div>' +
			'<div class="ecrm-kpi__v">' + x.v + '</div>' +
			(hasFoot ? '<div class="ecrm-kpi__f">' + delta(x.delta, x.unit) + esc(x.text) + '</div>' : '') +
			'</div>';
	}).join('') + '</div>';
}

function reportMembersRows(list) {
	return (list || []).map(function (m) {
		var apps = m.target
			? '<span class="' + (m.applications >= m.target ? 'ecrm-storeok' : 'ecrm-storewarn') + '">' + esc(m.applications) + '/' + esc(m.target) + '</span>'
			: esc(m.applications);
		return '<tr><td>' + esc(m.name) + '</td><td class="ecrm-tlnum">' + apps + '</td>' +
			'<td class="ecrm-tlnum">' + esc(m.activated) + '</td><td class="ecrm-tlnum">' + esc(m.lost) + '</td></tr>';
	}).join('') || '<tr><td colspan="4" class="ecrm-empty">Καμία κίνηση αυτόν τον μήνα.</td></tr>';
}

function reportProvidersRows(list) {
	return (list || []).map(function (p) {
		return '<tr><td>' + esc(p.name) + '</td><td class="ecrm-tlnum">' + esc(p.applications) + '</td><td class="ecrm-tlnum">' + esc(p.activated) + '</td></tr>';
	}).join('') || '<tr><td colspan="3" class="ecrm-empty">—</td></tr>';
}

function reportHTML(r) {
	return '<div class="ecrm-card"><div class="ecrm-step ecrm-step--row"><span>' + esc(r.label) + ' · σύνοψη</span>' +
		'<button type="button" class="ecrm-btn ecrm-btn--sm" data-store-print>Εκτύπωση / PDF</button></div>' +
		reportTilesHTML(r) + '</div>' +
		'<div class="ecrm-card"><div class="ecrm-step">Πωλητές</div><div class="ecrm-tablewrap"><table class="ecrm-table"><thead><tr>' +
		'<th>Πωλητής</th><th class="ecrm-tlnum">Αιτήσεις / στόχος</th><th class="ecrm-tlnum">Ενεργές</th><th class="ecrm-tlnum">Απορρ.</th>' +
		'</tr></thead><tbody>' + reportMembersRows(r.members) + '</tbody></table></div></div>' +
		'<div class="ecrm-card"><div class="ecrm-step">Πάροχοι</div><div class="ecrm-tablewrap"><table class="ecrm-table"><thead><tr>' +
		'<th>Πάροχος</th><th class="ecrm-tlnum">Αιτήσεις</th><th class="ecrm-tlnum">Ενεργές</th>' +
		'</tr></thead><tbody>' + reportProvidersRows(r.providers) + '</tbody></table></div></div>';
}

/* Η ίδια αναφορά σε νέο παράθυρο, για εκτύπωση ή «Αποθήκευση ως PDF». */
function openPrintable(r) {
	var t = r.tiles || {};
	var css = 'body{font:13px/1.5 Manrope,-apple-system,"Segoe UI",Roboto,sans-serif;color:#1d1d1b;margin:24px}' +
		'h1{font-size:20px;margin:0 0 4px}.mt{color:#666}table{width:100%;border-collapse:collapse;margin-top:14px}' +
		'th,td{border-top:1px solid #ddd;padding:6px;text-align:left}th{color:#666;font-size:12px}td.r,th.r{text-align:right}' +
		'.tiles{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:14px}.t{border:1px solid #ddd;border-radius:8px;padding:8px}' +
		'.t b{display:block;font-size:20px}.bar{margin-bottom:12px}@media print{.bar{display:none}}';
	var tile = function (k, v, s) { return '<div class="t">' + esc(k) + '<b>' + esc(v) + '</b><span class="mt">' + esc(s) + '</span></div>'; };
	var members = (r.members || []).map(function (m) {
		return '<tr><td>' + esc(m.name) + '</td><td class="r">' + esc(m.applications) + (m.target ? '/' + esc(m.target) : '') +
			'</td><td class="r">' + esc(m.activated) + '</td><td class="r">' + esc(m.lost) + '</td></tr>';
	}).join('');
	var providers = (r.providers || []).map(function (p) {
		return '<tr><td>' + esc(p.name) + '</td><td class="r">' + esc(p.applications) + '</td><td class="r">' + esc(p.activated) + '</td></tr>';
	}).join('');
	var html = '<!doctype html><html lang="el"><head><meta charset="utf-8"><title>' + esc(r.label) + '</title><style>' + css + '</style></head><body>' +
		'<div class="bar"><button type="button" onclick="window.print()">Εκτύπωση / PDF</button></div>' +
		'<h1>' + esc(r.label) + '</h1><div class="mt">Μηνιαία αναφορά καταστήματος</div>' +
		'<div class="tiles">' +
		tile('Αιτήσεις', t.applications, t.target ? 'στόχος ' + t.target + ' (' + t.of_target + '%)' : '') +
		tile('Έγιναν ενεργές', t.activated, '') +
		tile('Απορρίψεις / ακυρώσεις', t.lost, t.top_reason ? 'κυρίως: ' + t.top_reason : '') +
		tile('Υποψήφιοι', t.leads, 'έγιναν αίτηση: ' + t.leads_converted) +
		'</div>' +
		'<table><tr><th>Πωλητής</th><th class="r">Αιτήσεις</th><th class="r">Ενεργές</th><th class="r">Απορρ.</th></tr>' + members + '</table>' +
		'<table><tr><th>Πάροχος</th><th class="r">Αιτήσεις</th><th class="r">Ενεργές</th></tr>' + providers + '</table>' +
		'</body></html>';
	var page = URL.createObjectURL(new Blob([html], { type: 'text/html' }));
	var win = window.open(page, '_blank');
	setTimeout(function () { URL.revokeObjectURL(page); }, 60000);
	return !!win;
}

/* (345) «Στόχοι μήνα»: ο τρέχων ή ο επόμενος μήνας, ένα πεδίο ανά πωλητή. */
function openTargets(view, month) {
	fetch(api('/store/targets') + (month ? '?month=' + encodeURIComponent(month) : ''), { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (!d || !d.ok) { toast((d && d.error) || 'Σφάλμα.', false); return; }
			var next = storeMonths.length ? storeMonths[0].month : '';
			var nextMonth = nextOf(next);
			var switcher = next
				? '<div class="ecrm-scope ecrm-tgtdlg__months">' +
					'<button type="button" class="ecrm-scope__b' + (d.month === next ? ' is-on' : '') + '" data-tgt-month="' + esc(next) + '">' + esc(monthName(next)) + '</button>' +
					'<button type="button" class="ecrm-scope__b' + (d.month === nextMonth ? ' is-on' : '') + '" data-tgt-month="' + esc(nextMonth) + '">' + esc(monthName(nextMonth)) + '</button></div>'
				: '';
			var rows = (d.rows || []).map(function (r) {
				return '<tr><td>' + esc(r.name) + '</td><td class="ecrm-tlnum">' + esc(r.previous) + '</td>' +
					'<td class="ecrm-tlnum"><input type="number" min="0" max="1000" inputmode="numeric" class="ecrm-input ecrm-tgtdlg__in" data-tgt-user="' + esc(r.id) + '"' +
					' data-tgt-prev="' + (r.previous_target === null ? '' : esc(r.previous_target)) + '"' +
					' value="' + (r.target === null ? '' : esc(r.target)) + '" placeholder="—"></td></tr>';
			}).join('');
			var dlg = openDialog({
				eyebrow: d.label,
				title: 'Στόχοι αιτήσεων',
				body: switcher +
					'<div class="ecrm-tablewrap"><table class="ecrm-table"><thead><tr><th>Πωλητής</th>' +
					'<th class="ecrm-tlnum">' + esc(d.previous) + ' (έκανε)</th><th class="ecrm-tlnum">Στόχος</th></tr></thead><tbody>' + rows + '</tbody></table></div>' +
					'<p class="ecrm-muted ecrm-tgtdlg__note">Κενό = χωρίς στόχο. Μετράνε οι αιτήσεις που στάλθηκαν, όχι τα πρόχειρα. ' +
					'<button type="button" class="ecrm-linkbtn" data-tgt-copy>Ίδιοι με τον ' + esc(d.previous_acc) + '</button></p>',
				confirm: 'Αποθήκευση',
				onConfirm: function (ov, close, btn) {
					var targets = {};
					ov.querySelectorAll('[data-tgt-user]').forEach(function (inp) {
						targets[inp.getAttribute('data-tgt-user')] = inp.value === '' ? null : Number(inp.value);
					});
					btn.disabled = true;
					fetch(api('/store/targets'), {
						method: 'POST',
						headers: Object.assign({ 'Content-Type': 'application/json' }, H()),
						body: JSON.stringify({ month: d.month, targets: targets })
					})
						.then(function (r) { return r.json(); })
						.then(function (res) {
							btn.disabled = false;
							if (!res || !res.ok) { toast((res && res.error) || 'Αποτυχία.', false); return; }
							toast('Οι στόχοι αποθηκεύτηκαν.');
							close();
							loadStore(view);
						})
						.catch(function () { btn.disabled = false; toast('Σφάλμα δικτύου.', false); });
				}
			});
			var copy = dlg.el.querySelector('[data-tgt-copy]');
			if (copy) copy.addEventListener('click', function () {
				dlg.el.querySelectorAll('[data-tgt-user]').forEach(function (inp) { inp.value = inp.getAttribute('data-tgt-prev') || ''; });
			});
			dlg.el.querySelectorAll('[data-tgt-month]').forEach(function (b) {
				b.addEventListener('click', function () {
					if (b.getAttribute('data-tgt-month') === d.month) return;
					dlg.close();
					openTargets(view, b.getAttribute('data-tgt-month'));
				});
			});
		})
		.catch(function () { toast('Σφάλμα δικτύου.', false); });
}

function nextOf(month) {
	var p = (month || '').split('-');
	if (p.length !== 2) return '';
	var y = Number(p[0]), m = Number(p[1]) + 1;
	if (m > 12) { m = 1; y++; }
	return y + '-' + (m < 10 ? '0' : '') + m;
}

function monthName(month) {
	var m = Number((month || '').split('-')[1]);
	return MONTHS_LONG_NOM[m - 1] || month;
}

function wireStore(view) {
	wireMonths(view);
	var tg = view.querySelector('[data-store-targets]');
	if (tg) tg.addEventListener('click', function () { openTargets(view, ''); });
	view.querySelectorAll('[data-store-go]').forEach(function (b) {
		b.addEventListener('click', function () { openStoreList(b.getAttribute('data-store-go')); });
	});
	view.querySelectorAll('[data-store-member]').forEach(function (tr) {
		tr.addEventListener('click', function () { openPartner(Number(tr.getAttribute('data-store-member'))); });
	});
}

function greeting() {
	var h = new Date().getHours();
	return h < 5 ? 'Καλό ξημέρωμα' : h < 12 ? 'Καλημέρα' : h < 18 ? 'Καλησπέρα' : 'Καλό βράδυ';
}

/* €1.820 — σύμβολο ΜΠΡΟΣΤΑ και τελεία στις χιλιάδες, όπως το kit A1.
 *
 * Χωρίς λεπτά: το πλακίδιο δείχνει άθροισμα μήνα, όπου τα δύο δεκαδικά είναι
 * θόρυβος — και χωρίς `toLocaleString`, που αλλάζει μορφή ανάλογα με τη γλώσσα
 * του browser και θα έδινε «1,820» σε αγγλικό μηχάνημα. Ίδιος χειρισμός με το
 * `ecrm-view-commissions.js`, που έλυσε το ίδιο πρόβλημα με το ίδιο regex. */
function euro(n) {
	return '€' + Math.round(Number(n) || 0).toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}

/* Τα τέσσερα πλακίδια, ίδιο βάρος — δομή του kit A1, δεδομένα από
 * `DashboardRepository::tiles()`. Κάθε πλακίδιο μετράει διαφορετικό ΕΙΔΟΣ
 * πράγματος (απόθεμα / απόθεμα-με-προθεσμία / ροή ενός μήνα / εργασίες),
 * οπότε κάθε ένα έχει και δικό του υποκείμενο κείμενο αντί για μια γενική
 * σύγκριση που δεν θα σήμαινε το ίδιο και στα τέσσερα.
 *
 * Κλικαριστά μόνο όσα δείχνουν ΜΙΑ κατάσταση: «Ανοιχτές» και «Αναμονή
 * υπογραφής» καλύπτουν παραπάνω από μία (η δεύτερη δύο: pending_signature +
 * awaiting_signature) και η λίστα συμβάσεων φιλτράρει μόνο σε μία κατάσταση
 * τη φορά — ένα κλικ που δείχνει μισή αλήθεια είναι χειρότερο από κανένα
 * κλικ. «Κλεισμένες» είναι ΜΙΑ κατάσταση (active) και πάει κατευθείαν εκεί.
 * «Εργασίες μου» πάει στην ίδια την οθόνη εργασιών. */
function tilesHTML(t) {
	var closed = Number(t.closed_month) || 0;
	var commission = Number(t.closed_month_commission) || 0;
	var expiring = Number(t.expiring_today) || 0;
	var overdue = Number(t.tasks_overdue) || 0;

	var week = Number(t.open_this_week) || 0;

	var tiles = [
		{
			label: 'Ανοιχτές αιτήσεις',
			v: Number(t.open) || 0,
			foot: week > 0 ? '↑ ' + week + ' αυτή την εβδομάδα' : ''
		},
		{
			label: 'Αναμονή υπογραφής',
			v: Number(t.awaiting_signature) || 0,
			foot: expiring > 0 ? expiring + (expiring === 1 ? ' λήγει σήμερα' : ' λήγουν σήμερα') : '',
			warn: expiring > 0
		},
		{
			label: 'Κλεισμένες (μήνας)',
			v: closed,
			foot: commission > 0 ? '→ ' + euro(commission) + ' προμήθεια' : (closed === 0 ? 'καμία ακόμα' : ''),
			go: 'contracts', status: 'active'
		},
		{
			label: 'Εργασίες μου',
			v: Number(t.tasks_open) || 0,
			foot: overdue > 0 ? overdue + (overdue === 1 ? ' εκπρόθεσμη' : ' εκπρόθεσμες') : '',
			warn: overdue > 0,
			go: 'tasks'
		}
	];

	return '<div class="ecrm-kpis ecrm-kpis--4">' + tiles.map(function (x) {
		var clickable = !!x.go;
		var tag = clickable ? 'button' : 'div';
		var att = clickable
			? ' type="button" data-go="' + esc(x.go) + '"' + (x.status ? ' data-status="' + esc(x.status) + '"' : '')
			: '';

		return '<' + tag + ' class="ecrm-kpi' + (clickable ? ' is-clickable' : '') + '"' + att + '>' +
			'<div class="ecrm-kpi__k">' + esc(x.label) + '</div>' +
			'<div class="ecrm-kpi__v">' + x.v + '</div>' +
			(x.foot ? '<div class="ecrm-kpi__f' + (x.warn ? ' is-warn' : '') + '">' + esc(x.foot) + '</div>' : '') +
			'</' + tag + '>';
	}).join('') + '</div>';
}

/* Η κάρτα level, ξεχωριστή από τα πλακίδια: δεν μετράει «πόσα», μετράει «πόσο
 * κοντά». Έζησε μέσα στο παλιό hero· το hero έφυγε, αυτή έμεινε — απλώς μικρή
 * και μόνη της, αντί να χαθεί σιωπηλά μαζί με το ηρωικό νούμερο. */
/* (345) Ο στόχος αιτήσεων του μήνα, όταν του έχει μπει. */
function myTargetHTML(t) {
	if (!t || !t.target) return '';
	var done = Number(t.done) || 0, target = Number(t.target);
	var pct = Math.min(100, Math.round(done * 100 / target));
	return '<div class="ecrm-card"><div class="ecrm-tier"><span class="ecrm-tier__now">Στόχος μήνα</span>' +
		'<span class="ecrm-tier__next">' + done + ' από ' + target + ' αιτήσεις</span></div>' +
		'<div class="ecrm-meter"><div class="ecrm-meter__f" style="width:' + pct + '%"></div></div></div>';
}

function levelHTML(lvl) {
	if (!lvl || !lvl.current) { return ''; }

	var at  = Number(lvl.next_at) || 0;
	var month = at - (Number(lvl.remaining) || 0);
	var pct = at ? Math.min(100, Math.max(0, Math.round((month / at) * 100))) : 0;

	return '<div class="ecrm-card"><div class="ecrm-tier">' +
		'<span class="ecrm-tier__now">' + esc(lvl.current) + '</span>' +
		(lvl.next ? '<span class="ecrm-tier__next">' + (Number(lvl.remaining) || 0) + ' ακόμα για ' + esc(lvl.next) + '</span>' : '') +
		'</div><div class="ecrm-meter"><div class="ecrm-meter__f" style="width:' + pct + '%"></div></div>' +
		(at ? '<div class="ecrm-tier__scale"><span>0</span><span>' + esc(lvl.next || '') + ' ' + at + '</span></div>' : '') +
		'</div>';
}

/* Ποιο κουμπί ταιριάζει σε κάθε κατάσταση, και τι λέει ο τίτλος στο hover.
 *
 * ΠΡΟΣΟΧΗ στο τι ΔΕΝ επιστρέφει πια: μέχρι 25/08 έφτιαχνε ολόκληρη φράση
 * («περιμένει υπογραφή πελάτη — εδώ και 4 ημέρες») που γραφόταν στη δεύτερη
 * γραμμή. Έφυγε: η κατάσταση λέγεται ήδη από το badge δίπλα, και η φράση
 * απλώς την ξαναέλεγε με άλλα λόγια, σπρώχνοντας κάτω τον κωδικό και τον
 * πάροχο — τα ΜΟΝΑ δύο στοιχεία που δεν φαίνονται αλλού στη γραμμή.
 *
 * Η ηλικία δεν χάθηκε, μετακόμισε στο `title` της γραμμής: η σειρά της λίστας
 * ΕΙΝΑΙ η ηλικία (πιο στάσιμες πρώτες, `ORDER BY updated_at ASC`), οπότε το
 * νούμερο ήταν επανάληψη της θέσης — χρήσιμο στο hover, θόρυβος σε κάθε
 * γραμμή. */
function why(it) {
	var d = Number(it.days) || 0;
	var ago = d === 0 ? 'από σήμερα' : d === 1 ? 'από χθες' : 'εδώ και ' + d + ' ημέρες';

	if (it.status === 'draft') {
		return {
			act: 'Συνέχεια',
			tip: (it.blocked_no_afm ? 'Λείπει ΑΦΜ, δεν οριστικοποιείται' : 'Δεν οριστικοποιήθηκε') + ' — ' + ago
		};
	}
	if (it.status === 'awaiting_signature') {
		return { act: 'Υπενθύμιση', tip: 'Περιμένει υπογραφή πελάτη — ' + ago };
	}
	// (354) Καταχώρηση: έτοιμη, περιμένει να σταλεί για υπογραφή.
	if (it.status === 'registration') {
		return { act: 'Για υπογραφή', tip: 'Έτοιμη για αποστολή υπογραφής — ' + ago };
	}
	// Ό,τι απομένει είναι presale (και οι Εκκρεμότητα/Οφειλή).
	return { act: 'Δικαιολογητικά', tip: 'Λείπουν δικαιολογητικά — ' + ago };
}

/* Η γραμμή του kit A1, τρεις στήλες: ταυτότητα αριστερά, πινακίδα κατάστασης
 * στη μέση, ενέργεια δεξιά.
 *
 * Η ταυτότητα είναι ΔΥΟ γραμμές — όνομα πελάτη από πάνω, `#κωδικός · πάροχος`
 * από κάτω σε σβηστό — και όχι μία με τελείες, επειδή το όνομα είναι αυτό που
 * σαρώνει το μάτι· ο κωδικός είναι για μετά, όταν έχει ήδη βρει τη σωστή
 * γραμμή.
 *
 * Η μεσαία στήλη έχει ΣΤΑΘΕΡΟ πλάτος (`.ecrm-attn__s`) ώστε όλες οι πινακίδες
 * να αρχίζουν στο ίδιο x. Με `flex: 0 0 auto` κάθε μία ξεκινούσε όπου τελείωνε
 * το από πάνω της κείμενο — τέσσερις πινακίδες σε τέσσερα διαφορετικά σημεία
 * διαβάζονται ως τέσσερα άσχετα πράγματα, ενώ είναι μία στήλη.
 *
 * Τα χρώματα είναι του κώδικα, όχι του kit: ίδιες κλάσεις `.ecrm-badge--{status}`
 * με τη λίστα συμβάσεων και τη λεπτομέρεια, ώστε το ίδιο χρώμα να σημαίνει το
 * ίδιο πράγμα παντού. Το ίδιο και οι ετικέτες, από το `window.ECRM.statuses`:
 * το kit γράφει «Υπογραφή», η εφαρμογή «Αναμονή υπογραφής» — δύο ονόματα για
 * την ίδια κατάσταση στην ίδια οθόνη είναι χειρότερο από μια μακριά ετικέτα. */
function attentionHTML(list) {
	if (!list || !list.length) {
		return '<div class="ecrm-card"><div class="ecrm-step">Χρειάζεται ενέργεια</div>' +
			'<div class="ecrm-empty">Τίποτα δεν περιμένει εσένα αυτή τη στιγμή.</div></div>';
	}

	var statuses = (window.ECRM && ECRM.statuses) || {};

	return '<div class="ecrm-card"><div class="ecrm-step">Χρειάζεται ενέργεια</div>' +
		'<ul class="ecrm-attn">' + list.map(function (it) {
			var w = why(it);
			var meta = (it.code ? '#' + esc(it.code) : '') +
				(it.code && it.provider ? ' · ' : '') +
				(it.provider ? esc(it.provider) : '');

			return '<li data-attn="' + esc(it.id) + '" title="' + esc(w.tip) + '">' +
				'<span class="ecrm-attn__m"><b>' + esc(it.customer || '—') + '</b>' +
				'<small>' + (meta || '—') + '</small></span>' +
				'<span class="ecrm-attn__s"><span class="ecrm-badge ecrm-badge--' + esc(it.status) + '">' +
				esc(statuses[it.status] || it.status) + '</span></span>' +
				'<button type="button" class="ecrm-attn__a" data-attn-go="' + esc(it.id) + '">' + esc(w.act) + '</button></li>';
		}).join('') + '</ul></div>';
}

/* «Ελλείψεις & προθεσμίες» -- 31/08, δεύτερη κάρτα δίπλα στο «Χρειάζεται
 * ενέργεια», ΕΠΙΤΗΔΕΣ ξεχωριστή αντί να μπει στην ίδια λίστα.
 *
 * Το attentionHTML() από πάνω μένει actionable-only με τη σημερινή του
 * λογική (draft/pending/υπογραφή, ΧΩΡΙΣ routed) -- ρητή απόφαση ιδιοκτήτη να
 * μη το αγγίξουμε (βλ. DashboardAttentionTest). Αυτή εδώ η κάρτα είναι κάτι
 * διαφορετικό: τρεις πηγές (λείπον έγγραφο, ληγμένο έγγραφο, lead με
 * περασμένο ραντεβού) που ΔΕΝ είναι «σύμβαση σε εξέλιξη», αλλά χρειάζονται
 * δικό σου βήμα -- μαζεμένες εδώ ώστε να μη χαθούν σε μια οθόνη ο καθένας
 * ξεχωριστά. Πηγή: `attention_extra` στο payload του `/dashboard`
 * (`DashboardController`), πάνω σε `ECRM_Notifications::missing_docs_for()`
 * / `expired_docs_for()` / `overdue_leads_for()`.
 *
 * Ταξινόμηση κατά `age_days` φθίνουσα σε ΟΛΗ τη μαζεμένη λίστα -- το πιο
 * παλιό πράγμα πρώτο, ανεξάρτητα από ποια πηγή το έδωσε, γι' αυτό και το
 * `age_days` προστέθηκε στο `missing_docs_for()` (δεν το είχε πριν). */
function attentionExtraHTML(extra) {
	extra = extra || {};
	var rows = []
		.concat((extra.missing_docs || []).map(function (r) {
			return {
				kind: 'doc', kindLabel: 'Έγγραφο', goContract: r.contract_id,
				name: r.customer, meta: '#' + (r.code || '') + ' · λείπει ' + (r.missing || []).join(', '),
				badge: 'pending', badgeLabel: 'Λείπει', age: Number(r.age_days) || 0
			};
		}))
		.concat((extra.expired_docs || []).map(function (r) {
			return {
				kind: 'doc', kindLabel: 'Έγγραφο', goContract: r.contract_id,
				name: r.customer, meta: '#' + (r.code || '') + ' · έληξε ' + (r.label || r.kind),
				badge: 'cancelled', badgeLabel: 'Έληξε', age: Number(r.age_days) || 0
			};
		}))
		.concat((extra.overdue_leads || []).map(function (r) {
			return {
				kind: 'lead', kindLabel: 'Lead', goLead: r.id,
				name: r.name, meta: (r.phone || '') + ' · ραντεβού πέρασε',
				badge: 'cancelled', badgeLabel: 'Επανάκληση', age: Number(r.age_days) || 0
			};
		}));

	rows.sort(function (a, b) { return b.age - a.age; });

	if (!rows.length) {
		return '';
	}

	return '<div class="ecrm-card"><div class="ecrm-step">Ελλείψεις &amp; προθεσμίες</div>' +
		'<ul class="ecrm-attn">' + rows.map(function (it, i) {
			var goAttr = it.goContract ? ' data-attnx-contract="' + esc(it.goContract) + '"' : ' data-attnx-lead="' + esc(it.goLead) + '"';
			return '<li data-attnx-i="' + i + '">' +
				'<span class="ecrm-attnx__kind">' + esc(it.kindLabel) + '</span>' +
				'<span class="ecrm-attn__m"><b>' + esc(it.name || '—') + '</b><small>' + esc(it.meta || '—') + '</small></span>' +
				'<span class="ecrm-attn__s"><span class="ecrm-badge ecrm-badge--' + esc(it.badge) + '">' + esc(it.badgeLabel) + '</span></span>' +
				'<button type="button" class="ecrm-attn__a"' + goAttr + '>Άνοιγμα</button></li>';
		}).join('') + '</ul></div>';
}

/* Γραμμή, μία σειρά, χωρίς legend: ο τίτλος τη δηλώνει. Ετικέτα μόνο στο
 * τελευταίο σημείο — αριθμός σε κάθε σημείο είναι χάος και δεν διαβάζεται. */
function trendHTML(monthly) {
	var vals = (monthly || []).map(function (v) { return Number(v) || 0; });
	var upto = new Date().getMonth() + 1;
	vals = vals.slice(0, Math.max(2, upto));

	// Το SVG κρατά ΜΟΝΟ σχήματα. Τεντώνεται οριζόντια για να γεμίσει την κάρτα,
	// και κείμενο μέσα του θα παραμορφωνόταν μαζί — οι ετικέτες είναι HTML.
	var W = 560, HH = 168, PT = 10, PB = 10;
	var max = Math.max.apply(null, vals.concat([1]));
	var ih = HH - PT - PB;
	var x = function (i) { return vals.length < 2 ? W / 2 : i * W / (vals.length - 1); };
	var y = function (v) { return PT + ih - (v / max) * ih; };

	var s = '';
	var ticks = [0, Math.round(max / 2), max];
	ticks.forEach(function (t) {
		s += '<line x1="0" x2="' + W + '" y1="' + y(t) + '" y2="' + y(t) + '" class="ecrm-gridline" vector-effect="non-scaling-stroke"/>';
	});

	var d = vals.map(function (v, i) { return (i ? 'L' : 'M') + x(i) + ' ' + y(v); }).join(' ');
	s += '<path d="' + d + ' L' + x(vals.length - 1) + ' ' + (PT + ih) + ' L' + x(0) + ' ' + (PT + ih) + ' Z" class="ecrm-area"/>';
	s += '<path d="' + d + '" class="ecrm-line" vector-effect="non-scaling-stroke"/>';

	var li = vals.length - 1;
	s += '<circle cx="' + x(li) + '" cy="' + y(vals[li]) + '" r="4.5" class="ecrm-dot" vector-effect="non-scaling-stroke"/>';

	vals.forEach(function (v, i) {
		var w = vals.length < 2 ? W : W / (vals.length - 1);
		s += '<rect class="ecrm-hit" data-i="' + i + '" data-v="' + v + '" x="' + (x(i) - w / 2) + '" y="' + PT + '" width="' + w + '" height="' + ih + '"/>';
	});

	var yaxis = ticks.map(function (t) {
		return '<span style="top:' + (y(t) / HH * 100) + '%">' + t + '</span>';
	}).join('');

	var xaxis = vals.map(function (v, i) { return '<span>' + MONTHS[i] + '</span>'; }).join('');

	// Ο κατακόρυφος άξονας είναι 1:1 — το SVG έχει ύψος 168px και viewBox 168 —
	// οπότε το y του viewBox ΕΙΝΑΙ pixel. Το +20 είναι το padding-top της κάρτας.
	var endval = '<div class="ecrm-endval" style="left:100%;top:' + (y(vals[li]) + 20) + 'px">' + vals[li] + '</div>';

	var rows = vals.map(function (v, i) { return '<tr><td>' + MONTHS[i] + '</td><td class="ecrm-num">' + v + '</td></tr>'; }).join('');

	return '<div class="ecrm-card"><div class="ecrm-head--row ecrm-attnhead">' +
		'<span class="ecrm-step">Αιτήσεις ανά μήνα</span>' +
		'<button type="button" class="ecrm-tableview" data-tableview>Πίνακας</button></div>' +
		'<div class="ecrm-chartwrap" data-chartwrap><div class="ecrm-chart">' +
		'<div class="ecrm-yaxis">' + yaxis + '</div>' +
		'<svg class="ecrm-plot" viewBox="0 0 ' + W + ' ' + HH + '" preserveAspectRatio="none" role="img" aria-label="Αιτήσεις ανά μήνα">' + s + '</svg>' +
		endval + '<div class="ecrm-charttip" data-charttip></div></div>' +
		'<div class="ecrm-xaxis">' + xaxis + '</div></div>' +
		'<div class="ecrm-tablewrap"><table class="ecrm-charttbl" data-charttbl hidden><thead><tr><th>Μήνας</th><th class="ecrm-num">Αιτήσεις</th></tr></thead><tbody>' + rows + '</tbody></table></div></div>';
}

function providersHTML(list) {
	if (!list || !list.length) {
		return '<div class="ecrm-card"><div class="ecrm-step">Ανά πάροχο</div>' +
			'<div class="ecrm-empty">Δεν έχεις καμία αίτηση ακόμα. Πάτα «Νέα αίτηση» για να ξεκινήσεις.</div></div>';
	}
	var max = Math.max.apply(null, list.map(function (p) { return Number(p.c) || 0; }).concat([1]));

	return '<div class="ecrm-card"><div class="ecrm-head--row ecrm-attnhead">' +
		'<span class="ecrm-step">Ανά πάροχο</span><span class="ecrm-attnhead__n">αυτόν τον μήνα</span></div>' +
		'<div class="ecrm-pbars">' + list.map(function (p) {
			return '<div class="ecrm-pbar"><span class="ecrm-pbar__n">' + esc(p.name || '—') + '</span>' +
				'<span class="ecrm-pbar__t"><span class="ecrm-pbar__f" style="width:' + Math.round((Number(p.c) || 0) / max * 100) + '%"></span></span>' +
				'<span class="ecrm-pbar__v">' + (Number(p.c) || 0) + '</span></div>';
		}).join('') + '</div></div>';
}

/* Δύο γραμμές ανά γεγονός, όπως το kit A1: τι έγινε από πάνω, πότε και σε ποια
 * σύμβαση από κάτω σε σβηστό.
 *
 * Ήταν μία γραμμή τριών στηλών (κωδικός | μήνυμα | χρόνος) και έσπαγε άσχημα:
 * το μήνυμα είναι ελεύθερο κείμενο μεταβλητού μήκους, οπότε ο χρόνος δεξιά
 * χόρευε πάνω-κάτω και ο μονόχωρος κωδικός αριστερά τραβούσε το μάτι πρώτος —
 * ενώ είναι το λιγότερο ενδιαφέρον στοιχείο της γραμμής. */
function feedHTML(feed) {
	if (!feed || !feed.length) {
		return '<div class="ecrm-card"><div class="ecrm-step">Τελευταία δραστηριότητα</div>' +
			'<div class="ecrm-empty">Καμία πρόσφατη δραστηριότητα.</div></div>';
	}
	return '<div class="ecrm-card"><div class="ecrm-step">Τελευταία δραστηριότητα</div><ul class="ecrm-feed">' +
		feed.map(function (f) {
			return '<li><span class="ecrm-feed__t">' + esc(f.message || f.type) + '</span>' +
				'<span class="ecrm-feed__s">' + esc(timeAgo(f.created_at)) +
				(f.code ? ' · #' + esc(f.code) : '') + '</span></li>';
		}).join('') + '</ul></div>';
}

/* ═══ (353) Αρχική «Signature» ════════════════════════════════════════════
 * Μόνο όταν το κέλυφος έχει data-ui="signature" (διαχειριστής, σε δοκιμή).
 * Ίδια δεδομένα με την κλασική, κανένα νέο αίτημα, κανένας νέος αριθμός που
 * δεν υπάρχει ήδη στο /dashboard. Τα κουμπιά φέρουν τα ΙΔΙΑ data-* με την
 * κλασική, οπότε τα ανοίγει η ίδια wire(). docs/UI-REDESIGN-WOW.html.
 *
 * Τα δαχτυλίδια δείχνουν μόνο λόγους που υπάρχουν:
 *   1. Αιτήσεις μήνα προς τον στόχο (345). Χωρίς στόχο: προς τον προηγούμενο μήνα.
 *   2. Ενεργοποιήσεις μήνα προς τις αιτήσεις του μήνα.
 *   3. Πρόοδος προς το επόμενο επίπεδο, όταν υπάρχει επίπεδο.
 * Η «νησίδα» δείχνει το τελευταίο γεγονός της δραστηριότητας, μόνο αν έγινε
 * τα τελευταία 30 λεπτά. Αλλιώς δεν εμφανίζεται καθόλου. */
function isSignature() {
	var app = document.getElementById('ecrm-app');
	return !!app && app.getAttribute('data-ui') === 'signature';
}

var SIG_R = [88, 68, 48];

function sigRing(i, pct, cls) {
	var r = SIG_R[i];
	var c = 2 * Math.PI * r;
	var to = Math.round(c * Math.max(0, Math.min(100, pct)) / 100);

	return '<circle class="sig-ring__trk ' + cls + '" cx="100" cy="100" r="' + r + '"/>' +
		'<circle class="sig-ring__arc ' + cls + '" cx="100" cy="100" r="' + r + '" style="--to:' + to + '"/>';
}

function sigRingsHTML(d) {
	var tiles = d.tiles || {};
	var monthly = (d.monthly || []).map(function (v) { return Number(v) || 0; });
	var mi = new Date().getMonth();
	var now = monthly[mi] || 0;
	var prev = mi > 0 ? (monthly[mi - 1] || 0) : 0;
	var target = d.target || {};
	var goal = Number(target.target) || 0;
	var done = goal ? (Number(target.done) || 0) : now;
	var rows = [];

	if (goal) {
		var left = Math.max(0, goal - done);
		rows.push({ cls: 'is-a', label: 'Αιτήσεις', v: done, of: goal,
			sub: left ? left + ' ακόμα για τον στόχο' : 'Ο στόχος πιάστηκε', pct: done * 100 / goal });
	} else {
		rows.push({ cls: 'is-a', label: 'Αιτήσεις', v: now, of: prev || null,
			sub: prev ? 'ο ' + MONTHS_LONG_NOM[mi - 1] + ' είχε ' + prev : 'αυτόν τον μήνα',
			pct: prev ? now * 100 / prev : (now ? 100 : 0) });
	}

	var closed = Number(tiles.closed_month) || 0;
	var commission = Number(tiles.closed_month_commission) || 0;
	rows.push({ cls: 'is-b', label: 'Ενεργοποιήσεις', v: closed, of: null,
		sub: commission > 0 ? euro(commission) + ' προμήθεια' : 'ενεργές αυτόν τον μήνα',
		pct: now ? closed * 100 / now : 0 });

	var lvl = d.level || {};
	if (lvl.current && Number(lvl.next_at)) {
		var at = Number(lvl.next_at);
		var got = at - (Number(lvl.remaining) || 0);
		rows.push({ cls: 'is-c', label: String(lvl.current), v: got, of: at,
			sub: lvl.next ? (Number(lvl.remaining) || 0) + ' ακόμα για ' + lvl.next : 'ανώτατο επίπεδο', pct: got * 100 / at });
	}

	var center = Math.round(Math.min(999, rows[0].pct));
	var svg = rows.map(function (r, i) { return sigRing(i, r.pct, r.cls); }).join('');
	var legend = rows.map(function (r) {
		var of = r.of ? '<small> / ' + esc(r.of) + '</small>' : '';
		return '<div class="sig-leg__row"><i class="sig-dot ' + esc(r.cls) + '"></i><span>' + esc(r.label) + '</span>' +
			'<b>' + esc(r.v) + of + '</b><em>' + esc(r.sub) + '</em></div>';
	}).join('');

	return '<section class="ecrm-card sig-panel sig-rings"><div class="sig-ph"><h3>' + esc(MONTHS_LONG_NOM[mi]) + '</h3>' +
		(goal ? '' : '<span class="sig-ph__note">Χωρίς στόχο μήνα</span>') + '</div>' +
		'<div class="sig-rings__body"><div class="sig-ring"><svg viewBox="0 0 200 200" aria-hidden="true">' + svg + '</svg>' +
		'<div class="sig-ring__c"><div><b>' + center + '%</b><small>' + (goal ? 'του στόχου' : 'του προηγούμενου') + '</small></div></div></div>' +
		'<div class="sig-leg">' + legend + '</div></div></section>';
}

function sigPaceHTML(d) {
	var vals = (d.monthly || []).map(function (v) { return Number(v) || 0; });
	var mi = new Date().getMonth();
	vals = vals.slice(0, Math.max(2, mi + 1));
	var now = vals[mi] || 0;
	var prev = mi > 0 ? (vals[mi - 1] || 0) : 0;
	var chg = prev ? Math.round((now - prev) * 100 / prev) : null;

	var W = 400, HH = 150, P = 10;
	var max = Math.max.apply(null, vals.concat([1]));
	var x = function (i) { return vals.length < 2 ? W / 2 : i * W / (vals.length - 1); };
	var y = function (v) { return P + (HH - 2 * P) - (v / max) * (HH - 2 * P); };
	var pts = vals.map(function (v, i) { return x(i) + ' ' + y(v); });
	var line = 'M' + pts.join(' L');
	var li = vals.length - 1;

	var chgTxt = chg === null ? '' : (chg >= 0 ? '+' : '') + chg + '% από τον ' + MONTHS_LONG_NOM[mi - 1];

	return '<section class="ecrm-card sig-panel sig-pace"><div class="sig-ph"><h3>Ρυθμός</h3>' +
		(can('ecrm_view_analytics') ? '<button type="button" class="sig-link" data-go="analytics">Στατιστικά</button>' : '') + '</div>' +
		'<div class="sig-big">' + now + (chgTxt ? '<small class="' + (chg >= 0 ? 'is-up' : 'is-down') + '">' + esc(chgTxt) + '</small>' : '') + '</div>' +
		'<p class="sig-subt">αιτήσεις αυτόν τον μήνα</p>' +
		'<svg class="sig-chart" viewBox="0 0 ' + W + ' ' + HH + '" preserveAspectRatio="none" role="img" aria-label="Αιτήσεις ανά μήνα">' +
		'<path class="sig-chart__area" d="' + line + ' L' + x(li) + ' ' + HH + ' L0 ' + HH + ' Z"/>' +
		'<path class="sig-chart__line" d="' + line + '" vector-effect="non-scaling-stroke"/>' +
		'<circle class="sig-chart__dot" cx="' + x(li) + '" cy="' + y(vals[li]) + '" r="5" vector-effect="non-scaling-stroke"/></svg>' +
		'<div class="sig-axis"><span>' + MONTHS[0] + '</span><span>' + MONTHS[li] + '</span></div></section>';
}

/* Τα τέσσερα πλακίδια της κλασικής, ως μικρά «χάπια» κάτω από τον χαιρετισμό. */
function sigChipsHTML(t) {
	var week = Number(t.open_this_week) || 0, expiring = Number(t.expiring_today) || 0;
	var overdue = Number(t.tasks_overdue) || 0;
	var chips = [
		{ label: 'Ανοιχτές', v: Number(t.open) || 0, foot: week ? '+' + week + ' την εβδομάδα' : '' },
		{ label: 'Αναμονή υπογραφής', v: Number(t.awaiting_signature) || 0, foot: expiring ? expiring + ' λήγ' + (expiring === 1 ? 'ει' : 'ουν') + ' σήμερα' : '', warn: expiring > 0 },
		{ label: 'Κλεισμένες', v: Number(t.closed_month) || 0, go: 'contracts', status: 'active' },
		{ label: 'Εργασίες', v: Number(t.tasks_open) || 0, foot: overdue ? overdue + ' εκπρόθεσμ' + (overdue === 1 ? 'η' : 'ες') : '', warn: overdue > 0, go: 'tasks' }
	];

	return '<div class="sig-chips">' + chips.map(function (c) {
		var tag = c.go ? 'button' : 'div';
		var att = c.go ? ' type="button" data-go="' + esc(c.go) + '"' + (c.status ? ' data-status="' + esc(c.status) + '"' : '') : '';
		return '<' + tag + ' class="sig-chip' + (c.warn ? ' is-warn' : '') + '"' + att + '><b>' + esc(c.v) + '</b><span>' + esc(c.label) +
			(c.foot ? '<small>' + esc(c.foot) + '</small>' : '') + '</span></' + tag + '>';
	}).join('') + '</div>';
}

/* «Χρειάζονται εσένα»: οι δύο λίστες της κλασικής σε μία στοίβα, η πιο
 * επείγουσα πάνω. Οι υπόλοιπες ανοίγουν με ένα πάτημα. */
function sigNeedsRows(d) {
	var statuses = (window.ECRM && ECRM.statuses) || {};
	var rows = (d.attention || []).map(function (it) {
		var w = why(it);
		var kind = (it.status === 'awaiting_signature' || it.status === 'registration') ? 'is-sign' : it.status === 'draft' ? 'is-draft' : 'is-docs';
		return { kind: kind, title: statuses[it.status] || it.status, name: it.customer || '—',
			meta: [it.code ? '#' + it.code : '', it.provider || ''].filter(Boolean).join(', '),
			act: w.act, attr: ' data-attn-go="' + esc(it.id) + '"', age: Number(it.days) || 0 };
	});
	var extra = d.attention_extra || {};
	(extra.missing_docs || []).forEach(function (r) {
		rows.push({ kind: 'is-docs', title: 'Λείπει ' + (r.missing || []).join(', '), name: r.customer || '—', meta: r.code ? '#' + r.code : '',
			act: 'Άνοιγμα', attr: ' data-attnx-contract="' + esc(r.contract_id) + '"', age: Number(r.age_days) || 0 });
	});
	(extra.expired_docs || []).forEach(function (r) {
		rows.push({ kind: 'is-bad', title: 'Έληξε ' + (r.label || r.kind || ''), name: r.customer || '—', meta: r.code ? '#' + r.code : '',
			act: 'Άνοιγμα', attr: ' data-attnx-contract="' + esc(r.contract_id) + '"', age: Number(r.age_days) || 0 });
	});
	(extra.overdue_leads || []).forEach(function (r) {
		rows.push({ kind: 'is-bad', title: 'Πέρασε η επανάκληση', name: r.name || '—', meta: r.phone || '',
			act: 'Άνοιγμα', attr: ' data-attnx-lead="' + esc(r.id) + '"', age: Number(r.age_days) || 0 });
	});
	rows.sort(function (a, b) { return b.age - a.age; });
	return rows;
}

var SIG_ICON = { 'is-sign': '✎', 'is-draft': '…', 'is-docs': '!', 'is-bad': '×' };

function sigNeedsHTML(d) {
	var rows = sigNeedsRows(d);
	var head = '<div class="sig-ph"><h3>Χρειάζονται εσένα</h3>' + (rows.length ? '<span class="sig-count">' + rows.length + '</span>' : '') + '</div>';

	if (!rows.length) {
		return '<section class="ecrm-card sig-panel">' + head + '<p class="sig-empty">Τίποτα δεν περιμένει εσένα αυτή τη στιγμή.</p></section>';
	}

	var notes = rows.map(function (r, i) {
		var attr = r.attr;
		var days = r.age === 0 ? 'σήμερα' : r.age === 1 ? 'χθες' : r.age + ' μέρες';
		return '<div class="sig-note' + (i === 0 ? ' is-top' : i < 3 ? ' is-peek is-peek' + i : ' is-rest') + '">' +
			'<span class="sig-note__ic ' + esc(r.kind) + '">' + esc(SIG_ICON[r.kind]) + '</span>' +
			'<div class="sig-note__b"><b>' + esc(r.title) + '</b><p>' + esc(r.name) + (r.meta ? ', ' + esc(r.meta) : '') + '</p>' +
			'<button type="button" class="sig-note__go"' + attr + '>' + esc(r.act) + '</button></div>' +
			'<time>' + esc(days) + '</time></div>';
	}).join('');

	return '<section class="ecrm-card sig-panel">' + head + '<div class="sig-stack" data-sig-stack>' + notes + '</div>' +
		(rows.length > 1 ? '<button type="button" class="sig-more" data-sig-more data-n="' + rows.length + '">Δες και τα ' + rows.length + '</button>' : '') +
		'</section>';
}

function sigProvidersHTML(list) {
	list = list || [];
	var max = Math.max.apply(null, list.map(function (p) { return Number(p.c) || 0; }).concat([1]));
	var body = list.length ? list.map(function (p, i) {
		var c = Number(p.c) || 0;
		return '<div class="sig-pbar"><span>' + esc(p.name || '—') + '</span><i><b class="sig-pbar__f is-' + (i % 3) + '" style="width:' +
			Math.round(c * 100 / max) + '%"></b></i><em>' + c + '</em></div>';
	}).join('') : '<p class="sig-empty">Καμία αίτηση αυτόν τον μήνα ακόμα.</p>';

	return '<section class="ecrm-card sig-panel"><div class="sig-ph"><h3>Ανά πάροχο</h3><span class="sig-ph__note">αυτόν τον μήνα</span></div>' + body + '</section>';
}

function sigFeedHTML(feed) {
	feed = (feed || []).slice(0, 6);
	var body = feed.length ? '<ol class="sig-feed">' + feed.map(function (f) {
		return '<li><b>' + esc(f.message || f.type) + '</b><small>' + esc(timeAgo(f.created_at)) + (f.code ? ', #' + esc(f.code) : '') + '</small></li>';
	}).join('') + '</ol>' : '<p class="sig-empty">Καμία πρόσφατη δραστηριότητα.</p>';

	return '<section class="ecrm-card sig-panel"><div class="sig-ph"><h3>Δραστηριότητα</h3></div>' + body + '</section>';
}

/* Η νησίδα: το τελευταίο γεγονός, αν έγινε τα τελευταία 30 λεπτά. */
function sigIslandHTML(feed) {
	var f = (feed || [])[0];
	var m = f ? minutesAgo(f.created_at) : null;
	if (m === null || m > 30) return '';
	return '<div class="sig-island" role="status"><span class="sig-island__dot"></span><span class="sig-island__t"><b>' + esc(f.message || f.type) + '</b>' +
		'<small>' + esc(m === 0 ? 'μόλις τώρα' : 'πριν ' + m + '′') + (f.code ? ', #' + esc(f.code) : '') + '</small></span></div>';
}

function sigDashboardHTML(d) {
	var rows = sigNeedsRows(d).length;
	var lead = rows === 0 ? 'Όλα είναι σε τάξη.' : rows === 1 ? 'Ένα πράγμα περιμένει εσένα.' : rows + ' πράγματα περιμένουν εσένα.';

	return sigIslandHTML(d.feed) +
		'<header class="sig-hello' + (hasStore() ? ' has-tabs' : '') + '"><div><p class="sig-date">' +
		esc(new Date().toLocaleDateString('el-GR', { weekday: 'long', day: 'numeric', month: 'long' })) + '</p>' +
		'<h2>' + greeting() + ', ' + esc(d.user || '') + '.</h2><p class="sig-lead">' + esc(lead) + '</p></div>' + tabsHTML(false) + '</header>' +
		sigChipsHTML(d.tiles || {}) +
		'<div class="sig-grid">' + sigRingsHTML(d) + sigPaceHTML(d) + '</div>' +
		'<div class="sig-grid sig-grid--3">' + sigNeedsHTML(d) + sigProvidersHTML(d.by_provider) + sigFeedHTML(d.feed) + '</div>';
}

function wireSignature(view) {
	var more = view.querySelector('[data-sig-more]');
	var stack = view.querySelector('[data-sig-stack]');
	if (!more || !stack) return;
	more.addEventListener('click', function () {
		var open = stack.classList.toggle('is-open');
		more.textContent = open ? 'Μάζεψέ τα' : 'Δες και τα ' + more.getAttribute('data-n');
	});
}

function dashboardHTML(d) {
	if (isSignature()) { return sigDashboardHTML(d); }

	var lvl = d.level || {}, tiles = d.tiles || {};

	return '' +
		'<header class="ecrm-head' + (hasStore() ? ' ecrm-head--row' : '') + '"><div><div class="ecrm-eyebrow">' +
		new Date().toLocaleDateString('el-GR', { weekday: 'long', day: 'numeric', month: 'long' }) + '</div>' +
		'<h2 class="ecrm-title">' + greeting() + ', ' + esc(d.user || '') + '</h2></div>' + tabsHTML(false) + '</header>' +
		tilesHTML(tiles) +
		levelHTML(lvl) +
		myTargetHTML(d.target) +
		attentionHTML(d.attention) +
		attentionExtraHTML(d.attention_extra) +
		'<div class="ecrm-cols">' + trendHTML(d.monthly) + providersHTML(d.by_provider) + '</div>' +
		feedHTML(d.feed);
}

function wire(view, d) {
	wireSignature(view);

	// Το κουμπί κάθε γραμμής ανοίγει την ίδια τη σύμβαση: το dashboard δείχνει
	// δουλειά, και η δουλειά γίνεται στην καρτέλα.
	//
	// Μέσω openDetail() και ΟΧΙ location.hash: η εφαρμογή δεν δρομολογείται από
	// το hash — ο router κρατά handlers που περνά το κέλυφος, και το κλικ σε
	// γραμμή της λίστας κάνει ακριβώς αυτό. Η πρώτη γραφή έγραφε hash και το
	// κουμπί δεν έκανε απολύτως τίποτα, χωρίς σφάλμα πουθενά.
	view.querySelectorAll('[data-attn-go]').forEach(function (b) {
		b.addEventListener('click', function () {
			openDetail(b.getAttribute('data-attn-go'));
		});
	});

	// Ίδιο μοτίβο, δεύτερη κάρτα: contract-linked γραμμές ανοίγουν τη
	// σύμβαση, lead γραμμές πάνε στην οθόνη υποψηφίων -- δεν υπάρχει ακόμα
	// δική της καρτέλα lead στο κέλυφος, οπότε η λίστα είναι το πιο κοντινό
	// σωστό προορισμό (όχι εικασία νέου route για μια κάρτα).
	view.querySelectorAll('[data-attnx-contract]').forEach(function (b) {
		b.addEventListener('click', function () {
			openDetail(b.getAttribute('data-attnx-contract'));
		});
	});
	view.querySelectorAll('[data-attnx-lead]').forEach(function (b) {
		b.addEventListener('click', function () { go('leads'); });
	});

	var tip = view.querySelector('[data-charttip]');
	var svg = view.querySelector('.ecrm-plot');
	if (tip && svg) {
		svg.querySelectorAll('.ecrm-hit').forEach(function (r) {
			r.addEventListener('mouseenter', function () {
				var i = +r.getAttribute('data-i');
				var box = svg.parentNode.getBoundingClientRect(), sr = svg.getBoundingClientRect();
				var cx = +r.getAttribute('x') + (+r.getAttribute('width')) / 2;
				tip.innerHTML = MONTHS[i] + ' · <b>' + r.getAttribute('data-v') + '</b>';
				tip.style.left = (sr.left - box.left + cx / 560 * sr.width) + 'px';
				tip.style.top  = (sr.top - box.top + 20) + 'px';
				tip.classList.add('is-on');
			});
			r.addEventListener('mouseleave', function () { tip.classList.remove('is-on'); });
		});
	}

	// Κάθε γράφημα έχει και πίνακα: οι τιμές δεν επιτρέπεται να είναι
	// προσβάσιμες μόνο με ποντίκι πάνω από ένα σχήμα.
	var tv = view.querySelector('[data-tableview]');
	if (tv) {
		tv.addEventListener('click', function () {
			var tbl = view.querySelector('[data-charttbl]');
			var chart = view.querySelector('[data-chartwrap]');
			var on = tbl.hidden;
			tbl.hidden = !on;
			chart.hidden = on;
			tv.textContent = on ? 'Γράφημα' : 'Πίνακας';
			if (tip) { tip.classList.remove('is-on'); }
		});
	}
}
