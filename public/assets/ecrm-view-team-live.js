/* Energy CRM — team live: who is working right now.
 *
 * Owns a timer, and clearing it is not optional: leaving the interval running
 * after the view is left keeps polling a screen nobody is looking at. */

import { api, esc, fetch, H, viewEl } from '@energy-crm/util';
import { initials, timeAgo, tint } from '@energy-crm/format';

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
		// Best-effort: μία αποτυχημένη ανανέωση απλά αφήνει τον πίνακα στα
		// τελευταία γνωστά νούμερα μέχρι το επόμενο tick των 30".
		fetch(api('/team/live'), { headers: H() }).then(function (r) { return r.json(); })
			.then(function (d) { if (d && d.ok) renderTeamLive(v, d); }).catch(function () {});
	}, 30000);
}
/* (364) «Signature»: ο πίνακας της μέρας. Σκούρα κάρτα με το «σήμερα» της
 * ομάδας, δίπλα «Θέλουν προσοχή» (καταχωρήσεις και εργασίες που περιμένουν),
 * από κάτω όλοι σε κατάταξη της μέρας με μπάρες σήμερα/μήνας. Ίδια δεδομένα
 * και ίδια ανανέωση κάθε 30". docs/UI-TEAMLIVE-FRESH.html, Β. */
function isSignature() {
	var app = document.getElementById('ecrm-app');
	return !!app && app.getAttribute('data-ui') === 'signature';
}

function sigAv(m, size) {
	return '<span class="sig-tlav' + (m.online ? ' is-on' : '') + (size ? ' sig-tlav--' + size : '') + '" style="--h:' + tint(m.name) + '">' + esc(initials(m.name)) + '</span>';
}

function sigPlural(n, one, many) { return n === 1 ? one : many; }

function sigTeamLiveHTML(d) {
	var t = d.totals || {};
	var members = d.members || [];
	var online = members.filter(function (m) { return m.online; }).length;
	var topToday = members.reduce(function (n, m) { return Math.max(n, Number(m.today) || 0); }, 0);
	var topMonth = members.reduce(function (n, m) { return Math.max(n, Number(m.month) || 0); }, 0);

	var needs = members.filter(function (m) { return (m.presale || 0) + (m.open_tasks || 0) > 0; })
		.sort(function (a, b) { return ((b.presale || 0) + (b.open_tasks || 0)) - ((a.presale || 0) + (a.open_tasks || 0)); });
	var needsHTML = needs.length
		? needs.map(function (m) {
			var pills = (m.presale ? '<span class="sig-tlpill">' + m.presale + ' σε καταχώρηση</span>' : '') +
				(m.open_tasks ? '<span class="sig-tlpill">' + m.open_tasks + sigPlural(m.open_tasks, ' εργασία', ' εργασίες') + '</span>' : '');
			return '<div class="sig-tlneed">' + sigAv(m, 'sm') + '<b>' + esc(m.name) + '</b><span class="sig-tlneed__p">' + pills + '</span></div>';
		}).join('')
		: '<p class="sig-tlcalm">Τίποτα δεν περιμένει. Καμία καταχώρηση ή εργασία σε αναμονή.</p>';

	var rows = members.map(function (m) {
		var today = Number(m.today) || 0;
		var month = Number(m.month) || 0;
		var pt = topToday ? Math.round(today / topToday * 100) : 0;
		var pm = topMonth ? Math.round(month / topMonth * 100) : 0;
		return '<div class="sig-tlrow' + (m.online ? ' is-on' : '') + '">' +
			sigAv(m) +
			'<div class="sig-tlrow__who"><b>' + esc(m.name) + '</b><small>' + esc(m.role || '') + (m.is_self ? ' · εσύ' : '') + '</small></div>' +
			'<div class="sig-tlrow__bars" aria-hidden="true"><span class="is-today"><i style="width:' + pt + '%"></i></span><span class="is-month"><i style="width:' + pm + '%"></i></span></div>' +
			'<div class="sig-tlrow__n"><b>' + today + '</b><span>' + month + ' τον μήνα</span></div>' +
			'<div class="sig-tlrow__last">' + (m.online ? 'online' : (m.last ? esc(timeAgo(m.last)) : '—')) + '</div>' +
		'</div>';
	}).join('');

	return '<header class="sig-cuhead"><div><h2>Ομάδα τώρα</h2><p>Ανανεώνεται μόνο του κάθε 30″</p></div>' +
			'<span class="sig-tllive"><i></i>' + online + ' online' + (d.ts ? ' · ' + esc(d.ts) : '') + '</span></header>' +
		'<div class="sig-tlhero">' +
			'<div class="sig-tlday"><small>ΣΗΜΕΡΑ</small><div class="sig-tlday__big">' + (t.today || 0) + '</div>' +
				'<p>' + sigPlural(t.today || 0, 'αίτηση', 'αιτήσεις') + ' από την ομάδα · ' + online + ' από ' + members.length + ' online</p>' +
				'<div class="sig-tlday__row">' +
					'<div><b>' + (t.month || 0) + '</b><span>τον μήνα</span></div>' +
					'<div><b>' + (t.presale || 0) + '</b><span>καταχώρηση</span></div>' +
					'<div><b>' + (t.finalisation || 0) + '</b><span>οριστικοποίηση</span></div>' +
					'<div><b>' + (t.active || 0) + '</b><span>ενεργές</span></div>' +
				'</div></div>' +
			'<div class="sig-tlhelp"><h3>Θέλουν προσοχή</h3>' + needsHTML + '</div>' +
		'</div>' +
		(members.length
			? '<div class="sig-tlboard"><div class="sig-tlboard__key" aria-hidden="true"><span class="is-today">σήμερα</span><span class="is-month">μήνας</span></div>' + rows + '</div>'
			: '<div class="sig-tempty"><b>Δεν υπάρχουν μέλη ομάδας.</b></div>');
}

function renderTeamLive(view, d) {
	if (isSignature()) { view.innerHTML = sigTeamLiveHTML(d); return; }
	var t = d.totals || {};
	var stat = function (label, val, cls) {
		return '<div class="ecrm-tlstat ' + (cls || '') + '"><div class="ecrm-tlstat__n">' + val + '</div><div class="ecrm-tlstat__l">' + label + '</div></div>';
	};
	var cards = '<div class="ecrm-tlstats">' +
		stat('Online τώρα', (t.online || 0), 'is-online') +
		stat('Σήμερα', (t.today || 0)) +
		stat('Αυτόν τον μήνα', (t.month || 0)) +
		stat('Καταχώρηση', (t.presale || 0)) + // (354) Presale + Καταχώρηση
		stat('Οριστικοποίηση', (t.finalisation || 0)) +
		stat('Ενεργές', (t.active || 0)) +
	'</div>';

	var rows = (d.members || []).map(function (m) {
		var dot = m.online ? '<span class="ecrm-tldot is-on" title="Ενεργός τελευταίο 30λεπτο"></span>' : '<span class="ecrm-tldot" title="Ανενεργός"></span>';
		var last = m.last ? timeAgo(m.last) : '—';
		return '<tr>' +
			'<td>' + dot + '<strong>' + esc(m.name) + '</strong>' + (m.is_self ? ' <span class="ecrm-muted">(εσύ)</span>' : '') + '<div class="ecrm-muted ecrm-tlrole">' + esc(m.role) + '</div></td>' +
			'<td class="ecrm-tlnum">' + m.today + '</td>' +
			'<td class="ecrm-tlnum ecrm-col-sec">' + m.month + '</td>' +
			'<td class="ecrm-tlnum">' + (m.presale ? '<span class="ecrm-pillwarn">' + m.presale + '</span>' : '0') + '</td>' +
			'<td class="ecrm-tlnum ecrm-col-sec">' + m.finalisation + '</td>' +
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
		'<th>Μέλος</th><th>Σήμερα</th><th class="ecrm-col-sec">Μήνας</th><th>Καταχώρηση</th><th class="ecrm-col-sec">Οριστ/ση</th><th>Εργασίες</th><th class="ecrm-col-sec">Τελ. δραστηριότητα</th>' +
		'</tr></thead><tbody>' + rows + '</tbody></table></div></div>';
}
