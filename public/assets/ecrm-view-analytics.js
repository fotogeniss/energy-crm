/* Energy CRM — analytics: funnel, distributions and the partner leaderboard.
 *
 * Same shape as the commissions view: analyticsScope lives here and nowhere
 * else, and the module asks the shell for nothing beyond the primitives.
 *
 * One line is worth reading twice. The server may downgrade a request for
 * 'team' to 'own', and whatever it answers is written back over the local
 * value — so the toggle shows what was actually served, not what was asked
 * for. Dropping that assignment would leave the UI claiming a scope the user
 * does not have.
 *
 * (358) In the new «Signature» look the same data is drawn by
 * sigAnalyticsHTML(): big title, four tiles, cards with rounded bars, the
 * month columns of Προμήθειες and the leaderboard as a list. The
 * «Γιατί χάνουμε αιτήσεις» card keeps its markup and its wiring (data-lost).
 */

import { api, esc, fetch, H, providerMark, viewEl } from '@energy-crm/util';
import { openReasonContracts } from '@energy-crm/navigate';

var analyticsScope = 'team';
export function loadAnalytics() {
	var view = viewEl('analytics');
	fetch(api('/analytics') + '?scope=' + encodeURIComponent(analyticsScope), { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (!d || !d.ok) { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα.</div></div>'; return; }
			analyticsScope = d.scope; // server may downgrade team→own
			view.innerHTML = analyticsHTML(d);
			wireLost(view, d.lost_reasons || []);
			view.querySelectorAll('[data-ascope]').forEach(function (b) { b.addEventListener('click', function () { analyticsScope = this.getAttribute('data-ascope'); loadAnalytics(); }); });
		})
		.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα δικτύου.</div></div>'; });
}

function barList(items, accentClass) {
	var max = 1; items.forEach(function (it) { if (it.count > max) max = it.count; });
	if (!items.length) return '<div class="ecrm-empty">—</div>';
	return '<div class="ecrm-barlist">' + items.map(function (it) {
		var pct = Math.round(100 * it.count / max);
		return '<div class="ecrm-barrow"><div class="ecrm-barrow__lbl">' + esc(it.label || it.name || '—') + '</div>' +
			'<div class="ecrm-barrow__track"><div class="ecrm-barrow__fill ' + (accentClass || '') + '" style="width:' + pct + '%"></div></div>' +
			'<div class="ecrm-barrow__val">' + it.count + '</div></div>';
	}).join('') + '</div>';
}

/* (342) «Γιατί χάνουμε αιτήσεις»: απορρίψεις και ακυρώσεις 90 ημερών ανά
 * λόγο, με καρτέλες παρόχου. Το πάτημα σε λόγο ανοίγει τις αιτήσεις του.
 * docs/UI-REJECT-REASONS.html. */
var lostProvider = '';

function lostHTML(rows, provider) {
	var providers = [];
	rows.forEach(function (r) { if (r.provider && providers.indexOf(r.provider) < 0) providers.push(r.provider); });
	var by = {}, order = [];
	rows.forEach(function (r) {
		if (provider && r.provider !== provider) return;
		if (!by[r.reason]) { by[r.reason] = { reason: r.reason, label: r.label, count: 0 }; order.push(r.reason); }
		by[r.reason].count += r.count;
	});
	var items = order.map(function (k) { return by[k]; }).sort(function (a, b) { return b.count - a.count; });
	var max = 1; items.forEach(function (it) { if (it.count > max) max = it.count; });

	var tabs = providers.length > 1
		? '<div class="ecrm-xsell__tabs">' +
			'<button type="button" class="ecrm-xsell__tab' + (provider === '' ? ' is-on' : '') + '" data-lost-prov="">Όλοι οι πάροχοι</button>' +
			providers.map(function (p) {
				return '<button type="button" class="ecrm-xsell__tab' + (provider === p ? ' is-on' : '') + '" data-lost-prov="' + esc(p) + '">' + esc(p) + '</button>';
			}).join('') + '</div>'
		: '';

	var list = items.length
		? '<div class="ecrm-barlist">' + items.map(function (it) {
			var pct = Math.round(100 * it.count / max);
			var lbl = it.reason
				? '<button type="button" class="ecrm-lost__go" data-lost-reason="' + esc(it.reason) + '" data-lost-label="' + esc(it.label) + '">' + esc(it.label) + '</button>'
				: esc(it.label + ' (παλιές / Excel)');
			return '<div class="ecrm-barrow"><div class="ecrm-barrow__lbl">' + lbl + '</div>' +
				'<div class="ecrm-barrow__track"><div class="ecrm-barrow__fill is-lost" style="width:' + pct + '%"></div></div>' +
				'<div class="ecrm-barrow__val">' + esc(it.count) + '</div></div>';
		}).join('') + '</div>'
		: '<div class="ecrm-empty">Καμία απόρριψη ή ακύρωση τις τελευταίες 90 μέρες.</div>';

	return '<div class="ecrm-step">Γιατί χάνουμε αιτήσεις · τελευταίες 90 μέρες</div>' + tabs + list;
}

function wireLost(view, rows) {
	var box = view.querySelector('[data-lost]');
	if (!box) return;
	box.querySelectorAll('[data-lost-prov]').forEach(function (b) {
		b.addEventListener('click', function () {
			lostProvider = b.getAttribute('data-lost-prov') || '';
			box.innerHTML = lostHTML(rows, lostProvider);
			wireLost(view, rows);
		});
	});
	box.querySelectorAll('[data-lost-reason]').forEach(function (b) {
		b.addEventListener('click', function () {
			openReasonContracts(b.getAttribute('data-lost-reason'), b.getAttribute('data-lost-label'));
		});
	});
}

function analyticsHTML(d) {
	if (isSignature()) return sigAnalyticsHTML(d);
	var scopeToggle = d.can_team
		? '<div class="ecrm-scope"><button type="button" class="ecrm-scope__b' + (analyticsScope==="own"?" is-on":"") + '" data-ascope="own">Δικά μου</button><button type="button" class="ecrm-scope__b' + (analyticsScope==="team"?" is-on":"") + '" data-ascope="team">Ομάδας</button></div>'
		: '';

	var kpis =
		'<div class="ecrm-stats">' +
		'<div class="ecrm-stat is-routed"><div class="ecrm-stat__k">Σύνολο αιτήσεων</div><div class="ecrm-stat__v">' + (d.total || 0) + '</div></div>' +
		'<div class="ecrm-stat is-today"><div class="ecrm-stat__k">✓ Conversion</div><div class="ecrm-stat__v">' + Number(d.conv_rate || 0).toFixed(1) + '%</div></div>' +
		'<div class="ecrm-stat is-pending"><div class="ecrm-stat__k">✕ Ακυρώσεις</div><div class="ecrm-stat__v">' + Number(d.canc_rate || 0).toFixed(1) + '%</div></div>' +
		'<div class="ecrm-stat is-today"><div class="ecrm-stat__k"><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg> Μέσος χρόνος ενεργ.</div><div class="ecrm-stat__v">' + (d.avg_days == null ? '—' : (Number(d.avg_days).toFixed(1) + ' ημ.')) + '</div></div>' +
		'</div>';

	// funnel (only stages with any count, in pipeline order)
	var funnel = (d.funnel || []).filter(function (f) { return f.count > 0; });
	var funnelMax = 1; funnel.forEach(function (f) { if (f.count > funnelMax) funnelMax = f.count; });
	var funnelHTML = funnel.length
		? '<div class="ecrm-barlist">' + funnel.map(function (f) {
			var pct = Math.round(100 * f.count / funnelMax);
			return '<div class="ecrm-barrow"><div class="ecrm-barrow__lbl"><span class="ecrm-badge ecrm-badge--' + esc(f.status) + '">' + esc(f.label) + '</span></div>' +
				'<div class="ecrm-barrow__track"><div class="ecrm-barrow__fill" style="width:' + pct + '%"></div></div>' +
				'<div class="ecrm-barrow__val">' + f.count + '</div></div>';
		}).join('') + '</div>'
		: '<div class="ecrm-empty">Καμία αίτηση.</div>';

	// monthly trend
	var months = ['Ιαν','Φεβ','Μαρ','Απρ','Μαϊ','Ιουν','Ιουλ','Αυγ','Σεπ','Οκτ','Νοε','Δεκ'];
	var mvals = d.monthly || [];
	var mmax = 1; mvals.forEach(function (v) { if (v > mmax) mmax = v; });
	var trend = '<div class="ecrm-trend">' + mvals.map(function (v, i) {
		var h = Math.round(100 * v / mmax);
		return '<div class="ecrm-trend__col" title="' + months[i] + ': ' + v + '"><div class="ecrm-trend__bar" style="height:' + Math.max(3, h) + '%"></div><div class="ecrm-trend__lbl">' + months[i] + '</div></div>';
	}).join('') + '</div>';

	// leaderboard
	var lb = (d.leaderboard || []);
	// (318) Χωρίς δικαίωμα προμηθειών (Back office) ο server δεν στέλνει
	// `amount` και κατατάσσει με το πλήθος· εδώ απλώς δεν μπαίνει η στήλη €.
	// Χωρίς τη στήλη, το πλήθος είναι η μόνη τιμή και δεν κρύβεται στο κινητό.
	var lbMoney = lb.length > 0 && lb[0].amount !== undefined;
	var lbCountCls = lbMoney ? ' class="ecrm-col-sec"' : '';
	var lbHTML = lb.length
		? '<div class="ecrm-tablewrap"><table class="ecrm-table"><thead><tr><th>#</th><th>Συνεργάτης</th><th' + lbCountCls + '>Συμβάσεις</th>' +
			(lbMoney ? '<th style="text-align:right">Προμήθεια €</th>' : '') + '</tr></thead><tbody>' +
			lb.map(function (r, i) {
				var medal = i < 3 ? '<span class="ecrm-medal ecrm-medal--' + (i + 1) + '">' + (i + 1) + '</span>' : (i + 1);
				return '<tr><td>' + medal + '</td><td><strong>' + esc(r.name) + '</strong></td><td' + lbCountCls + '>' + r.count + '</td>' +
					(lbMoney ? '<td style="text-align:right" class="ecrm-mono">' + Number(r.amount).toFixed(0) + ' €</td>' : '') + '</tr>';
			}).join('') + '</tbody></table></div>'
		: '<div class="ecrm-empty">Διαθέσιμο σε προβολή «Ομάδας».</div>';

	return '' +
		'<header class="ecrm-head ecrm-head--row"><div><div class="ecrm-eyebrow">Διοίκηση</div><h2 class="ecrm-title">Στατιστικά</h2>' +
		'<p class="ecrm-sub">Funnel, αποδοτικότητα και κατανομές</p></div>' + scopeToggle + '</header>' +
		kpis +
		'<div class="ecrm-cols">' +
		'<div class="ecrm-card"><div class="ecrm-step">Funnel καταστάσεων</div>' + funnelHTML + '</div>' +
		'<div class="ecrm-card"><div class="ecrm-step">Ανά πάροχο</div>' + barList(d.by_provider || [], 'is-prov') + '</div>' +
		'</div>' +
		'<div class="ecrm-cols">' +
		'<div class="ecrm-card"><div class="ecrm-step">Ανά υπηρεσία</div>' + barList(d.by_energy || [], 'is-energy') + '</div>' +
		'<div class="ecrm-card"><div class="ecrm-step">Ανά νομό</div>' + barList(d.by_region || [], 'is-region') + '</div>' +
		'</div>' +
		'<div class="ecrm-card" data-lost>' + lostHTML(d.lost_reasons || [], lostProvider) + '</div>' +
		'<div class="ecrm-card"><div class="ecrm-step">Τάση μήνα (' + new Date().getFullYear() + ')</div>' + trend + '</div>' +
		'<div class="ecrm-card"><div class="ecrm-step"><svg class="ecrm-i ecrm-i--step" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 4h8v5a4 4 0 01-8 0zM8 6H5v2a3 3 0 003 3M16 6h3v2a3 3 0 01-3 3M10 13h4v3h-4zM8 20h8M12 16v4"/></svg> Κατάταξη συνεργατών</div>' + lbHTML + '</div>';
}

// ---------------------------------------------------------------------
// (358) «Signature»: Στατιστικά
// ---------------------------------------------------------------------

function isSignature() {
	var app = document.getElementById('ecrm-app');
	return !!app && app.getAttribute('data-ui') === 'signature';
}

/* Μία λίστα με στρογγυλές μπάρες. `label` είναι ήδη HTML (escaped). */
function sigBars(items, tone) {
	if (!items.length) return '<div class="sig-tempty"><b>Τίποτα ακόμα.</b></div>';
	var max = 1; items.forEach(function (it) { if (it.count > max) max = it.count; });
	return '<ul class="sig-bars">' + items.map(function (it) {
		var pct = Math.max(2, Math.round(100 * it.count / max));
		var labelHtml = it.label; // ήδη escaped από όποιον έφτιαξε τη λίστα
		return '<li><span class="sig-bars__l">' + labelHtml + '</span>' +
			'<span class="sig-bars__t"><i class="' + tone + '" style="width:' + esc(String(pct)) + '%"></i></span>' +
			'<b>' + esc(String(it.count)) + '</b></li>';
	}).join('') + '</ul>';
}

function plainItems(items) {
	return (items || []).map(function (it) { return { label: esc(it.label || it.name || '—'), count: Number(it.count) || 0 }; });
}

function pctText(n) {
	return Number(n || 0).toFixed(1).replace('.', ',') + '%';
}

function sigAnalyticsHTML(d) {
	var scopeToggle = d.can_team
		? '<div class="ecrm-scope"><button type="button" class="ecrm-scope__b' + (analyticsScope === 'own' ? ' is-on' : '') + '" data-ascope="own">Δικά μου</button>' +
			'<button type="button" class="ecrm-scope__b' + (analyticsScope === 'team' ? ' is-on' : '') + '" data-ascope="team">Ομάδας</button></div>'
		: '';

	var avg = d.avg_days == null ? '—' : Number(d.avg_days).toFixed(1).replace('.', ',') + ' ημ.';
	var kpis = '<div class="sig-kpis">' +
		'<div><small>Αιτήσεις</small><b>' + esc(String(d.total || 0)) + '</b><em>συνολικά</em></div>' +
		'<div><small>Γίνονται ενεργές</small><b>' + esc(pctText(d.conv_rate)) + '</b><em>από όσες μπήκαν</em></div>' +
		'<div><small>Ακυρώνονται</small><b>' + esc(pctText(d.canc_rate)) + '</b><em>απορρίψεις και ακυρώσεις</em></div>' +
		'<div><small>Μέχρι να γίνει ενεργή</small><b>' + esc(avg) + '</b><em>κατά μέσο όρο</em></div>' +
		'</div>';

	var funnel = (d.funnel || []).filter(function (f) { return f.count > 0; }).map(function (f) {
		return { label: '<span class="ecrm-badge ecrm-badge--' + esc(f.status) + '">' + esc(f.label) + '</span>', count: Number(f.count) || 0 };
	});

	function card(title, body, extra) {
		return '<section class="ecrm-card sig-acard"' + (extra || '') + '><h3 class="sig-ch">' + esc(title) + '</h3>' + body + '</section>';
	}

	// Τάση: οι στήλες των Προμηθειών, με πλήθος αντί για ευρώ.
	var months = ['Ιαν', 'Φεβ', 'Μαρ', 'Απρ', 'Μάι', 'Ιούν', 'Ιούλ', 'Αύγ', 'Σεπ', 'Οκτ', 'Νοέ', 'Δεκ'];
	var mvals = d.monthly || [];
	var now = new Date().getMonth();
	var mtop = mvals.reduce(function (n, v) { return Math.max(n, Number(v) || 0); }, 0);
	var trend = '<section class="ecrm-card sig-paychart"><h3 class="sig-ch">Αιτήσεις ανά μήνα, ' + esc(String(new Date().getFullYear())) + '</h3>' +
		'<div class="sig-paychart__cols">' + mvals.map(function (v, i) {
			var h = mtop ? Math.max(4, Math.round((Number(v) || 0) / mtop * 100)) : 4;
			return '<div class="sig-paychart__col' + (i === now ? ' is-paid' : '') + (i > now ? ' is-future' : '') + '" title="' + esc(months[i] + ': ' + v) + '">' +
				'<span class="sig-paychart__v">' + esc(String(v)) + '</span>' +
				'<span class="sig-paychart__b"><i style="height:' + esc(String(h)) + '%"></i></span>' +
				'<small>' + esc(months[i]) + '</small></div>';
		}).join('') + '</div></section>';

	var lb = d.leaderboard || [];
	var lbMoney = lb.length > 0 && lb[0].amount !== undefined;
	var board = lb.length
		? '<ul class="sig-paylist sig-board">' + lb.map(function (r, i) {
			return '<li><span class="sig-board__n' + (i < 3 ? ' is-top' : '') + '">' + esc(String(i + 1)) + '</span>' +
				'<span class="sig-paylist__who"><b>' + esc(r.name) + '</b><small>' + esc(String(r.count)) + (Number(r.count) === 1 ? ' σύμβαση' : ' συμβάσεις') + '</small></span>' +
				(lbMoney ? '<b class="sig-paylist__amt">' + esc(Number(r.amount).toFixed(0)) + ' €</b>' : '') + '</li>';
		}).join('') + '</ul>'
		: '<div class="sig-tempty"><b>Η κατάταξη φαίνεται στην προβολή «Ομάδας».</b></div>';

	return '<header class="sig-cuhead"><div><h2>Στατιστικά</h2><p>Πώς πάνε οι αιτήσεις</p></div>' + scopeToggle + '</header>' +
		kpis +
		'<div class="sig-agrid">' +
			card('Πού βρίσκονται', sigBars(funnel, 'is-green')) +
			card('Ανά πάροχο', sigBars((d.by_provider || []).map(function (it) {
				return { label: providerMark(it.label, it.logo) + esc(it.label || '—'), count: Number(it.count) || 0 };
			}), 'is-teal')) +
			card('Ανά υπηρεσία', sigBars(plainItems(d.by_energy), 'is-blue')) +
			card('Ανά νομό', sigBars(plainItems(d.by_region), 'is-teal')) +
		'</div>' +
		trend +
		'<div class="sig-agrid">' +
			'<section class="ecrm-card sig-acard sig-lost" data-lost>' + lostHTML(d.lost_reasons || [], lostProvider) + '</section>' +
			'<section class="sig-acard--plain"><h3 class="sig-ch sig-paygrid__h">Κατάταξη συνεργατών</h3>' + board + '</section>' +
		'</div>';
}
