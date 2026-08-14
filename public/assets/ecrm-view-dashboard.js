/* Energy CRM — dashboard: the first screen, and the month's standing. */

import { api, esc, fetch, H, viewEl } from '@energy-crm/util';
import { timeAgo } from '@energy-crm/format';

var MONTHS = ['Ιαν', 'Φεβ', 'Μαρ', 'Απρ', 'Μάι', 'Ιουν', 'Ιουλ', 'Αυγ', 'Σεπ', 'Οκτ', 'Νοε', 'Δεκ'];
export function loadDashboard() {
	var view = viewEl('dashboard');
	fetch(api('/dashboard'), { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) { view.innerHTML = dashboardHTML(d); })
		.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα φόρτωσης dashboard.</div></div>'; });
}
function dashboardHTML(d) {
	var c = d.cards || {}, lvl = d.level || {};
	var pct = lvl.next_at ? Math.min(100, Math.round((c.month / lvl.next_at) * 100)) : 0;

	var cards = [
		{ k: 'Σήμερα', v: c.today || 0, cls: 'is-today' },
		{ k: 'Εκκρεμότητα', v: c.pending || 0, cls: 'is-pending' },
		{ k: 'Δρομολογήθηκαν', v: c.routed || 0, cls: 'is-routed' }
	].map(function (x) {
		return '<div class="ecrm-stat ' + x.cls + '"><div class="ecrm-stat__k">' + x.k + '</div><div class="ecrm-stat__v">' + x.v + '</div></div>';
	}).join('');

	var maxM = Math.max.apply(null, (d.monthly || [0]).concat([1]));
	var bars = (d.monthly || []).map(function (val, i) {
		var h = Math.round((val / maxM) * 100);
		return '<div class="ecrm-bar"><div class="ecrm-bar__fill" style="height:' + h + '%" title="' + val + '"></div><div class="ecrm-bar__lbl">' + MONTHS[i] + '</div></div>';
	}).join('');

	var prov = (d.by_provider && d.by_provider.length)
		? '<ul class="ecrm-plist">' + d.by_provider.map(function (p) { return '<li><span>' + esc(p.name || '—') + '</span><strong>' + p.c + '</strong></li>'; }).join('') + '</ul>'
		: '<div class="ecrm-empty">Δεν υπάρχουν αιτήσεις σε κανέναν πάροχο ακόμα.</div>';

	var feed = (d.feed && d.feed.length)
		? '<ul class="ecrm-feed">' + d.feed.map(function (f) {
			return '<li><span class="ecrm-feed__code">' + esc(f.code || '—') + '</span><span class="ecrm-feed__msg">' + esc(f.message || f.type) + '</span><span class="ecrm-feed__time">' + timeAgo(f.created_at) + '</span></li>';
		}).join('') + '</ul>'
		: '<div class="ecrm-empty">Καμία πρόσφατη δραστηριότητα.</div>';

	return '' +
		'<header class="ecrm-head"><div class="ecrm-eyebrow">' + new Date().toLocaleDateString('el-GR', { weekday: 'long', day: 'numeric', month: 'long' }) + '</div>' +
		'<h2 class="ecrm-title">Καλό βράδυ, ' + esc(d.user || '') + ' 👋</h2><p class="ecrm-sub">τα στατιστικά σου</p></header>' +

		'<div class="ecrm-card ecrm-level">' +
		'<div class="ecrm-level__top"><div><div class="ecrm-eyebrow">Επίπεδο μήνα · δικά σου</div>' +
		'<div class="ecrm-level__name">' + esc(lvl.current || 'Χωρίς level') + '</div>' +
		'<div class="ecrm-level__hint">' + (lvl.remaining || 0) + ' αιτήσεις ακόμα για 🥉 ' + esc(lvl.next || '') + '</div></div>' +
		'<div class="ecrm-level__big">' + (c.month || 0) + '<span>αιτήσεις τον μήνα</span></div></div>' +
		'<div class="ecrm-progress"><div class="ecrm-progress__fill" style="width:' + pct + '%"></div></div></div>' +

		'<div class="ecrm-stats">' + cards + '</div>' +

		'<div class="ecrm-cols">' +
		'<div class="ecrm-card"><div class="ecrm-step">Στατιστικά πωλήσεων ανά μήνα</div><div class="ecrm-chart">' + bars + '</div></div>' +
		'<div class="ecrm-card"><div class="ecrm-step">Στατιστικά ανά πάροχο</div>' + prov + '</div>' +
		'</div>' +

		'<div class="ecrm-card"><div class="ecrm-step">Ζωντανή ροή</div>' + feed + '</div>';
}
