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
 */

import { api, esc, fetch, H, viewEl } from '@energy-crm/util';

var analyticsScope = 'team';
export function loadAnalytics() {
	var view = viewEl('analytics');
	fetch(api('/analytics') + '?scope=' + encodeURIComponent(analyticsScope), { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (!d || !d.ok) { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα.</div></div>'; return; }
			analyticsScope = d.scope; // server may downgrade team→own
			view.innerHTML = analyticsHTML(d);
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

function analyticsHTML(d) {
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
	var lbHTML = lb.length
		? '<div class="ecrm-tablewrap"><table class="ecrm-table"><thead><tr><th>#</th><th>Συνεργάτης</th><th class="ecrm-col-sec">Συμβάσεις</th><th style="text-align:right">Προμήθεια €</th></tr></thead><tbody>' +
			lb.map(function (r, i) {
				var medal = i < 3 ? '<span class="ecrm-medal ecrm-medal--' + (i + 1) + '">' + (i + 1) + '</span>' : (i + 1);
				return '<tr><td>' + medal + '</td><td><strong>' + esc(r.name) + '</strong></td><td class="ecrm-col-sec">' + r.count + '</td>' +
					'<td style="text-align:right" class="ecrm-mono">' + Number(r.amount).toFixed(0) + ' €</td></tr>';
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
		'<div class="ecrm-card"><div class="ecrm-step">Τάση μήνα (' + new Date().getFullYear() + ')</div>' + trend + '</div>' +
		'<div class="ecrm-card"><div class="ecrm-step"><svg class="ecrm-i ecrm-i--step" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 4h8v5a4 4 0 01-8 0zM8 6H5v2a3 3 0 003 3M16 6h3v2a3 3 0 01-3 3M10 13h4v3h-4zM8 20h8M12 16v4"/></svg> Κατάταξη συνεργατών</div>' + lbHTML + '</div>';
}
