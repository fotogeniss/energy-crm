/* Energy CRM — «Το προφίλ μου» (379).
 *
 * Mockup docs/UI-PROFILE.html (εγκρίθηκε 13/11/2026). Αριστερά ο χρήστης
 * και οι ενότητες, δεξιά η ενότητα. Ό,τι αποθηκεύεται πάει στο
 * POST /me/profile (UserProfile, user meta)· το θέμα και η εμφάνιση
 * περνούν από τους διακόπτες που υπάρχουν ήδη στη σελίδα, ώστε να μην
 * υπάρχουν δύο δρόμοι για το ίδιο πράγμα. Η «Ασφάλεια» (2FA, ενεργές
 * συνδέσεις) έρχεται σε δικό της γύρο.
 */

import { api, esc, fetch, H, providerMark, toast, viewEl } from '@energy-crm/util';
import { qrPosterHtml } from '@energy-crm/qr-poster';

var SECTIONS = [
	{ key: '', label: 'Τα στοιχεία μου' },
	{ key: 'notifications', label: 'Ειδοποιήσεις' },
	{ key: 'look', label: 'Εμφάνιση' },
	{ key: 'work', label: 'Η δουλειά μου' },
	{ key: 'numbers', label: 'Τα νούμερά μου' }
];

var START = [
	['dashboard', 'Αρχική'], ['contracts', 'Συμβάσεις'], ['tasks', 'Εργασίες'],
	['leads', 'Υποψήφιοι'], ['customers', 'Πελάτες'], ['new-contract', 'Νέα αίτηση']
];

var state = { section: '', data: null, providers: null, dash: null, link: null };

export function loadProfile(section) {
	var view = viewEl('profile');
	state.section = SECTIONS.some(function (s) { return s.key === section; }) ? section : '';
	fetch(api('/me/profile'), { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (!d || !d.ok) { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα.</div></div>'; return; }
			state.data = d;
			render(view);
		})
		.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα φόρτωσης.</div></div>'; });
}

function initialsOf(name) {
	var p = String(name || '').trim().split(/\s+/);
	return ((p[0] || '').charAt(0) + (p.length > 1 ? p[p.length - 1].charAt(0) : '')).toUpperCase() || '·';
}

function render(view) {
	var p = state.data.profile;
	var nav = SECTIONS.map(function (s) {
		return '<button type="button" class="sig-pf__nav' + (s.key === state.section ? ' is-on' : '') + '" data-pf-sec="' + esc(s.key) + '">' + esc(s.label) + '</button>';
	}).join('');

	view.innerHTML = '<div class="sig-pf">' +
		'<aside class="sig-pf__side">' +
			'<div class="sig-pf__me"><span class="sig-pf__av">' + esc(initialsOf(p.display_name)) + '</span>' +
			'<b>' + esc(p.display_name) + '</b><small>' + esc(p.email) + '</small>' +
			(p.title ? '<span class="sig-pf__title">' + esc(p.title) + '</span>' : '') + '</div>' +
			'<nav class="sig-pf__navs">' + nav + '</nav>' +
		'</aside>' +
		'<div class="sig-pf__main" data-pf-main></div>' +
	'</div>';

	view.querySelectorAll('[data-pf-sec]').forEach(function (b) {
		b.addEventListener('click', function () {
			var k = b.getAttribute('data-pf-sec');
			try { history.replaceState({ v: 'profile' }, '', '#profile' + (k ? '/' + k : '')); } catch (e) {}
			state.section = k;
			render(view);
		});
	});

	var main = view.querySelector('[data-pf-main]');
	if (state.section === 'notifications') { notificationsHTML(main); return; }
	if (state.section === 'look') { lookHTML(main); return; }
	if (state.section === 'work') { workHTML(main); return; }
	if (state.section === 'numbers') { numbersHTML(main); return; }
	detailsHTML(main);
}

function save(patch, done) {
	return fetch(api('/me/profile'), {
		method: 'POST',
		headers: Object.assign({ 'Content-Type': 'application/json' }, H()),
		body: JSON.stringify(patch)
	})
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (!d || !d.ok) { toast((d && d.error) || 'Δεν αποθηκεύτηκε.', false); return; }
			state.data = d;
			// Ό,τι διαβάζει η σελίδα κατά τη φόρτωση (window.ECRM.prefs) μένει σε συμφωνία.
			if (window.ECRM) {
				window.ECRM.prefs = {
					start_view: d.profile.start_view, scope: d.profile.scope,
					font: d.profile.font, fav_providers: d.profile.fav_providers
				};
			}
			if (done) { done(d); }
		})
		.catch(function () { toast('Σφάλμα δικτύου.', false); });
}

/* ---- 1. Τα στοιχεία μου -------------------------------------------- */

function field(label, key, value, attrs, hint) {
	return '<label class="sig-pf__f"><span>' + esc(label) + '</span>' +
		'<input class="ecrm-input" data-pf="' + esc(key) + '" value="' + esc(value || '') + '" ' + (attrs || '') + '>' +
		(hint ? '<small>' + esc(hint) + '</small>' : '') + '</label>';
}

function detailsHTML(main) {
	var p = state.data.profile;
	main.innerHTML = '<section class="sig-pf__card"><h3>Τα στοιχεία μου</h3><p class="sig-pf__lead">Μπαίνουν στο PDF προσφοράς και στην αφίσα QR.</p>' +
		'<div class="sig-pf__grid">' +
			field('Όνομα', 'first_name', p.first_name, 'maxlength="60" autocomplete="off"') +
			field('Επώνυμο', 'last_name', p.last_name, 'maxlength="60" autocomplete="off"') +
			field('Κινητό', 'phone', p.phone, 'maxlength="30" inputmode="tel" autocomplete="off"') +
			field('Email', 'email', p.email, 'readonly', 'Το αλλάζει ο διαχειριστής (είναι και το όνομα σύνδεσης).') +
			field('Τίτλος', 'title', p.title, 'maxlength="60" placeholder="π.χ. Σύμβουλος ενέργειας" autocomplete="off"') +
		'</div>' +
		'<label class="sig-pf__f"><span>Υπογραφή στα email</span><textarea class="ecrm-input" data-pf="signature" rows="3" maxlength="500">' + esc(p.signature || '') + '</textarea></label>' +
		'<div class="sig-pf__prev" data-pf-prev></div>' +
		'<div class="sig-pf__foot"><button type="button" class="ecrm-btn ecrm-btn--primary" data-pf-save>Αποθήκευση</button></div></section>';

	function preview() {
		var v = function (k) { var el = main.querySelector('[data-pf="' + k + '"]'); return el ? el.value.trim() : ''; };
		var line = [(v('first_name') + ' ' + v('last_name')).trim(), v('phone'), p.email].filter(Boolean).join(' · ');
		main.querySelector('[data-pf-prev]').innerHTML = 'Στο PDF προσφοράς: <b>Σύμβουλος</b> ' + esc(line);
	}
	preview();
	main.querySelectorAll('[data-pf]').forEach(function (el) { el.addEventListener('input', preview); });

	main.querySelector('[data-pf-save]').addEventListener('click', function () {
		var body = {};
		['first_name', 'last_name', 'phone', 'title', 'signature'].forEach(function (k) {
			body[k] = main.querySelector('[data-pf="' + k + '"]').value;
		});
		if (!body.first_name.trim() || !body.last_name.trim()) { toast('Το όνομα και το επώνυμο δεν μένουν κενά.', false); return; }
		save(body, function () { toast('Αποθηκεύτηκε.'); render(viewEl('profile')); });
	});
}

/* ---- 3. Ειδοποιήσεις ------------------------------------------------ */

function switchHTML(key, on, label, sub) {
	return '<div class="sig-pf__row"><div><b>' + esc(label) + '</b><small>' + esc(sub) + '</small></div>' +
		'<button type="button" class="sig-pf__sw' + (on ? ' is-on' : '') + '" role="switch" aria-checked="' + (on ? 'true' : 'false') + '" data-pf-flag="' + esc(key) + '" aria-label="' + esc(label) + '"></button></div>';
}

function notificationsHTML(main) {
	var p = state.data.profile;
	main.innerHTML = '<section class="sig-pf__card"><h3>Ειδοποιήσεις</h3>' +
		'<p class="sig-pf__lead">Στο καμπανάκι του CRM έρχονται πάντα όλα. Εδώ διαλέγεις ποια θέλεις και με email.</p>' +
		switchHTML('notify_status_email', p.notify_status_email, 'Email όταν μια αίτησή μου θέλει ενέργεια', 'Εκκρεμότητα, οφειλή ή απόρριψη.') +
		switchHTML('notify_digest_email', p.notify_digest_email, 'Ημερήσια σύνοψη με email', 'Κάθε πρωί: ό,τι περιμένει, εργασίες, έγγραφα που λείπουν.') +
		switchHTML('quiet', p.quiet, 'Μην με ενοχλείς', 'Χωρίς email 21:00–08:00 και την Κυριακή. Στο CRM μαζεύονται κανονικά.') +
		'</section>';
	main.querySelectorAll('[data-pf-flag]').forEach(function (b) {
		b.addEventListener('click', function () {
			var k = b.getAttribute('data-pf-flag');
			var patch = {};
			patch[k] = !b.classList.contains('is-on');
			save(patch, function () { notificationsHTML(main); toast('Αποθηκεύτηκε.'); });
		});
	});
}

/* ---- 4. Εμφάνιση ---------------------------------------------------- */

function segHTML(attr, items, current) {
	return '<span class="sig-pf__seg" role="group">' + items.map(function (it) {
		var on = it[0] === current;
		return '<button type="button" ' + attr + '="' + esc(it[0]) + '" class="' + (on ? 'is-on' : '') + '" aria-pressed="' + (on ? 'true' : 'false') + '">' + esc(it[1]) + '</button>';
	}).join('') + '</span>';
}

function lookHTML(main) {
	var app = document.getElementById('ecrm-app');
	var root = app ? app.closest('.ecrm') || app : null;
	var theme = root ? root.getAttribute('data-theme') : 'light';
	var p = state.data.profile;
	var collapsed = app && app.getAttribute('data-collapsed') === '1';

	main.innerHTML = '<section class="sig-pf__card"><h3>Εμφάνιση</h3><p class="sig-pf__lead">Ισχύουν σε κάθε συσκευή που μπαίνεις.</p>' +
		'<div class="sig-pf__row"><div><b>Θέμα</b><small>Ανοιχτό ή σκούρο.</small></div>' + segHTML('data-pf-theme', [['light', 'Ανοιχτό'], ['dark', 'Σκούρο']], theme) + '</div>' +
		(state.data.can_ui
			? '<div class="sig-pf__row"><div><b>Εμφάνιση CRM</b><small>Η νέα ή η κλασική.</small></div>' + segHTML('data-pf-ui', [['signature', 'Νέα'], ['classic', 'Κλασική']], state.data.ui) + '</div>'
			: '') +
		'<div class="sig-pf__row"><div><b>Μέγεθος γραμμάτων</b><small>Για όσους κουράζονται στην οθόνη.</small></div>' + segHTML('data-pf-font', [['normal', 'Κανονικό'], ['large', 'Μεγάλο']], p.font) + '</div>' +
		'<div class="sig-pf__row is-desk"><div><b>Στενή πλαϊνή στήλη</b><small>Μόνο εικονίδια στο μενού, περισσότερος χώρος. Σε αυτόν τον υπολογιστή.</small></div>' +
			'<button type="button" class="sig-pf__sw' + (collapsed ? ' is-on' : '') + '" role="switch" aria-checked="' + (collapsed ? 'true' : 'false') + '" data-pf-collapse aria-label="Στενή πλαϊνή στήλη"></button></div>' +
		'</section>';

	main.querySelectorAll('[data-pf-theme]').forEach(function (b) {
		b.addEventListener('click', function () {
			// Ο ίδιος διακόπτης της μπάρας: αποθηκεύει και αλλάζει χρώματα.
			var want = b.getAttribute('data-pf-theme');
			var now = root ? root.getAttribute('data-theme') : '';
			var sw = document.querySelector('[data-theme-toggle]');
			if (want !== now && sw) { sw.click(); }
			setTimeout(function () { lookHTML(main); }, 50);
		});
	});
	main.querySelectorAll('[data-pf-ui]').forEach(function (b) {
		b.addEventListener('click', function () {
			if (b.getAttribute('data-pf-ui') === state.data.ui) { return; }
			var sw = document.querySelector('[data-ui-toggle]');
			if (sw) { sw.click(); }
		});
	});
	main.querySelectorAll('[data-pf-font]').forEach(function (b) {
		b.addEventListener('click', function () {
			var f = b.getAttribute('data-pf-font');
			if (app) { app.setAttribute('data-fs', f); }
			save({ font: f }, function () { lookHTML(main); });
		});
	});
	var col = main.querySelector('[data-pf-collapse]');
	col.addEventListener('click', function () {
		var btn = document.querySelector('[data-collapse]');
		if (btn) { btn.click(); }
		lookHTML(main);
	});
}

/* ---- 5. Η δουλειά μου ---------------------------------------------- */

function workHTML(main) {
	var p = state.data.profile;

	main.innerHTML = '<section class="sig-pf__card"><h3>Η δουλειά μου</h3><p class="sig-pf__lead">Για να ξεκινάς πιο γρήγορα. Ισχύουν από την επόμενη φόρτωση της σελίδας.</p>' +
		'<div class="sig-pf__row is-wrap"><div><b>Πρώτη οθόνη</b><small>Τι ανοίγει όταν μπαίνεις.</small></div>' + segHTML('data-pf-start', START, p.start_view) + '</div>' +
		'<div class="sig-pf__row"><div><b>Οι λίστες ξεκινούν με</b><small>Συμβάσεις, Πελάτες, Εργασίες.</small></div>' +
			segHTML('data-pf-scope', [['own', 'Δικά μου'], ['team', 'Ομάδας']], p.scope || 'own') + '</div>' +
		'<div class="sig-pf__row is-block"><div><b>Αγαπημένοι πάροχοι</b><small>Μπαίνουν πρώτοι στη «Νέα αίτηση».</small></div><div class="sig-pf__chips" data-pf-favs><span class="sig-pf__muted">Φόρτωση…</span></div></div>' +
		'<div class="sig-pf__row is-block"><div><b>Ο σύνδεσμός μου για υποψηφίους</b><small>Όποιος τον ανοίξει αφήνει στοιχεία και έρχεται σε σένα.</small></div><div data-pf-link>' +
			'<button type="button" class="ecrm-btn ecrm-btn--ghost" data-pf-showlink>Δείξε τον σύνδεσμο</button></div></div>' +
		'</section>';

	main.querySelectorAll('[data-pf-start]').forEach(function (b) {
		b.addEventListener('click', function () { save({ start_view: b.getAttribute('data-pf-start') }, function () { workHTML(main); toast('Αποθηκεύτηκε.'); }); });
	});
	main.querySelectorAll('[data-pf-scope]').forEach(function (b) {
		b.addEventListener('click', function () { save({ scope: b.getAttribute('data-pf-scope') }, function () { workHTML(main); toast('Αποθηκεύτηκε.'); }); });
	});
	favourites(main);
	main.querySelector('[data-pf-showlink]').addEventListener('click', function () { showLink(main); });
	if (state.link) { showLink(main); }
}

function favourites(main) {
	var box = main.querySelector('[data-pf-favs]');
	function paint() {
		var favs = state.data.profile.fav_providers || [];
		var list = state.providers || [];
		if (!list.length) { box.innerHTML = '<span class="sig-pf__muted">Δεν σου έχει δοθεί κανένας πάροχος ακόμα.</span>'; return; }
		box.innerHTML = list.map(function (pr) {
			var on = favs.indexOf(+pr.id) >= 0;
			return '<button type="button" class="sig-pf__chip' + (on ? ' is-on' : '') + '" data-pf-fav="' + esc(String(pr.id)) + '" aria-pressed="' + (on ? 'true' : 'false') + '">' +
				providerMark(pr.name, pr.logo_url) + '<span>' + esc(pr.name) + '</span></button>';
		}).join('');
		box.querySelectorAll('[data-pf-fav]').forEach(function (b) {
			b.addEventListener('click', function () {
				var id = +b.getAttribute('data-pf-fav');
				var cur = (state.data.profile.fav_providers || []).slice();
				var i = cur.indexOf(id);
				if (i >= 0) { cur.splice(i, 1); } else { cur.push(id); }
				save({ fav_providers: cur }, paint);
			});
		});
	}
	if (state.providers) { paint(); return; }
	fetch(api('/providers'), { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) { state.providers = (d && d.providers) || []; paint(); })
		.catch(function () { box.innerHTML = '<span class="sig-pf__muted">Δεν φόρτωσαν οι πάροχοι.</span>'; });
}

function showLink(main) {
	var box = main.querySelector('[data-pf-link]');
	function paint() {
		var l = state.link;
		box.innerHTML = '<div class="sig-pf__link"><input class="ecrm-input" value="' + esc(l.url) + '" readonly data-pf-url aria-label="Ο σύνδεσμός μου">' +
			'<button type="button" class="ecrm-btn ecrm-btn--ghost" data-pf-copy>Αντιγραφή</button>' +
			'<button type="button" class="ecrm-btn ecrm-btn--ghost" data-pf-qr>Αφίσα QR</button></div>';
		box.querySelector('[data-pf-copy]').addEventListener('click', function () {
			var inp = box.querySelector('[data-pf-url]');
			inp.select();
			var ok = false;
			try { ok = document.execCommand('copy'); } catch (e) {}
			if (navigator.clipboard) { navigator.clipboard.writeText(l.url).then(function () { toast('Αντιγράφηκε.'); }, function () { if (ok) { toast('Αντιγράφηκε.'); } }); }
			else if (ok) { toast('Αντιγράφηκε.'); }
		});
		box.querySelector('[data-pf-qr]').addEventListener('click', function () {
			// Ίδια αφίσα με τους Υποψήφιους (344): ο σύνδεσμος με via=qr.
			var target = l.url + (l.url.indexOf('?') >= 0 ? '&' : '?') + 'via=qr';
			var page = URL.createObjectURL(new Blob([qrPosterHtml(target, { name: l.name || '', company: l.company || '' })], { type: 'text/html' }));
			var win = window.open(page, '_blank');
			setTimeout(function () { URL.revokeObjectURL(page); }, 60000);
			if (!win) { toast('Ο browser μπλόκαρε το νέο παράθυρο. Επίτρεψέ το και ξαναπάτα.', false); }
		});
	}
	if (state.link) { paint(); return; }
	fetch(api('/intake/link'), { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (res) {
			if (!res || !res.ok || !res.url) { toast('Δεν ήταν δυνατή η δημιουργία συνδέσμου.', false); return; }
			state.link = { url: res.url, name: res.name || '', company: res.company || '' };
			paint();
		})
		.catch(function () { toast('Σφάλμα δικτύου.', false); });
}

/* ---- 6. Τα νούμερά μου --------------------------------------------- */

function numbersHTML(main) {
	main.innerHTML = '<section class="sig-pf__card"><h3>Τα νούμερά μου</h3><p class="sig-pf__lead">Αυτός ο μήνας. Μόνο ανάγνωση.</p><div data-pf-nums><span class="sig-pf__muted">Φόρτωση…</span></div></section>';
	fetch(api('/dashboard'), { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			var box = main.querySelector('[data-pf-nums]');
			if (!box) { return; }
			var t = (d && d.tiles) || {};
			var lvl = (d && d.level) || {};
			var tg = d && d.target;
			var stat = function (label, value, extra) {
				return '<div class="sig-pf__stat"><small>' + esc(label) + '</small><b>' + esc(value) + '</b>' + (extra || '') + '</div>';
			};
			var bar = tg && tg.target > 0
				? '<span class="sig-pf__bar"><i style="width:' + esc(String(Math.min(100, Math.round(100 * tg.done / tg.target)))) + '%"></i></span>'
				: '';
			var money = t.closed_month_commission != null
				? stat('Προμήθειες μήνα', '€' + Number(t.closed_month_commission || 0).toLocaleString('el-GR', { maximumFractionDigits: 0 }))
				: stat('Ανοιχτές αιτήσεις', String(Number(t.open) || 0));
			box.innerHTML = '<div class="sig-pf__stats">' +
				stat('Κλεισμένες μήνα', String(Number(t.closed_month) || 0)) +
				(tg && tg.target > 0 ? stat('Στόχος μήνα', String(Number(tg.done) || 0) + ' / ' + String(Number(tg.target) || 0), bar) : stat('Στόχος μήνα', 'Χωρίς στόχο')) +
				money + '</div>' +
				'<div class="sig-pf__row"><div><b>Level: ' + esc(lvl.current || 'Χωρίς level') + '</b><small>' +
					(lvl.next ? esc(String(Number(lvl.remaining) || 0)) + ' ακόμα για ' + esc(lvl.next) : 'Το ψηλότερο level') + '</small></div>' +
					'<button type="button" class="ecrm-btn ecrm-btn--ghost" data-pf-goto="dashboard">Αρχική ›</button></div>';
			var go = box.querySelector('[data-pf-goto]');
			if (go) {
				go.addEventListener('click', function () {
					var nav = document.querySelector('.ecrm-nav__item[data-go="dashboard"]');
					if (nav) { nav.click(); }
				});
			}
		})
		.catch(function () { var box = main.querySelector('[data-pf-nums]'); if (box) { box.innerHTML = '<span class="sig-pf__muted">Δεν φόρτωσαν.</span>'; } });
}
