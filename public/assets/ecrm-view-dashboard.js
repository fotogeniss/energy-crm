/* Energy CRM — dashboard: η πρώτη οθόνη, και τι χρειάζεται εσένα σήμερα.
 *
 * Ξαναγράφτηκε 2026-08-18. Το προηγούμενο έδειχνε τέσσερις ισότιμους μετρητές,
 * δώδεκα κοντόχοντρες στήλες και μια λίστα παρόχων — αριθμούς χωρίς σύγκριση
 * και χωρίς ενέργεια. Δες CHANGELOG (17) και docs/UI-DASHBOARD.html.
 *
 * Οι μορφές δεν διαλέχτηκαν στο μάτι:
 *   · ΕΝΑ ηρωικό νούμερο (ο μήνας) — τα υπόλοιπα είναι πλαίσιο.
 *   · Γραμμή και όχι στήλες για τους 12 μήνες: μία σειρά είναι τάση.
 *   · ΕΝΑ χρώμα για όλες τις ράβδους παρόχων — είναι μέγεθος, όχι ταυτότητα,
 *     και χρώμα ανά πάροχο θα ξόδευε το μοναδικό ελεύθερο κανάλι για
 *     πληροφορία που το μήκος ήδη δείχνει.
 *   · Οι ράβδοι είναι --p500 και η γραμμή --p600, ΟΧΙ το accent: το #16c217
 *     δίνει 2,33:1 σε λευκό, κάτω από το 3:1 που θέλουν τα γραφικά.
 */

import { api, esc, fetch, H, viewEl } from '@energy-crm/util';
import { timeAgo } from '@energy-crm/format';
import { openDetail } from '@energy-crm/navigate';

var MONTHS = ['Ιαν', 'Φεβ', 'Μαρ', 'Απρ', 'Μάι', 'Ιουν', 'Ιουλ', 'Αυγ', 'Σεπ', 'Οκτ', 'Νοε', 'Δεκ'];

export function loadDashboard() {
	var view = viewEl('dashboard');
	fetch(api('/dashboard'), { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) { view.innerHTML = dashboardHTML(d); wire(view, d); })
		.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα φόρτωσης dashboard.</div></div>'; });
}

/* Ο μήνας που πέρασε, από τα δεδομένα που ήδη έρχονται. Το API δεν στέλνει
 * σύγκριση — αλλά στέλνει και τους δώδεκα μήνες, οπότε η διαφορά βγαίνει δωρεάν
 * εδώ και δεν χρειάστηκε τίποτα από το backend. */
function prevMonth(monthly) {
	var i = new Date().getMonth();                 // 0-11, ο τρέχων
	return i > 0 && monthly && monthly.length > i ? Number(monthly[i - 1]) || 0 : null;
}

function greeting() {
	var h = new Date().getHours();
	return h < 5 ? 'Καλό ξημέρωμα' : h < 12 ? 'Καλημέρα' : h < 18 ? 'Καλησπέρα' : 'Καλό βράδυ';
}

function heroHTML(c, lvl, monthly) {
	var month = Number(c.month) || 0;
	var prev  = prevMonth(monthly);
	var delta = prev === null ? null : month - prev;

	var chip = '';
	if (delta !== null && delta !== 0) {
		var up = delta > 0;
		chip = '<span class="ecrm-delta ' + (up ? 'is-up' : 'is-down') + '">' +
			'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 ' + (up ? '19V5M5 12l7-7 7 7' : '5v14M5 12l7 7 7-7') + '"/></svg>' +
			(up ? '+' : '') + delta + '</span>';
	}

	var sub = prev === null ? 'ο πρώτος μήνας της χρονιάς'
		: (delta === 0 ? 'ίδιες με τον προηγούμενο μήνα' : 'έναντι ' + prev + ' τον προηγούμενο μήνα');

	// Ο μετρητής: γεμάτο σκαλί πάνω σε ΑΝΟΙΧΤΟΤΕΡΟ σκαλί της ίδιας ράμπας, όχι
	// γκρι, ώστε η κατάσταση να διαβάζεται σε όλο το μήκος.
	var at   = Number(lvl.next_at) || 0;
	var from = Math.max(0, at - (Number(lvl.remaining) || 0) - month + month);
	var pct  = at ? Math.min(100, Math.round((month / at) * 100)) : 0;

	return '<div class="ecrm-card"><div class="ecrm-hero">' +
		'<div><div class="ecrm-eyebrow">Αιτήσεις αυτόν τον μήνα</div>' +
		'<div class="ecrm-hero__n">' + month + chip + '</div>' +
		'<div class="ecrm-hero__lbl">' + sub + '</div></div>' +
		'<div><div class="ecrm-tier"><span class="ecrm-tier__now">' + esc(lvl.current || 'Χωρίς level') + '</span>' +
		(lvl.next ? '<span class="ecrm-tier__next">' + (Number(lvl.remaining) || 0) + ' ακόμα για ' + esc(lvl.next) + '</span>' : '') +
		'</div><div class="ecrm-meter"><div class="ecrm-meter__f" style="width:' + pct + '%"></div></div>' +
		(at ? '<div class="ecrm-tier__scale"><span>0</span><span>' + esc(lvl.next || '') + ' ' + at + '</span></div>' : '') +
		'</div></div></div>';
}

var KPI = [
	{ k: 'today',   label: 'Σήμερα',          cls: 'is-ok'   },
	{ k: 'pending', label: 'Εκκρεμότητες',    cls: 'is-pend' },
	{ k: 'routed',  label: 'Δρομολογήθηκαν',  cls: 'is-route', foot: 'περιμένουν τον πάροχο' }
];

function kpisHTML(c) {
	return '<div class="ecrm-kpis">' + KPI.map(function (x) {
		return '<div class="ecrm-kpi"><div class="ecrm-kpi__k"><span class="ecrm-kpi__dot ' + esc(x.cls) + '"></span>' + esc(x.label) + '</div>' +
			'<div class="ecrm-kpi__v">' + (Number(c[x.k]) || 0) + '</div>' +
			(x.foot ? '<div class="ecrm-kpi__f">' + esc(x.foot) + '</div>' : '') + '</div>';
	}).join('') + '</div>';
}

/* Γιατί κάθε γραμμή είναι εκεί, και τι κουμπί της ταιριάζει. Η φράση φτιάχνεται
 * από την κατάσταση ΚΑΙ τις ημέρες, γιατί «Εκκρεμότητα» σκέτο δεν λέει τίποτα
 * που δεν λέει ήδη το badge δίπλα. */
function why(it) {
	var d = Number(it.days) || 0;
	var ago = d === 0 ? 'σήμερα' : d === 1 ? 'από χθες' : 'εδώ και ' + d + ' ημέρες';

	if (it.status === 'draft') {
		return it.blocked_no_afm
			? { txt: 'Πρόχειρο ' + ago + ' — λείπει ΑΦΜ, δεν οριστικοποιείται', act: 'Συνέχεια', tone: 'is-mute' }
			: { txt: 'Πρόχειρο ' + ago + ', δεν οριστικοποιήθηκε', act: 'Συνέχεια', tone: 'is-mute' };
	}
	if (it.status === 'awaiting_signature') {
		return { txt: 'Περιμένει υπογραφή πελάτη ' + ago, act: 'Υπενθύμιση', tone: 'is-pend' };
	}
	return { txt: 'Εκκρεμότητα ' + ago, act: 'Επίλυση', tone: d >= 7 ? 'is-late' : 'is-pend' };
}

function attentionHTML(list) {
	if (!list || !list.length) {
		return '<div class="ecrm-card"><div class="ecrm-step">Τι χρειάζεται εσένα</div>' +
			'<div class="ecrm-empty">Τίποτα δεν περιμένει εσένα αυτή τη στιγμή.</div></div>';
	}

	return '<div class="ecrm-card"><div class="ecrm-head--row ecrm-attnhead">' +
		'<span class="ecrm-step">Τι χρειάζεται εσένα</span>' +
		'<span class="ecrm-attnhead__n">οι πιο στάσιμες</span></div>' +
		'<ul class="ecrm-attn">' + list.map(function (it) {
			var w = why(it);
			return '<li data-attn="' + esc(it.id) + '"><span class="ecrm-attn__b ' + esc(w.tone) + '"></span>' +
				'<span class="ecrm-attn__m"><b>' + esc(it.customer || '—') + '</b>' +
				(it.provider ? ' · ' + esc(it.provider) : '') +
				'<small>' + esc(it.code || '') + (it.code ? ' — ' : '') + esc(w.txt) + '</small></span>' +
				'<button type="button" class="ecrm-attn__a" data-attn-go="' + esc(it.id) + '">' + esc(w.act) + '</button></li>';
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
		'<table class="ecrm-charttbl" data-charttbl hidden><thead><tr><th>Μήνας</th><th class="ecrm-num">Αιτήσεις</th></tr></thead><tbody>' + rows + '</tbody></table></div>';
}

function providersHTML(list) {
	if (!list || !list.length) {
		return '<div class="ecrm-card"><div class="ecrm-step">Ανά πάροχο</div>' +
			'<div class="ecrm-empty">Δεν υπάρχουν αιτήσεις σε κανέναν πάροχο ακόμα.</div></div>';
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

function feedHTML(feed) {
	if (!feed || !feed.length) {
		return '<div class="ecrm-card"><div class="ecrm-step">Ζωντανή ροή</div>' +
			'<div class="ecrm-empty">Καμία πρόσφατη δραστηριότητα.</div></div>';
	}
	return '<div class="ecrm-card"><div class="ecrm-step">Ζωντανή ροή</div><ul class="ecrm-feed">' +
		feed.map(function (f) {
			return '<li><span class="ecrm-feed__code">' + esc(f.code || '—') + '</span>' +
				'<span class="ecrm-feed__msg">' + esc(f.message || f.type) + '</span>' +
				'<span class="ecrm-feed__time">' + timeAgo(f.created_at) + '</span></li>';
		}).join('') + '</ul></div>';
}

function dashboardHTML(d) {
	var c = d.cards || {}, lvl = d.level || {};

	return '' +
		'<header class="ecrm-head"><div class="ecrm-eyebrow">' +
		new Date().toLocaleDateString('el-GR', { weekday: 'long', day: 'numeric', month: 'long' }) + '</div>' +
		'<h2 class="ecrm-title">' + greeting() + ', ' + esc(d.user || '') + '</h2></header>' +
		heroHTML(c, lvl, d.monthly) +
		kpisHTML(c) +
		attentionHTML(d.attention) +
		'<div class="ecrm-cols">' + trendHTML(d.monthly) + providersHTML(d.by_provider) + '</div>' +
		feedHTML(d.feed);
}

function wire(view, d) {
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
