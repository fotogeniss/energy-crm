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
import { openDetail, go } from '@energy-crm/navigate';

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
	return { act: 'Επίλυση', tip: 'Εκκρεμεί — ' + ago };
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

function dashboardHTML(d) {
	var lvl = d.level || {}, tiles = d.tiles || {};

	return '' +
		'<header class="ecrm-head"><div class="ecrm-eyebrow">' +
		new Date().toLocaleDateString('el-GR', { weekday: 'long', day: 'numeric', month: 'long' }) + '</div>' +
		'<h2 class="ecrm-title">' + greeting() + ', ' + esc(d.user || '') + '</h2></header>' +
		tilesHTML(tiles) +
		levelHTML(lvl) +
		attentionHTML(d.attention) +
		attentionExtraHTML(d.attention_extra) +
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
