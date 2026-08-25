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

function greeting() {
	var h = new Date().getHours();
	return h < 5 ? 'Καλό ξημέρωμα' : h < 12 ? 'Καλημέρα' : h < 18 ? 'Καλησπέρα' : 'Καλό βράδυ';
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

	var tiles = [
		{
			label: 'Ανοιχτές αιτήσεις',
			v: Number(t.open) || 0,
			foot: ''
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
			foot: commission > 0 ? '→ ' + Number(commission).toFixed(0) + ' € προμήθεια' : (closed === 0 ? 'καμία ακόμα' : ''),
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

/* Γιατί κάθε γραμμή είναι εκεί, και τι κουμπί της ταιριάζει. Η φράση φτιάχνεται
 * από την κατάσταση ΚΑΙ τις ημέρες, γιατί «Εκκρεμεί» σκέτο δεν λέει τίποτα
 * που δεν λέει ήδη το badge δίπλα.
 *
 * Το `late` (7+ μέρες) ΔΕΝ κωδικοποιείται πια σε ξεχωριστό χρώμα μπάρας —
 * αφαιρέθηκε 25/08 μαζί με το `ecrm-attn__b`, όταν το badge πήρε τη θέση της
 * μπάρας (ευθυγράμμιση με το kit A1, CHANGELOG (119)). Το σήμα δεν χάθηκε: το
 * κείμενο της ηλικίας υπογραμμίζεται όταν είναι στάσιμο πάνω από μια βδομάδα. */
function why(it) {
	var d = Number(it.days) || 0;
	var ago = d === 0 ? 'σήμερα' : d === 1 ? 'από χθες' : 'εδώ και ' + d + ' ημέρες';
	var late = d >= 7;

	if (it.status === 'draft') {
		return it.blocked_no_afm
			? { txt: 'λείπει ΑΦΜ, δεν οριστικοποιείται — ' + ago, act: 'Συνέχεια', late: late }
			: { txt: 'δεν οριστικοποιήθηκε — ' + ago, act: 'Συνέχεια', late: late };
	}
	if (it.status === 'awaiting_signature') {
		return { txt: 'περιμένει υπογραφή πελάτη — ' + ago, act: 'Υπενθύμιση', late: late };
	}
	return { txt: 'εκκρεμεί — ' + ago, act: 'Επίλυση', late: late };
}

/* Πινακίδα-γραμμή αντί για μπάρα χρώματος: όνομα/κωδικός αριστερά, badge
 * ΚΑΤΑΣΤΑΣΗΣ στη μέση — ίδιες κλάσεις `.ecrm-badge--{status}` με τη λίστα
 * συμβάσεων και τη λεπτομέρεια, ώστε το ίδιο χρώμα να σημαίνει το ίδιο πράγμα
 * παντού στην εφαρμογή — και κουμπί δεξιά. Δομή του kit A1, όχι δικές του
 * τιμές χρώματος: τα `--st-*` του δωδεκάδικου παλέτα είναι ήδη εκεί. */
function attentionHTML(list) {
	if (!list || !list.length) {
		return '<div class="ecrm-card"><div class="ecrm-step">Τι χρειάζεται εσένα</div>' +
			'<div class="ecrm-empty">Τίποτα δεν περιμένει εσένα αυτή τη στιγμή.</div></div>';
	}

	var statuses = (window.ECRM && ECRM.statuses) || {};

	return '<div class="ecrm-card"><div class="ecrm-head--row ecrm-attnhead">' +
		'<span class="ecrm-step">Τι χρειάζεται εσένα</span>' +
		'<span class="ecrm-attnhead__n">οι πιο στάσιμες</span></div>' +
		'<ul class="ecrm-attn">' + list.map(function (it) {
			var w = why(it);
			return '<li data-attn="' + esc(it.id) + '">' +
				'<span class="ecrm-attn__m"><b>' + esc(it.customer || '—') + '</b>' +
				(it.provider ? ' · ' + esc(it.provider) : '') +
				'<small' + (w.late ? ' class="is-late"' : '') + '>' + esc(it.code || '') + (it.code ? ' — ' : '') + esc(w.txt) + '</small></span>' +
				'<span class="ecrm-badge ecrm-badge--' + esc(it.status) + '">' + esc(statuses[it.status] || it.status) + '</span>' +
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
	var lvl = d.level || {}, tiles = d.tiles || {};

	return '' +
		'<header class="ecrm-head"><div class="ecrm-eyebrow">' +
		new Date().toLocaleDateString('el-GR', { weekday: 'long', day: 'numeric', month: 'long' }) + '</div>' +
		'<h2 class="ecrm-title">' + greeting() + ', ' + esc(d.user || '') + '</h2></header>' +
		tilesHTML(tiles) +
		levelHTML(lvl) +
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
