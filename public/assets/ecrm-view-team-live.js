/* Energy CRM — team live: who is working right now.
 *
 * Owns a timer, and clearing it is not optional: leaving the interval running
 * after the view is left keeps polling a screen nobody is looking at. */

import { api, esc, fetch, H, viewEl } from '@energy-crm/util';
import { timeAgo } from '@energy-crm/format';

var teamLiveTimer = null;
export function loadTeamLive() {
	var view = viewEl('teamlive');
	fetch(api('/team/live'), { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (!d || !d.ok) { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">' + esc((d && d.error) || 'Σφάλμα.') + '</div></div>'; return; }
			renderTeamLive(view, d);
		})
		.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα δικτύου.</div></div>'; });

	// auto-refresh every 30s while this view is active
	clearInterval(teamLiveTimer);
	teamLiveTimer = setInterval(function () {
		var v = viewEl('teamlive');
		if (!v || !v.classList.contains('is-active')) { clearInterval(teamLiveTimer); return; }
		fetch(api('/team/live'), { headers: H() }).then(function (r) { return r.json(); })
			.then(function (d) { if (d && d.ok) renderTeamLive(v, d); }).catch(function () {});
	}, 30000);
}
function renderTeamLive(view, d) {
	var t = d.totals || {};
	var stat = function (label, val, cls) {
		return '<div class="ecrm-tlstat ' + (cls || '') + '"><div class="ecrm-tlstat__n">' + val + '</div><div class="ecrm-tlstat__l">' + label + '</div></div>';
	};
	var cards = '<div class="ecrm-tlstats">' +
		stat('Online τώρα', (t.online || 0), 'is-online') +
		stat('Σήμερα', (t.today || 0)) +
		stat('Αυτόν τον μήνα', (t.month || 0)) +
		stat('Εκκρεμότητες', (t.pending || 0)) +
		stat('Δρομολογήθηκαν', (t.routed || 0)) +
		stat('Ενεργές', (t.active || 0)) +
	'</div>';

	var rows = (d.members || []).map(function (m) {
		var dot = m.online ? '<span class="ecrm-tldot is-on" title="Ενεργός τελευταίο 30λεπτο"></span>' : '<span class="ecrm-tldot" title="Ανενεργός"></span>';
		var last = m.last ? timeAgo(m.last) : '—';
		return '<tr>' +
			'<td>' + dot + '<strong>' + esc(m.name) + '</strong>' + (m.is_self ? ' <span class="ecrm-muted">(εσύ)</span>' : '') + '<div class="ecrm-muted ecrm-tlrole">' + esc(m.role) + '</div></td>' +
			'<td class="ecrm-tlnum">' + m.today + '</td>' +
			'<td class="ecrm-tlnum ecrm-col-sec">' + m.month + '</td>' +
			'<td class="ecrm-tlnum">' + (m.pending ? '<span class="ecrm-pillwarn">' + m.pending + '</span>' : '0') + '</td>' +
			'<td class="ecrm-tlnum ecrm-col-sec">' + m.routed + '</td>' +
			'<td class="ecrm-tlnum">' + (m.open_tasks ? '<span class="ecrm-pillwarn">' + m.open_tasks + '</span>' : '0') + '</td>' +
			'<td class="ecrm-muted ecrm-col-sec">' + last + '</td>' +
			'</tr>';
	}).join('');
	if (!(d.members || []).length) rows = '<tr><td colspan="7" class="ecrm-empty">Δεν υπάρχουν μέλη ομάδας.</td></tr>';

	view.innerHTML =
		'<header class="ecrm-head ecrm-head--row"><div><div class="ecrm-eyebrow">Πραγματικός χρόνος</div><h2 class="ecrm-title">Ομάδα Live</h2>' +
		'<p class="ecrm-sub">Δραστηριότητα ομάδας · αυτόματη ανανέωση κάθε 30″</p></div>' +
		'<span class="ecrm-live"><span class="ecrm-live__dot"></span> ' + esc(d.ts || '') + '</span></header>' +
		cards +
		'<div class="ecrm-card"><div class="ecrm-tablewrap"><table class="ecrm-table"><thead><tr>' +
		'<th>Μέλος</th><th>Σήμερα</th><th class="ecrm-col-sec">Μήνας</th><th>Εκκρεμ.</th><th class="ecrm-col-sec">Δρομ/καν</th><th>Εργασίες</th><th class="ecrm-col-sec">Τελ. δραστηριότητα</th>' +
		'</tr></thead><tbody>' + rows + '</tbody></table></div></div>';
}
