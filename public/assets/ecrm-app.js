import { api, esc, toast } from './ecrm-util.js';

/* Energy CRM — app shell.
 * Client-side routing between views + dashboard & contracts rendering.
 * Depends on ECRM (rest, nonce) and window.ECRMForm (form init). */
(function () {
	'use strict';

	var app = document.getElementById('ecrm-app');
	if (!app || typeof ECRM === 'undefined') return;

	var MONTHS = ['Ιαν', 'Φεβ', 'Μαρ', 'Απρ', 'Μάι', 'Ιουν', 'Ιουλ', 'Αυγ', 'Σεπ', 'Οκτ', 'Νοε', 'Δεκ'];

	function H() { return { 'X-WP-Nonce': ECRM.nonce }; }

	// Hiding a control the user may not use. The server checks again regardless.
	function can(capability) { return !!(ECRM.caps && ECRM.caps[capability]); }

	// Force fresh data: never serve our API GETs from the browser/proxy cache.
	// (Function declaration shadows the global fetch for the whole module.)
	var _origFetch = window.fetch.bind(window);
	function fetch(url, opts) {
		opts = opts || {};
		try {
			var base = ECRM.rest.replace(/\/$/, '');
			if (typeof url === 'string' && url.indexOf(base) === 0) {
				opts.cache = 'no-store';
				opts.headers = Object.assign({ 'Cache-Control': 'no-cache', 'Pragma': 'no-cache' }, opts.headers || {});
			}
		} catch (e) {}
		return _origFetch(url, opts);
	}
	function timeAgo(iso) {
		if (!iso) return '';
		var d = new Date(iso.replace(' ', 'T') + 'Z'), s = (Date.now() - d.getTime()) / 1000;
		if (s < 60) return 'μόλις τώρα';
		if (s < 3600) return Math.floor(s / 60) + 'λ πριν';
		if (s < 86400) return Math.floor(s / 3600) + 'ω πριν';
		return Math.floor(s / 86400) + 'μ πριν';
	}

	// Copy text to clipboard with a fallback for non-secure (HTTP) contexts where
	// navigator.clipboard is unavailable. Returns a Promise<boolean> of success.
	function copyText(text) {
		text = String(text == null ? '' : text);
		if (navigator.clipboard && window.isSecureContext) {
			return navigator.clipboard.writeText(text).then(function () { return true; }, function () { return legacyCopy(text); });
		}
		return Promise.resolve(legacyCopy(text));
	}
	function legacyCopy(text) {
		try {
			var ta = document.createElement('textarea');
			ta.value = text;
			ta.setAttribute('readonly', '');
			ta.style.position = 'fixed';
			ta.style.top = '-1000px';
			ta.style.opacity = '0';
			document.body.appendChild(ta);
			ta.select();
			ta.setSelectionRange(0, ta.value.length);
			var ok = document.execCommand('copy');
			document.body.removeChild(ta);
			return ok;
		} catch (e) { return false; }
	}

	// ---- routing ----------------------------------------------------------
	var loaded = {};
	var VIEWS = ['dashboard','new-contract','leads','contracts','pending','tasks','customers','renewals','network','team','commissions','calc','kb','teamlive','analytics','import'];
	var suppressHash = false;
	function go(view) {
		app.setAttribute('data-view', view);
		app.querySelectorAll('.ecrm-view').forEach(function (v) { v.classList.toggle('is-active', v.getAttribute('data-view') === view); });
		app.querySelectorAll('.ecrm-nav__item[data-go]').forEach(function (b) { b.classList.toggle('is-active', b.getAttribute('data-go') === view); });
		window.scrollTo({ top: 0, behavior: 'smooth' });
		if (!suppressHash && VIEWS.indexOf(view) >= 0) {
			try { if (location.hash.slice(1) !== view) history.pushState({ v: view }, '', '#' + view); } catch (e) {}
		}

		if (view === 'dashboard') loadDashboard();
		if (view === 'contracts') loadContracts();
		if (view === 'team') loadTeam();
		if (view === 'network') loadNetwork();
		if (view === 'import') initImport();
		if (view === 'commissions') loadCommissions();
		if (view === 'analytics') loadAnalytics();
		if (view === 'teamlive') loadTeamLive();
		if (view === 'calc') loadCalc();
		if (view === 'kb') loadKB();
		if (view === 'leads') loadLeads();
		if (view === 'customers') loadCustomers();
		if (view === 'pending') loadPending();
		if (view === 'tasks') loadTasks();
		if (view === 'renewals') loadRenewals();
		if (view === 'new-contract' && !loaded.form) {
			var formRoot = app.querySelector('.ecrm-view[data-view="new-contract"] .ecrm-form');
			if (formRoot && window.ECRMForm) { window.ECRMForm.init(formRoot); loaded.form = true; }
		}
	}
	app.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-go]');
		if (!btn) return;
		e.preventDefault();
		var g = btn.getAttribute('data-go');
		// Clicking the sidebar "Νέα Σύμβαση" starts a fresh form.
		if (g === 'new-contract' && btn.classList.contains('ecrm-nav__item') && window.ECRMForm && window.ECRMForm.reset) {
			window.ECRMForm.reset();
		}
		go(g);
	});

	// Sidebar collapse (persisted).
	try { if (localStorage.getItem('ecrm_sidebar') === '1') app.setAttribute('data-collapsed', '1'); } catch (e) {}
	var collapseBtn = app.querySelector('[data-collapse]');
	if (collapseBtn) collapseBtn.addEventListener('click', function () {
		var on = app.getAttribute('data-collapsed') === '1';
		app.setAttribute('data-collapsed', on ? '0' : '1');
		try { localStorage.setItem('ecrm_sidebar', on ? '0' : '1'); } catch (e) {}
	});

	// ---- mobile nav drawer (off-canvas) ----------------------------------
	var mq = window.matchMedia('(max-width: 860px)');
	function closeMob() { app.removeAttribute('data-mobnav'); }
	var mobToggle = app.querySelector('[data-mobnav-toggle]');
	if (mobToggle) mobToggle.addEventListener('click', function () {
		app.setAttribute('data-mobnav', app.getAttribute('data-mobnav') === '1' ? '0' : '1');
	});
	app.querySelectorAll('[data-mobnav-close]').forEach(function (b) { b.addEventListener('click', closeMob); });
	app.querySelectorAll('.ecrm-nav__item[data-go]').forEach(function (b) {
		b.addEventListener('click', function () { if (mq.matches) closeMob(); });
	});

	// ---- global search (topbar) ------------------------------------------
	var gEl = app.querySelector('[data-gsearch]');
	var gRes = app.querySelector('[data-gsearch-results]');
	var gT;
	if (gEl && gRes) {
		function gRender(list) {
			if (!list.length) { gRes.innerHTML = '<div class="ecrm-gsearch__empty">Κανένα αποτέλεσμα.</div>'; gRes.hidden = false; return; }
			gRes.innerHTML = list.map(function (r) {
				return '<button type="button" class="ecrm-gsearch__row" data-gid="' + r.id + '">' +
					'<span class="ecrm-gsearch__main">' + esc(r.customer) + (r.afm ? ' · ' + esc(r.afm) : '') + '</span>' +
					'<span class="ecrm-gsearch__meta">' + esc(r.code || '') + (r.provider ? ' · ' + esc(r.provider) : '') +
					' <span class="ecrm-badge ecrm-badge--' + esc(r.status) + '">' + esc(r.status_label) + '</span></span></button>';
			}).join('');
			gRes.hidden = false;
			gRes.querySelectorAll('[data-gid]').forEach(function (b) {
				b.addEventListener('click', function () {
					var id = +this.getAttribute('data-gid');
					gRes.hidden = true; gEl.value = '';
					if (typeof go === 'function') go('contracts');
					setTimeout(function () { openDetail(id); }, 50);
				});
			});
		}
		gEl.addEventListener('input', function () {
			var q = this.value.trim();
			clearTimeout(gT);
			if (q.length < 2) { gRes.hidden = true; return; }
			gT = setTimeout(function () {
				fetch(api('/search') + '?q=' + encodeURIComponent(q), { headers: H() })
					.then(function (r) { return r.json(); })
					.then(function (d) { if (d && d.ok) gRender(d.results || []); })
					.catch(function () {});
			}, 250);
		});
		document.addEventListener('click', function (e) {
			if (!gEl.contains(e.target) && !gRes.contains(e.target)) gRes.hidden = true;
		});
	}

	// ---- notifications bell ----------------------------------------------
	var bellBtn = app.querySelector('[data-bell]');
	var bellPanel = app.querySelector('[data-bellpanel]');
	var bellBadge = app.querySelector('[data-bell-badge]');

	function loadBell() {
		if (!bellBtn) return;
		fetch(api('/notifications'), { headers: H() })
			.then(function (r) { return r.json(); })
			.then(function (d) {
				if (!d || !d.ok) return;
				renderBell(d);
			})
			.catch(function () {});
	}

	function renderBell(d) {
		var stale = d.stale || 0;
		var unread = d.unread || 0;
		if (bellBadge) {
			var total = stale + unread;
			if (total > 0) { bellBadge.hidden = false; bellBadge.textContent = total > 99 ? '99+' : total; }
			else { bellBadge.hidden = true; }
		}
		if (!bellPanel) return;
		var notifs = (d.notifs || []);
		var rows = (d.rows || []);
		var html = '';

		if (notifs.length) {
			html += '<div class="ecrm-bellpanel__head"><b>Ειδοποιήσεις</b><span>' + (unread ? unread + ' νέες' : '') + '</span></div>';
			html += '<div class="ecrm-bellpanel__list">' + notifs.slice(0, 10).map(function (n) {
				return '<button type="button" class="ecrm-bellitem' + (n.read ? '' : ' is-stale') + '" data-bell-open="' + n.contract_id + '">' +
					'<span class="ecrm-bellitem__dot ecrm-badge--processing"></span>' +
					'<span class="ecrm-bellitem__main"><span class="ecrm-bellitem__name">' + esc(n.title) + '</span>' +
					'<span class="ecrm-bellitem__meta">' + esc(n.body || '') + '</span></span></button>';
			}).join('') + '</div>';
		}

		html += '<div class="ecrm-bellpanel__head"><b>Εκκρεμότητες</b><span>' + (stale ? stale + ' πάνω από ' + d.threshold + ' ημέρες' : 'όλα εντάξει') + '</span></div>';
		if (!rows.length) {
			html += '<div class="ecrm-bellpanel__empty">Καμία ανοιχτή σύμβαση 🎉</div>';
		} else {
			html += '<div class="ecrm-bellpanel__list">' + rows.slice(0, 12).map(function (r) {
				return '<button type="button" class="ecrm-bellitem' + (r.stale ? ' is-stale' : '') + '" data-bell-open="' + r.id + '">' +
					'<span class="ecrm-bellitem__dot ecrm-badge--' + esc(r.status) + '"></span>' +
					'<span class="ecrm-bellitem__main"><span class="ecrm-bellitem__name">' + esc(r.customer) + '</span>' +
					'<span class="ecrm-bellitem__meta">' + esc(r.code || '') + ' · ' + esc(r.status_label) + '</span></span>' +
					'<span class="ecrm-bellitem__age">' + r.age_days + 'η</span></button>';
			}).join('') + '</div>';
		}
		bellPanel.innerHTML = html;
		bellPanel.querySelectorAll('[data-bell-open]').forEach(function (b) {
			b.addEventListener('click', function () { bellPanel.hidden = true; openDetail(this.getAttribute('data-bell-open')); });
		});

		// Mark event notifications as read only while the panel is actually open.
		if (unread > 0 && bellPanel && !bellPanel.hidden) {
			fetch(api('/notifications/read'), { method: 'POST', headers: H() })
				.then(function () { if (bellBadge) { if (stale > 0) { bellBadge.textContent = stale > 99 ? '99+' : stale; } else { bellBadge.hidden = true; } } })
				.catch(function () {});
		}
	}

	if (bellBtn) {
		bellBtn.addEventListener('click', function (e) {
			e.stopPropagation();
			if (bellPanel) { bellPanel.hidden = !bellPanel.hidden; if (!bellPanel.hidden) loadBell(); }
		});
		document.addEventListener('click', function (e) {
			if (bellPanel && !bellPanel.hidden && !bellPanel.contains(e.target) && e.target !== bellBtn && !bellBtn.contains(e.target)) {
				bellPanel.hidden = true;
			}
		});
		loadBell();
		setInterval(function () { if (bellPanel && bellPanel.hidden) { loadBell(); } }, 60000);
	}

	// ---- dashboard --------------------------------------------------------
	function loadDashboard() {
		var view = app.querySelector('.ecrm-view[data-view="dashboard"]');
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

	// ---- contracts list ---------------------------------------------------
	var contractsState = { status: '', q: '', scope: 'own', page: 1, pageSize: 12 };

	function up(str) { try { return String(str).toLocaleUpperCase('el').normalize('NFD').replace(/[\u0300-\u036f]/g, ''); } catch (e) { return String(str).toUpperCase(); } }
	function energyLabel(t) { return t === 'gas' ? 'Φυσικό Αέριο' : (t === 'mobile' ? 'Κινητή Τηλεφωνία' : 'Ηλεκτρισμός'); }
	function svgIcon(name) {
		var p = {
			phone: '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3.1-8.6A2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.3 1.8.6 2.7a2 2 0 0 1-.5 2.1L8 9.6a16 16 0 0 0 6 6l1.1-1.1a2 2 0 0 1 2.1-.5c.9.3 1.8.5 2.7.6a2 2 0 0 1 1.7 2z"/>',
			viber: '<path d="M12 3c4.5 0 8 3.2 8 7.4 0 4.2-3.5 7.4-8 7.4-.7 0-1.4-.1-2-.2L5 19l1-3.2C4.7 14.5 4 12.6 4 10.4 4 6.2 7.5 3 12 3z"/><path d="M9.5 8c.6 1.8 2 3.2 3.8 3.8"/>',
			mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/>',
			edit: '<path d="M11 4H5a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h13a2 2 0 0 0 2-2v-6"/><path d="M18.5 2.5a2.1 2.1 0 0 1 3 3L12 15l-4 1 1-4z"/>',
			trash: '<path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>'
		};
		return '<svg class="ecrm-bico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (p[name] || '') + '</svg>';
	}
	function fmtDate(iso) {
		if (!iso) return '';
		var d = new Date(iso.replace(' ', 'T') + 'Z');
		var p = function (n) { return (n < 10 ? '0' : '') + n; };
		return p(d.getDate()) + '/' + p(d.getMonth() + 1) + '/' + d.getFullYear();
	}
	function initials(name) {
		var parts = (name || '').trim().split(/\s+/).filter(Boolean);
		var s = (parts[0] ? parts[0][0] : '') + (parts[1] ? parts[1][0] : '');
		return (s || (name || '?')[0] || '?').toUpperCase();
	}
	function tint(str) {
		var h = 0; str = str || '';
		for (var i = 0; i < str.length; i++) { h = (h * 31 + str.charCodeAt(i)) % 360; }
		return h;
	}

	function loadContracts() {
		var view = app.querySelector('.ecrm-view[data-view="contracts"]');
		var url = api('/contracts') + '?status=' + encodeURIComponent(contractsState.status) + '&q=' + encodeURIComponent(contractsState.q) + '&scope=' + encodeURIComponent(contractsState.scope);
		fetch(url, { headers: H() })
			.then(function (r) { return r.json(); })
			.then(function (d) { renderContracts(view, d); })
			.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα φόρτωσης.</div></div>'; });
	}

	var expandCache = {};
	function loadExpand(id, view) {
		var box = view.querySelector('[data-exppanel="' + id + '"]');
		if (!box) return;
		if (expandCache[id]) { box.innerHTML = expandPanel(expandCache[id], view); bindExpand(box, expandCache[id], view); return; }
		fetch(api('/contracts/' + id), { headers: H() })
			.then(function (r) { return r.json(); })
			.then(function (d) {
				if (!d || !d.ok) { box.innerHTML = '<div class="ecrm-empty">Σφάλμα φόρτωσης.</div>'; return; }
				expandCache[id] = d.contract || d;
				box.innerHTML = expandPanel(expandCache[id], view);
				bindExpand(box, expandCache[id], view);
			})
			.catch(function () { box.innerHTML = '<div class="ecrm-empty">Σφάλμα δικτύου.</div>'; });
	}

	function expandPanel(c, view) {
		var statuses = (window.ECRM && ECRM.statuses) || {};
		var stLabel = statuses[c.status] || c.status;
		var name = c.company_name || ((c.first_name || '') + ' ' + (c.last_name || '')).trim() || '—';
		var mobile = c.mobile || c.phone || '';
		var dl = function (l, v) { return '<div class="ecrm-tr"><span>' + l + '</span><b>' + (v ? esc(v) : '—') + '</b></div>'; };

		var banner =
			'<div class="ecrm-expbanner ecrm-expbanner--' + esc(c.status) + '">' +
			'<span class="ecrm-expbanner__ico">' + (c.status === 'draft' ? '🗎' : (c.status === 'cancelled' || c.status === 'terminated' ? '✕' : '!')) + '</span>' +
			'<div><div class="ecrm-expbanner__eyebrow">' + esc(up(stLabel)) + '</div>' +
			'<div class="ecrm-expbanner__title">' + esc(stLabel) + ' για ' + esc(name) + '</div>' +
			'<div class="ecrm-expbanner__sub">Δημιουργήθηκε: <b>' + fmtDate(c.created_at) + '</b> · Αριθμός συμβολαίου: <b>' + esc(c.code || '—') + '</b></div></div></div>';

		var cards =
			'<div class="ecrm-expcards">' +
			'<div class="ecrm-card"><div class="ecrm-step">Στοιχεία πελάτη</div>' + dl('Ονοματεπώνυμο', name) + dl('ΑΦΜ', c.afm) + dl('Κινητό', mobile) + '</div>' +
			'<div class="ecrm-card"><div class="ecrm-step">Παροχή</div>' + dl('ΗΚΑΣΠ/Παροχή', c.supply_number) + dl('Τιμολόγιο', c.invoice_code) + dl('Πάροχος', c.provider_name) + '</div>' +
			'<div class="ecrm-card"><div class="ecrm-step">Tracking</div>' + dl('Ημ. δημιουργίας', fmtDate(c.created_at)) + dl('Ημ. Thalis', '') + dl('Ημ. Εκπροσώπησης', '') + dl('Ημ. Τερματισμού', '') + dl('Τελ. ενημέρωση', fmtDate(c.updated_at)) + '</div>' +
			'</div>';

		var phone = mobile || c.phone || '';
		var email = c.email || '';
		var viberNum = (phone || '').replace(/[^0-9+]/g, '');
		if (viberNum && viberNum.charAt(0) !== '+') { viberNum = '+30' + viberNum.replace(/^0+/, ''); }
		var actions = '<div class="ecrm-expactions">' +
			'<button type="button" class="ecrm-btn ecrm-btn--primary ecrm-btn--sm" data-exp-edit>' + svgIcon('edit') + ' Προβολή / Επεξεργασία</button>' +
			(phone ? '<a class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" href="tel:' + esc(phone) + '">' + svgIcon('phone') + ' Κάλεσε</a>' : '') +
			(viberNum ? '<a class="ecrm-btn ecrm-btn--viber ecrm-btn--sm" href="viber://chat?number=' + encodeURIComponent(viberNum) + '" target="_blank" rel="noopener">' + svgIcon('viber') + ' Viber</a>' : '') +
			(email ? '<a class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" href="mailto:' + esc(email) + '">' + svgIcon('mail') + ' Email</a>' : '') +
			(c.status === 'draft' ? '<button type="button" class="ecrm-btn ecrm-btn--danger ecrm-btn--sm" data-exp-del>' + svgIcon('trash') + ' Διαγραφή</button>' : '') +
			'</div>';

		return banner + cards + actions;
	}

	function bindExpand(box, c, view) {
		var edit = box.querySelector('[data-exp-edit]');
		if (edit) edit.addEventListener('click', function () { openEdit(c); });
		var del = box.querySelector('[data-exp-del]');
		if (del) del.addEventListener('click', function () {
			if (!confirm('Διαγραφή πρόχειρης σύμβασης; Δεν αναιρείται.')) return;
			var b = this; b.disabled = true;
			fetch(api('/contracts/' + c.id), { method: 'DELETE', headers: H() })
				.then(function (r) { return r.json(); })
				.then(function (d) { if (d && d.ok) { toast('Διαγράφηκε.'); delete expandCache[c.id]; loadContracts(); } else { toast((d && d.error) || 'Αποτυχία.', false); b.disabled = false; } })
				.catch(function () { toast('Σφάλμα δικτύου.', false); b.disabled = false; });
		});
	}

	function renderContracts(view, d) {
		var statuses = d.statuses || {}, counts = d.counts || {};
		var allRows = d.rows || [];

		// status tabs — show ALL statuses with counts + colour dots (like PSS)
		var tabs = '<button type="button" class="ecrm-tab' + (contractsState.status === '' ? ' is-on' : '') + '" data-status=""><span class="ecrm-tabdot ecrm-tabdot--all"></span>ΟΛΕΣ <b>' + (counts.all || 0) + '</b></button>';
		Object.keys(statuses).forEach(function (st) {
			tabs += '<button type="button" class="ecrm-tab' + (contractsState.status === st ? ' is-on' : '') + '" data-status="' + st + '"><span class="ecrm-tabdot ecrm-tabdot--' + esc(st) + '"></span>' + esc(up(statuses[st])) + ' <b>' + (counts[st] || 0) + '</b></button>';
		});

		// client-side pagination
		var total = allRows.length;
		var pages = Math.max(1, Math.ceil(total / contractsState.pageSize));
		if (contractsState.page > pages) contractsState.page = pages;
		var startI = (contractsState.page - 1) * contractsState.pageSize;
		var rowsPage = allRows.slice(startI, startI + contractsState.pageSize);

		// Ownership only matters when more than one partner is in view.
		var showOwner = contractsState.scope === 'team';

		var body = rowsPage.map(function (r) {
			var name = r.company_name || ((r.first_name || '') + ' ' + (r.last_name || '')).trim() || '—';
			var stLabel = statuses[r.status] || r.status;
			var prov = r.provider_logo
				? '<img class="ecrm-cell-logo" src="' + esc(r.provider_logo) + '" alt="">'
				: '<span class="ecrm-cell-mark" style="--h:' + tint(r.provider_name || r.provider_slug || '') + '">' + esc(initials(r.provider_name || '·')) + '</span>';
			var cust = '<span class="ecrm-cell-mark ecrm-cell-mark--cust" style="--h:' + tint(name) + '">' + esc(initials(name)) + '</span>';
			var inv = r.invoice_code ? '<span class="ecrm-tariff">' + esc(r.invoice_code) + '</span>' : '<span class="ecrm-muted">—</span>';
			return '<tr class="ecrm-rowlink" data-id="' + r.id + '">' +
				'<td class="ecrm-checkcol"><input type="checkbox" class="ecrm-rowcheck" data-cid="' + r.id + '"></td>' +
				'<td class="ecrm-expandcol"><button type="button" class="ecrm-expand" data-expand="' + r.id + '" aria-label="Λεπτομέρειες">+</button></td>' +
				'<td><span class="ecrm-cell-prov">' + prov + '<span>' + esc(r.provider_name || '—') + '</span></span></td>' +
				'<td>' + esc(r.program_name || '—') + '</td>' +
				'<td><span class="ecrm-cell-cust">' + cust + '<span>' + esc(name) + '</span></span></td>' +
				(showOwner ? '<td><span class="ecrm-cell-owner">' + esc(r.partner_name || '—') + '</span></td>' : '') +
				'<td class="ecrm-mono">' + esc(r.supply_number || '') + '</td>' +
				'<td>' + inv + '</td>' +
				'<td><span class="ecrm-badge ecrm-badge--' + esc(r.status) + '">' + esc(stLabel) + '</span></td>' +
				'<td class="ecrm-cell-date"><div>' + fmtDate(r.updated_at) + '</div><div class="ecrm-muted">' + timeAgo(r.updated_at) + '</div></td>' +
				'</tr>' +
				'<tr class="ecrm-exprow" data-exprow="' + r.id + '" hidden><td class="ecrm-expaccent" data-status="' + esc(r.status) + '"></td><td colspan="' + (showOwner ? 9 : 8) + '"><div class="ecrm-exppanel" data-exppanel="' + r.id + '"><div class="ecrm-loading">Φόρτωση…</div></div></td></tr>';
		}).join('');

		var table = total
			? '<div class="ecrm-bulkbar" data-bulkbar hidden><span class="ecrm-bulkbar__n"><b data-bulk-n>0</b> επιλεγμένες</span>' +
				'<select class="ecrm-input ecrm-input--sm" data-bulk-status><option value="">— Αλλαγή κατάστασης —</option>' +
				Object.keys(statuses).map(function (s) { return '<option value="' + esc(s) + '">' + esc(statuses[s]) + '</option>'; }).join('') +
				'</select><button type="button" class="ecrm-btn ecrm-btn--sm ecrm-btn--primary" data-bulk-apply-status>Εφαρμογή</button>' +
				(contractsState.scope === 'team' ? '<select class="ecrm-input ecrm-input--sm" data-bulk-assign><option value="">— Ανάθεση σε… —</option></select><button type="button" class="ecrm-btn ecrm-btn--sm" data-bulk-apply-assign>Ανάθεση</button>' : '') +
				(can('ecrm_export_data') ? '<button type="button" class="ecrm-btn ecrm-btn--sm ecrm-btn--ghost" data-bulk-export>⤓ Export επιλογής</button>' : '') +
				(can('ecrm_delete_contract') ? '<button type="button" class="ecrm-btn ecrm-btn--sm ecrm-btn--danger" data-bulk-delete>🗑 Διαγραφή</button>' : '') +
				'<button type="button" class="ecrm-btn ecrm-btn--sm ecrm-btn--ghost" data-bulk-clear>Καθαρισμός</button></div>' +
				'<div class="ecrm-tablewrap"><table class="ecrm-table ecrm-table--rich"><thead><tr>' +
				'<th class="ecrm-checkcol"><input type="checkbox" data-checkall></th><th></th><th>Πάροχος</th><th>Πρόγραμμα</th><th>Πελάτης</th>' +
				(showOwner ? '<th>Συνεργάτης</th>' : '') +
				'<th>ΗΚΑΣΠ / Παροχή</th><th>Τιμολόγιο</th><th>Κατάσταση</th><th>Ενημέρωση</th>' +
				'</tr></thead><tbody>' + body + '</tbody></table></div>' +
				'<div class="ecrm-pager"><span>Σύνολο <b>' + total + '</b> συμβάσεις</span>' +
				'<div class="ecrm-pager__nav"><button type="button" class="ecrm-pager__b" data-page="prev"' + (contractsState.page <= 1 ? ' disabled' : '') + '>‹ Προηγούμενη</button>' +
				'<span class="ecrm-pager__pos">Σελίδα ' + contractsState.page + ' από ' + pages + '</span>' +
				'<button type="button" class="ecrm-pager__b" data-page="next"' + (contractsState.page >= pages ? ' disabled' : '') + '>Επόμενη ›</button></div></div>'
			: '<div class="ecrm-empty">Δεν υπάρχουν συμβάσεις' + (contractsState.q ? ' για «' + esc(contractsState.q) + '»' : '') + '.</div>';

		view.innerHTML = '' +
			'<header class="ecrm-head ecrm-head--row"><div><h2 class="ecrm-title">Οι συμβάσεις μου</h2><p class="ecrm-sub">' + total + ' συμβάσεις · κάνε click σε γραμμή για λεπτομέρειες</p></div>' +
			'<div class="ecrm-head__actions">' +
			'<div class="ecrm-scope"><button type="button" class="ecrm-scope__b' + (contractsState.scope==="own"?" is-on":"") + '" data-scope="own">Δικά μου</button><button type="button" class="ecrm-scope__b' + (contractsState.scope==="team"?" is-on":"") + '" data-scope="team">Ομάδας</button></div>' +
			'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-export>⤓ Export Excel</button></div></header>' +
			'<div class="ecrm-card">' +
			'<div class="ecrm-search-row"><div class="ecrm-search"><input type="search" class="ecrm-input" placeholder="Αναζήτηση πελάτη, ΑΦΜ, ΗΚΑΣΠ, αριθμού συμβολαίου…" value="' + esc(contractsState.q) + '" data-search></div>' +
			'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-save-filter>★ Αποθήκευση φίλτρου</button>' +
			'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-toggle-filters>⚙ Φίλτρα</button></div>' +
			'<div class="ecrm-savedfilters" data-savedfilters></div>' +
			'<div class="ecrm-tabs" data-tabs>' + tabs + '</div>' +
			table + '</div>';

		view.querySelectorAll('.ecrm-tab').forEach(function (t) {
			t.addEventListener('click', function () { contractsState.status = this.getAttribute('data-status'); contractsState.page = 1; loadContracts(); });
		});
		var search = view.querySelector('[data-search]');
		if (search) {
			var timer;
			search.addEventListener('input', function () { clearTimeout(timer); var val = this.value; timer = setTimeout(function () { contractsState.q = val; contractsState.page = 1; loadContracts(); }, 350); });
		}
		var ft = view.querySelector('[data-toggle-filters]');
		if (ft) ft.addEventListener('click', function () { var t = view.querySelector('[data-tabs]'); if (t) t.classList.toggle('is-hidden'); });

		// ---- saved filters ----
		var sfBox = view.querySelector('[data-savedfilters]');
		function renderSavedFilters(list) {
			if (!sfBox) return;
			if (!list.length) { sfBox.innerHTML = ''; return; }
			sfBox.innerHTML = '<span class="ecrm-savedfilters__lbl">Αποθηκευμένα:</span>' + list.map(function (f, i) {
				return '<span class="ecrm-savedchip" data-sf="' + i + '"><button type="button" class="ecrm-savedchip__go" data-sfgo="' + i + '">' + esc(f.name) + '</button>' +
					'<button type="button" class="ecrm-savedchip__x" data-sfdel="' + i + '" aria-label="Διαγραφή">×</button></span>';
			}).join('');
			sfBox.querySelectorAll('[data-sfgo]').forEach(function (b) {
				b.addEventListener('click', function () {
					var f = list[+this.getAttribute('data-sfgo')];
					contractsState.status = f.status || ''; contractsState.q = f.q || ''; contractsState.scope = (f.scope === 'team' ? 'team' : 'own'); contractsState.page = 1;
					loadContracts();
				});
			});
			sfBox.querySelectorAll('[data-sfdel]').forEach(function (b) {
				b.addEventListener('click', function () {
					fetch(api('/filters/' + this.getAttribute('data-sfdel')), { method: 'DELETE', headers: H() })
						.then(function (r) { return r.json(); }).then(function (d) { if (d && d.ok) renderSavedFilters(d.filters || []); });
				});
			});
		}
		fetch(api('/filters'), { headers: H() }).then(function (r) { return r.json(); })
			.then(function (d) { if (d && d.ok) renderSavedFilters(d.filters || []); }).catch(function () {});

		var saveBtn = view.querySelector('[data-save-filter]');
		if (saveBtn) saveBtn.addEventListener('click', function () {
			var dflt = (statuses[contractsState.status] || 'Όλες') + (contractsState.q ? ' · ' + contractsState.q : '') + (contractsState.scope === 'team' ? ' · Ομάδας' : '');
			var name = prompt('Όνομα φίλτρου:', dflt);
			if (!name) return;
			fetch(api('/filters'), { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H()),
				body: JSON.stringify({ name: name, status: contractsState.status, q: contractsState.q, scope: contractsState.scope }) })
				.then(function (r) { return r.json(); })
				.then(function (d) { if (d && d.ok) { toast('Αποθηκεύτηκε φίλτρο.'); renderSavedFilters(d.filters || []); } else { toast((d && d.error) || 'Αποτυχία.', false); } })
				.catch(function () { toast('Σφάλμα δικτύου.', false); });
		});

		view.querySelectorAll('.ecrm-rowlink').forEach(function (tr) {
			tr.addEventListener('click', function () { openDetail(this.getAttribute('data-id')); });
		});
		view.querySelectorAll('[data-expand]').forEach(function (b) {
			b.addEventListener('click', function (e) {
				e.stopPropagation();
				var id = this.getAttribute('data-expand');
				var row = view.querySelector('[data-exprow="' + id + '"]');
				if (!row) return;
				row.hidden = !row.hidden;
				this.textContent = row.hidden ? '+' : '×';
				this.classList.toggle('is-open', !row.hidden);
				if (!row.hidden) loadExpand(id, view);
			});
		});
		view.querySelectorAll('[data-page]').forEach(function (b) {
			b.addEventListener('click', function () {
				if (this.disabled) return;
				contractsState.page += (this.getAttribute('data-page') === 'next' ? 1 : -1);
				loadContracts();
			});
		});

		var exportBtn = view.querySelector('[data-export]');
		if (exportBtn) exportBtn.addEventListener('click', function () { openExportModal(); });
		view.querySelectorAll('[data-scope]').forEach(function (b) { b.addEventListener('click', function () { contractsState.scope = this.getAttribute('data-scope'); contractsState.page = 1; loadContracts(); }); });

		// ---- bulk selection & actions ----
		var bar = view.querySelector('[data-bulkbar]');
		function selectedIds() { return Array.prototype.slice.call(view.querySelectorAll('.ecrm-rowcheck:checked')).map(function (c) { return +c.getAttribute('data-cid'); }); }
		function refreshBar() {
			if (!bar) return;
			var ids = selectedIds();
			bar.hidden = ids.length === 0;
			var n = bar.querySelector('[data-bulk-n]'); if (n) n.textContent = ids.length;
		}
		view.querySelectorAll('.ecrm-rowcheck').forEach(function (cb) {
			cb.addEventListener('click', function (e) { e.stopPropagation(); refreshBar(); });
		});
		var checkAll = view.querySelector('[data-checkall]');
		if (checkAll) checkAll.addEventListener('click', function (e) {
			e.stopPropagation();
			var on = this.checked;
			view.querySelectorAll('.ecrm-rowcheck').forEach(function (c) { c.checked = on; });
			refreshBar();
		});
		if (bar) {
			// populate assignee list (team scope)
			var assignSel = bar.querySelector('[data-bulk-assign]');
			if (assignSel) {
				fetch(api('/team'), { headers: H() }).then(function (r) { return r.json(); }).then(function (t) {
					(t && t.members || []).forEach(function (m) { var o = document.createElement('option'); o.value = m.id; o.textContent = m.name; assignSel.appendChild(o); });
				}).catch(function () {});
			}
			function runBulk(body, okMsg) {
				var ids = selectedIds();
				if (!ids.length) { toast('Δεν επιλέχθηκαν συμβάσεις.', false); return; }
				body.ids = ids;
				fetch(api('/contracts/bulk'), { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H()), body: JSON.stringify(body) })
					.then(function (r) { return r.json(); })
					.then(function (d) {
						if (!d || !d.ok) { toast((d && d.error) || 'Αποτυχία.', false); return; }
						if (d.b64) {
							var bin = atob(d.b64), len = bin.length, arr = new Uint8Array(len);
							for (var i = 0; i < len; i++) arr[i] = bin.charCodeAt(i);
							var url = URL.createObjectURL(new Blob([arr], { type: d.mime }));
							var a = document.createElement('a'); a.href = url; a.download = d.filename || 'export.xlsx'; document.body.appendChild(a); a.click(); a.remove();
							setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
							toast('Εξήχθησαν ' + d.count + ' συμβάσεις.');
							return;
						}
						// Nothing moved and the pipeline explained why: lead with
						// that, instead of reporting a success that never happened.
						if (d.notice && !d.updated) { toast(d.notice, false); loadContracts(); return; }

						var msg = okMsg + (d.updated != null ? ' (' + d.updated + ')' : '');
						if (d.notice) msg += ' · ' + d.notice;
						if (d.skipped) msg += ' · παραλείφθηκαν ' + d.skipped + ' (ελλιπή δικαιολογητικά)';
						toast(msg);
						loadContracts();
					})
					.catch(function () { toast('Σφάλμα δικτύου.', false); });
			}
			var bs = bar.querySelector('[data-bulk-apply-status]');
			if (bs) bs.addEventListener('click', function () {
				var v = bar.querySelector('[data-bulk-status]').value;
				if (!v) { toast('Διάλεξε κατάσταση.', false); return; }
				runBulk({ action: 'status', value: v }, 'Ενημερώθηκαν');
			});
			var ba = bar.querySelector('[data-bulk-apply-assign]');
			if (ba) ba.addEventListener('click', function () {
				var v = assignSel ? assignSel.value : '';
				if (!v) { toast('Διάλεξε μέλος.', false); return; }
				runBulk({ action: 'assign', value: +v }, 'Ανατέθηκαν');
			});
			var be = bar.querySelector('[data-bulk-export]');
			if (be) be.addEventListener('click', function () { runBulk({ action: 'export' }, ''); });
			var bd = bar.querySelector('[data-bulk-delete]');
			if (bd) bd.addEventListener('click', function () {
				var ids = selectedIds();
				if (!ids.length) { toast('Δεν επιλέχθηκαν αιτήσεις.', false); return; }
				if (!window.confirm('Διαγραφή ' + ids.length + ' αιτήσεων;\nΗ ενέργεια είναι οριστική και θα διαγράψει και τα σχετικά έγγραφα/υπογραφές.')) return;
				runBulk({ action: 'delete' }, 'Διαγράφηκαν');
			});
			var bc = bar.querySelector('[data-bulk-clear]');
			if (bc) bc.addEventListener('click', function () {
				view.querySelectorAll('.ecrm-rowcheck').forEach(function (c) { c.checked = false; });
				if (checkAll) checkAll.checked = false;
				refreshBar();
			});
		}
	}

	// ---- contract detail --------------------------------------------------
	function openEdit(c) {
		go('new-contract');
		if (window.ECRMForm && window.ECRMForm.edit) {
			// give the view a tick to become visible, then prefill
			setTimeout(function () { window.ECRMForm.edit(c); }, 30);
		}
	}

	function openDetail(id) {
		go('contract-detail');
		var view = app.querySelector('.ecrm-view[data-view="contract-detail"]');
		view.innerHTML = '<div class="ecrm-loading">Φόρτωση…</div>';
		fetch(api('/contracts/' + id), { headers: H() })
			.then(function (r) { return r.json(); })
			.then(function (d) { if (!d || !d.ok) { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">' + esc((d && d.error) || 'Σφάλμα.') + '</div></div>'; return; } renderDetail(view, d); })
			.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα φόρτωσης.</div></div>'; });
	}

	function field(label, val) {
		return '<div class="ecrm-dl"><dt>' + esc(label) + '</dt><dd>' + (val ? esc(val) : '—') + '</dd></div>';
	}

	function filesCard(c) {
		var files = c.files || [];
		var kindLabel = c.doc_kinds || { id_card: 'Ταυτότητα', provider_bill: 'Λογαριασμός', other: 'Έγγραφο' };

		// Required-docs checklist
		var checklist = '';
		if (c.doc_checklist && c.doc_checklist.items && c.doc_checklist.items.length) {
			var ck = c.doc_checklist;
			var rows = ck.items.map(function (it) {
				return '<li class="ecrm-check ' + (it.ok ? 'is-ok' : 'is-missing') + '">' +
					'<span class="ecrm-check__mark">' + (it.ok ? '✓' : '○') + '</span>' +
					'<span>' + esc(it.label) + '</span></li>';
			}).join('');
			var banner = ck.complete
				? '<div class="ecrm-check__note is-ok">Όλα τα δικαιολογητικά παρόντα.</div>'
				: '<div class="ecrm-check__note is-missing">Λείπουν δικαιολογητικά — δεν μπορεί να δρομολογηθεί/ενεργοποιηθεί.</div>';
			checklist = '<div class="ecrm-step">Απαιτούμενα δικαιολογητικά</div><ul class="ecrm-checklist">' + rows + '</ul>' + banner;
		}

		var list;
		if (!files.length) {
			list = '<div class="ecrm-empty">Δεν έχουν επισυναφθεί έγγραφα.</div>';
		} else {
			list = '<div class="ecrm-files">' + files.map(function (f) {
				var thumb = f.is_image && f.url ? '<img src="' + esc(f.url) + '" alt="">' : '<span class="ecrm-file__ext">' + (f.mime === 'application/pdf' ? 'PDF' : 'DOC') + '</span>';
				return '<a class="ecrm-file" href="' + esc(f.url || '#') + '" target="_blank" rel="noopener">' +
					'<span class="ecrm-file__thumb">' + thumb + '</span>' +
					'<span class="ecrm-file__meta"><span class="ecrm-file__name">' + esc(f.filename || 'έγγραφο') + '</span>' +
					'<span class="ecrm-file__kind">' + esc(kindLabel[f.doc_kind] || 'Έγγραφο') + '</span></span></a>';
			}).join('') + '</div>';
		}

		// Inline upload control
		var kindOpts = Object.keys(kindLabel).map(function (k) {
			return '<option value="' + esc(k) + '">' + esc(kindLabel[k]) + '</option>';
		}).join('');
		var upload = '<div class="ecrm-docup" data-docup="' + c.id + '">' +
			'<select class="ecrm-input ecrm-docup__kind" data-docup-kind>' + kindOpts + '</select>' +
			'<input type="file" multiple accept="image/*,application/pdf" data-docup-file>' +
			'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-docup-go>Προσθήκη</button>' +
			'<span class="ecrm-docup__msg" data-docup-msg></span></div>';

		return '<div class="ecrm-card">' + checklist + '<div class="ecrm-step">Έγγραφα</div>' + list + upload + '</div>';
	}

	function renderDetail(view, d) {
		var c = d.contract, statuses = d.statuses || {}, acts = d.activation_types || {};
		var name = c.company_name || ((c.first_name || '') + ' ' + (c.last_name || '')).trim() || '—';
		var energy = energyLabel(c.energy_type);

		var statusOpts = Object.keys(statuses).map(function (s) {
			return '<button type="button" class="ecrm-statuschip ecrm-badge--' + s + (c.status === s ? ' is-on' : '') + '" data-status="' + s + '">' + esc(statuses[s]) + '</button>';
		}).join('');

		var timeline = (c.events && c.events.length)
			? '<ul class="ecrm-timeline">' + c.events.map(function (e) {
				var label = e.type === 'status_change'
					? (statuses[e.from_status] || e.from_status || '—') + ' → ' + (statuses[e.to_status] || e.to_status || '—')
					: (e.message || e.type);
				return '<li><span class="ecrm-timeline__dot"></span><div><div class="ecrm-timeline__txt">' + esc(label) + '</div><div class="ecrm-timeline__time">' + timeAgo(e.created_at) + '</div></div></li>';
			}).join('') + '</ul>'
			: '<div class="ecrm-empty">Καμία καταγραφή.</div>';

		view.innerHTML = '' +
			'<div class="ecrm-detailhead"><button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-go="contracts">← Πίσω</button>' +
			'<div><div class="ecrm-eyebrow">' + esc(c.code || '') + '</div><h2 class="ecrm-title">' + esc(name) + '</h2></div>' +
			'<button type="button" class="ecrm-btn ecrm-btn--primary ecrm-btn--sm" data-detail-edit>' + svgIcon('edit') + ' Επεξεργασία</button>' +
			'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-printform="' + c.id + '">🖨 Εκτύπωση εντύπου</button>' +
			'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-provform="' + c.id + '">📄 Λήψη εντύπου παρόχου</button>' +
			'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-sign="' + c.id + '">✍ Αποστολή για υπογραφή</button>' +
			(c.track_url ? '<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-track="' + esc(c.track_url) + '">🔗 Link παρακολούθησης</button>' : '') +
			'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-task-new="' + c.id + '">＋ Εργασία</button>' +
			'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm ecrm-btn--danger" data-detail-del="' + c.id + '">🗑 Διαγραφή</button>' +
			'<span class="ecrm-badge ecrm-badge--' + esc(c.status) + '">' + esc(statuses[c.status] || c.status) + '</span>' +
			(c.consent_at ? '<span class="ecrm-chip-consent" title="Συναίνεση: ' + esc(fmtDate(c.consent_at)) + '">✓ GDPR</span>' : '') +
			'</div>' +

			'<div class="ecrm-cols">' +
			'<div class="ecrm-card"><div class="ecrm-step">Στοιχεία πελάτη</div><div class="ecrm-dlgrid">' +
			field('ΑΦΜ', c.afm) + field('ΔΟΥ', c.doy) + field('ΑΔΤ', c.adt) +
			field('Όνομα', c.first_name) + field('Επίθετο', c.last_name) + field('Πατρώνυμο', c.father_name) +
			field('Ημ. Γέννησης', c.birth_date) + field('Κινητό', c.mobile) + field('Τηλέφωνο', c.phone) +
			field('Email', c.email) + field('Διεύθυνση', [c.street, c.street_no].filter(Boolean).join(' ')) +
			field('Πόλη', c.city) + field('Νομός', c.region) + field('ΤΚ', c.postal_code) +
			'</div></div>' +
			'<div class="ecrm-card"><div class="ecrm-step">Στοιχεία αίτησης</div><div class="ecrm-dlgrid">' +
			field('Πάροχος', c.provider_name) + field('Πρόγραμμα', c.program_name) + field('Είδος', energy) +
			field('Ενεργοποίηση', acts[c.activation_type] || c.activation_type) +
			field('Αριθμός Παροχής', c.supply_number) + field('Μετρητής', c.meter_number) + field('Τιμολόγιο', c.invoice_code) +
			'</div>' + (c.notes ? '<div class="ecrm-notes"><strong>Σχόλια:</strong> ' + esc(c.notes) + '</div>' : '') + '</div>' +
			'</div>' +

			'<div class="ecrm-cols">' +
			'<div class="ecrm-card"><div class="ecrm-step">Αλλαγή κατάστασης</div><div class="ecrm-statuschips">' + statusOpts + '</div></div>' +
			'<div class="ecrm-card"><div class="ecrm-step">Ιστορικό</div>' + timeline + '</div>' +
			'</div>' +
			filesCard(c);

		view.querySelectorAll('.ecrm-statuschip').forEach(function (b) {
			b.addEventListener('click', function () {
				var to = this.getAttribute('data-status');
				if (to === c.status) return;
				fetch(api('/contracts/' + c.id + '/status'), { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H()), body: JSON.stringify({ status: to }) })
					.then(function (r) { return r.json(); })
					.then(function (res) { if (res && res.ok) { toast('Κατάσταση: ' + (statuses[to] || to)); openDetail(c.id); } else { toast((res && res.error) || 'Αποτυχία αλλαγής.', false); } })
					.catch(function () { toast('Σφάλμα δικτύου.', false); });
			});
		});

		var dEdit = view.querySelector('[data-detail-edit]');
		if (dEdit) dEdit.addEventListener('click', function () { openEdit(c); });

		var delBtn = view.querySelector('[data-detail-del]');
		if (delBtn) delBtn.addEventListener('click', function () {
			if (!window.confirm('Διαγραφή της αίτησης ' + (c.code || '') + ';\nΗ ενέργεια είναι οριστική και θα διαγράψει και τα σχετικά έγγραφα/υπογραφές.')) return;
			var b = this; b.disabled = true; var t = b.textContent; b.textContent = 'Διαγραφή…';
			fetch(api('/contracts/' + c.id), { method: 'DELETE', headers: H() })
				.then(function (r) { return r.text().then(function (x) { try { return JSON.parse(x); } catch (e) { throw new Error('HTTP ' + r.status); } }); })
				.then(function (d2) {
					if (d2 && d2.ok) { toast('Η αίτηση διαγράφηκε.', true); go('contracts'); }
					else { b.disabled = false; b.textContent = t; toast((d2 && d2.error) || 'Αποτυχία διαγραφής.', false); }
				})
				.catch(function (err) { b.disabled = false; b.textContent = t; toast((err && err.message) || 'Σφάλμα δικτύου.', false); });
		});
		var printBtn = view.querySelector('[data-printform]');
		if (printBtn) printBtn.addEventListener('click', function () {
			var b = this, win = window.open('', '_blank'); b.disabled = true; var t = b.textContent; b.textContent = 'Άνοιγμα…';
			fetch(api('/contracts/' + c.id + '/provider-form'), { headers: H() })
				.then(function (r) { return r.text(); })
				.then(function (x) {
					var d = JSON.parse(x);
					if (!d || !d.ok) throw new Error((d && d.error) || 'fail');
					var bin = atob(d.b64), len = bin.length, arr = new Uint8Array(len);
					for (var i = 0; i < len; i++) { arr[i] = bin.charCodeAt(i); }
					var url = URL.createObjectURL(new Blob([arr], { type: 'application/pdf' }));
					if (win) { win.location.href = url; } else { window.open(url, '_blank'); }
				})
				.catch(function (e) { if (win) { try { win.close(); } catch (er) {} } toast((e && e.message) || 'Αποτυχία.', false); })
				.finally(function () { b.disabled = false; b.textContent = t; });
		});
		var provBtn = view.querySelector('[data-provform]');
		if (provBtn) provBtn.addEventListener('click', function () { downloadBinary('/contracts/' + c.id + '/provider-form', this, 'Λήψη…', '📄 Λήψη εντύπου παρόχου'); });

		var signBtn = view.querySelector('[data-sign]');
		if (signBtn) signBtn.addEventListener('click', function () {
			var b = this; b.disabled = true;
			fetch(api('/contracts/' + c.id + '/sign-link'), { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H()), body: JSON.stringify({ email: true }) })
				.then(function (r) { return r.json(); })
				.then(function (d) {
					if (!d || !d.ok) { toast((d && d.error) || 'Αποτυχία.', false); return; }
					copyText(d.url).then(function (copied) {
						var lead = d.emailed
							? (copied ? 'Στάλθηκε email στον πελάτη. Σύνδεσμος (αντιγράφηκε):' : 'Στάλθηκε email στον πελάτη. Σύνδεσμος:')
							: (copied ? 'Σύνδεσμος υπογραφής (αντιγράφηκε) — στείλ τον στον πελάτη:' : 'Σύνδεσμος υπογραφής — αντίγραψέ τον και στείλ τον στον πελάτη:');
						prompt(lead, d.url);
						if (copied) { toast(d.emailed ? 'Στάλθηκε email υπογραφής στον πελάτη.' : 'Ο σύνδεσμος αντιγράφηκε.'); }
						else { toast(d.emailed ? 'Στάλθηκε email υπογραφής στον πελάτη.' : 'Ο σύνδεσμος δημιουργήθηκε.'); }
						openDetail(c.id);
					});
				})
				.catch(function () { toast('Σφάλμα δικτύου.', false); })
				.finally(function () { b.disabled = false; });
		});

		var trackBtn = view.querySelector('[data-track]');
		if (trackBtn) trackBtn.addEventListener('click', function () {
			var url = this.getAttribute('data-track');
			copyText(url).then(function (copied) {
				prompt(copied ? 'Σύνδεσμος παρακολούθησης (αντιγράφηκε) — στείλ τον στον πελάτη:' : 'Σύνδεσμος παρακολούθησης — αντίγραψέ τον και στείλ τον στον πελάτη:', url);
				toast(copied ? 'Ο σύνδεσμος παρακολούθησης αντιγράφηκε.' : 'Σύνδεσμος παρακολούθησης έτοιμος.');
			});
		});

		var taskNew = view.querySelector('[data-task-new]');
		if (taskNew) taskNew.addEventListener('click', function () {
			var title = prompt('Τίτλος εργασίας / επανάκλησης:', 'Επανάκληση πελάτη');
			if (!title) return;
			var when = prompt('Πότε; (π.χ. 2026-06-20 10:00 — άφησέ το κενό για χωρίς ημερομηνία)', '');
			fetch(api('/tasks'), { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H()), body: JSON.stringify({ title: title, due_at: when || '', contract_id: c.id }) })
				.then(function (r) { return r.json(); })
				.then(function (res) { if (res && res.ok) toast('Δημιουργήθηκε εργασία.'); else toast((res && res.error) || 'Αποτυχία.', false); })
				.catch(function () { toast('Σφάλμα δικτύου.', false); });
		});

		var docGo = view.querySelector('[data-docup-go]');
		if (docGo) docGo.addEventListener('click', function () {
			var wrap = view.querySelector('[data-docup]');
			var input = wrap.querySelector('[data-docup-file]');
			var kind = wrap.querySelector('[data-docup-kind]').value;
			var msg = wrap.querySelector('[data-docup-msg]');
			if (!input.files || !input.files.length) { msg.textContent = 'Επίλεξε αρχείο.'; msg.className = 'ecrm-docup__msg is-err'; return; }
			var fd = new FormData();
			for (var i = 0; i < input.files.length; i++) { fd.append('files[]', input.files[i]); fd.append('kinds[]', kind); }
			var b = this; b.disabled = true; msg.textContent = 'Ανέβασμα…'; msg.className = 'ecrm-docup__msg';
			fetch(api('/contracts/' + c.id + '/files'), { method: 'POST', headers: H(), body: fd })
				.then(function (r) { return r.json(); })
				.then(function (d) {
					if (d && d.ok) { toast('Προστέθηκαν ' + d.saved + ' έγγραφα.'); openDetail(c.id); }
					else { msg.textContent = (d && d.error) || 'Αποτυχία.'; msg.className = 'ecrm-docup__msg is-err'; b.disabled = false; }
				})
				.catch(function () { msg.textContent = 'Σφάλμα δικτύου.'; msg.className = 'ecrm-docup__msg is-err'; b.disabled = false; });
		});
	}

	// ---- generic base64 download (PDF / Excel share this) ----------------
	function downloadBinary(path, btn, busy, idle) {
		btn.disabled = true; var t = btn.textContent; btn.textContent = busy;
		fetch(api(path), { headers: H() })
			.then(function (r) {
				return r.text().then(function (txt) {
					try { return JSON.parse(txt); }
					catch (e) { throw new Error('HTTP ' + r.status + ': ' + txt.slice(0, 300)); }
				});
			})
			.then(function (d) {
				if (!d || !d.ok) { toast((d && d.error) || 'Αποτυχία.', false); return; }
				var bin = atob(d.b64), len = bin.length, arr = new Uint8Array(len);
				for (var i = 0; i < len; i++) { arr[i] = bin.charCodeAt(i); }
				var a = document.createElement('a');
				a.href = URL.createObjectURL(new Blob([arr], { type: d.mime }));
				a.download = d.filename; document.body.appendChild(a); a.click();
				setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
			})
			.catch(function (err) { toast((err && err.message) || 'Σφάλμα δικτύου.', false); })
			.finally(function () { btn.disabled = false; btn.textContent = idle || t; });
	}

	// ---- export modal (filter + download .xlsx) --------------------------
	function openExportModal() {
		var statuses = (window.ECRM && ECRM.statuses) || {};
		var statusOpts = '<option value="">Όλες</option>' + Object.keys(statuses).map(function (k) {
			return '<option value="' + esc(k) + '"' + (contractsState.status === k ? ' selected' : '') + '>' + esc(statuses[k]) + '</option>';
		}).join('');

		var ov = document.createElement('div');
		ov.className = 'ecrm-modalov';
		ov.innerHTML =
			'<div class="ecrm-modal" role="dialog" aria-modal="true">' +
				'<button type="button" class="ecrm-modal__x" data-x aria-label="Κλείσιμο">×</button>' +
				'<div class="ecrm-modal__eyebrow">⤓ Εξαγωγή σε Excel</div>' +
				'<h3 class="ecrm-modal__title">Συμβάσεις — φιλτράρισμα &amp; λήψη</h3>' +
				'<p class="ecrm-modal__lead">Διάλεξε <strong>κατάσταση</strong> και προαιρετικά <strong>εύρος ημερομηνιών</strong>. Το αρχείο κατεβαίνει σε <code>.xlsx</code> με τις στήλες της σελίδας συμβάσεων.</p>' +
				'<div class="ecrm-modal__card">' +
					'<div class="ecrm-field"><span class="ecrm-field__label">🏳 Κατάσταση</span><select class="ecrm-input" data-x-status>' + statusOpts + '</select></div>' +
				'</div>' +
				'<div class="ecrm-modal__card">' +
					'<div class="ecrm-field__label">📅 Εύρος ημερομηνιών <span class="ecrm-muted">(προαιρετικό)</span></div>' +
					'<div class="ecrm-modal__row">' +
						'<label class="ecrm-field"><span class="ecrm-field__label">Από</span><input type="date" class="ecrm-input" data-x-from></label>' +
						'<label class="ecrm-field"><span class="ecrm-field__label">Έως</span><input type="date" class="ecrm-input" data-x-to></label>' +
					'</div>' +
				'</div>' +
				'<label class="ecrm-modal__scope-sel"><span class="ecrm-field__label">👥 Συνεργάτες</span>' +
					'<select class="ecrm-input" data-x-partner><option value="me">Μόνο εγώ</option></select></label>' +
				'<div class="ecrm-modal__bar">' +
					'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-x-clear>↻ Καθαρισμός</button>' +
					'<span style="flex:1"></span>' +
					'<button type="button" class="ecrm-btn ecrm-btn--ghost" data-x>Ακύρωση</button>' +
					'<button type="button" class="ecrm-btn ecrm-btn--primary" data-x-go>⤓ Λήψη Excel</button>' +
				'</div>' +
			'</div>';
		app.appendChild(ov);

		function close() { ov.remove(); }
		ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
		ov.querySelectorAll('[data-x]').forEach(function (b) { b.addEventListener('click', close); });

		// Populate the partners dropdown (managers see team members).
		var partnerSel = ov.querySelector('[data-x-partner]');
		fetch(api('/team'), { headers: H() }).then(function (r) { return r.json(); }).then(function (t) {
			var members = (t && t.members) || [];
			if (members.length) {
				var all = document.createElement('option'); all.value = 'team'; all.textContent = 'Όλη η ομάδα'; partnerSel.appendChild(all);
				members.forEach(function (m) { var o = document.createElement('option'); o.value = String(m.id); o.textContent = m.name; partnerSel.appendChild(o); });
				if (contractsState.scope === 'team') partnerSel.value = 'team';
			}
		}).catch(function () {});

		ov.querySelector('[data-x-clear]').addEventListener('click', function () {
			ov.querySelector('[data-x-status]').value = '';
			ov.querySelector('[data-x-from]').value = '';
			ov.querySelector('[data-x-to]').value = '';
			partnerSel.value = 'me';
		});

		var goBtn = ov.querySelector('[data-x-go]');
		goBtn.addEventListener('click', function () {
			var status = ov.querySelector('[data-x-status]').value;
			var from = ov.querySelector('[data-x-from]').value;
			var to = ov.querySelector('[data-x-to]').value;
			var pv = partnerSel.value;
			var scope = 'own', partner = '';
			if (pv === 'team') { scope = 'team'; }
			else if (pv !== 'me') { partner = pv; }
			var qs = '?status=' + encodeURIComponent(status) + '&from=' + encodeURIComponent(from) +
				'&to=' + encodeURIComponent(to) + '&scope=' + scope + '&partner=' + encodeURIComponent(partner) +
				'&q=' + encodeURIComponent(contractsState.q || '');
			goBtn.disabled = true; var t = goBtn.textContent; goBtn.textContent = 'Δημιουργία…';
			fetch(api('/contracts/export') + qs, { headers: H() })
				.then(function (r) { return r.json(); })
				.then(function (d) {
					if (!d || !d.ok) { toast((d && d.error) || 'Αποτυχία.', false); goBtn.disabled = false; goBtn.textContent = t; return; }
					var bin = atob(d.b64), len = bin.length, arr = new Uint8Array(len);
					for (var i = 0; i < len; i++) { arr[i] = bin.charCodeAt(i); }
					var a = document.createElement('a');
					a.href = URL.createObjectURL(new Blob([arr], { type: d.mime }));
					a.download = d.filename || 'symvaseis.xlsx'; document.body.appendChild(a); a.click();
					setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
					toast('Εξήχθησαν ' + d.count + ' συμβάσεις.');
					close();
				})
				.catch(function () { toast('Σφάλμα δικτύου.', false); goBtn.disabled = false; goBtn.textContent = t; });
		});
	}

	// ---- team -------------------------------------------------------------
	function loadTeam() {
		var view = app.querySelector('.ecrm-view[data-view="team"]');
		fetch(api('/team'), { headers: H() })
			.then(function (r) { return r.json(); })
			.then(function (d) { renderTeam(view, d); })
			.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα φόρτωσης.</div></div>'; });
	}

	var teamTab = 'ecrm_seller';
	function renderTeam(view, d) {
		var members = d.members || [];
		var roleLabel = { ecrm_seller: 'Πωλητής', ecrm_registrar: 'Καταχωρητής' };
		var plural = { ecrm_seller: 'πωλητές', ecrm_registrar: 'καταχωρητές' };
		var list = members.filter(function (m) { return m.role === teamTab; });

		var tabs = ['ecrm_seller', 'ecrm_registrar'].map(function (r) {
			var n = members.filter(function (m) { return m.role === r; }).length;
			return '<button type="button" class="ecrm-tab2' + (teamTab === r ? ' is-on' : '') + '" data-ttab="' + r + '">' + (r === 'ecrm_seller' ? 'Πωλητές' : 'Καταχωρητές') + ' <span>' + n + '</span></button>';
		}).join('');

		var rows = list.map(function (m) {
			return '<tr>' +
				'<td><span class="ecrm-cell-cust"><span class="ecrm-cell-mark ecrm-cell-mark--cust" style="--h:' + tint(m.name) + '">' + esc(initials(m.name)) + '</span><span>' + esc(m.name) + '</span></span></td>' +
				'<td>' + esc(m.email) + '</td>' +
				'<td class="ecrm-muted">—</td>' +
				'<td>' + (m.active ? '<span class="ecrm-badge ecrm-badge--active">Ενεργός</span>' : '<span class="ecrm-badge ecrm-badge--cancelled">Ανενεργός</span>') + '</td>' +
				'<td><button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-toggle="' + m.id + '">' + (m.active ? 'Απενεργοποίηση' : 'Ενεργοποίηση') + '</button> ' +
				'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-remove="' + m.id + '">Αφαίρεση</button></td>' +
				'</tr>';
		}).join('');

		var table = list.length
			? '<div class="ecrm-tablewrap"><table class="ecrm-table"><thead><tr><th>Ονοματεπώνυμο</th><th>Email</th><th>Τηλέφωνο</th><th>Κατάσταση</th><th>Ενέργειες</th></tr></thead><tbody>' + rows + '</tbody></table></div>'
			: '<div class="ecrm-emptybox"><div class="ecrm-emptybox__txt">Κανένα μέλος ' + (teamTab === 'ecrm_seller' ? 'στους πωλητές' : 'στους καταχωρητές') + ' ακόμα.</div></div>';

		var canManage = d.can_manage;
		var addForm = canManage ? (
			'<div class="ecrm-card ecrm-addform" data-addwrap hidden><div class="ecrm-step">Νέο μέλος · ' + roleLabel[teamTab] + '</div>' +
			'<div class="ecrm-grid">' +
			'<label class="ecrm-field"><span class="ecrm-field__label">Ονοματεπώνυμο</span><input class="ecrm-input" data-f="name"></label>' +
			'<label class="ecrm-field"><span class="ecrm-field__label">Email</span><input class="ecrm-input" type="email" data-f="email"></label>' +
			'<label class="ecrm-field"><span class="ecrm-field__label">Κωδικός (προαιρετικό)</span><input class="ecrm-input" data-f="password" placeholder="αυτόματος αν κενό"></label>' +
			'</div><button type="button" class="ecrm-btn ecrm-btn--primary ecrm-btn--sm" data-add-member>+ Προσθήκη</button>' +
			'<div class="ecrm-ai-status" data-member-msg></div></div>'
		) : '';

		view.innerHTML =
			'<header class="ecrm-head"><h2 class="ecrm-title">Η ομάδα μου</h2><p class="ecrm-sub">Διαχείριση πωλητών και καταχωρητών του γραφείου σου.</p></header>' +
			'<div class="ecrm-card">' +
			'<div class="ecrm-tabs2">' + tabs + '</div>' +
			'<div class="ecrm-listhead"><span class="ecrm-listhead__count">' + list.length + ' ' + plural[teamTab] + '</span>' +
			(canManage ? '<button type="button" class="ecrm-btn ecrm-btn--primary ecrm-btn--sm" data-show-add>+ Νέος ' + roleLabel[teamTab] + '</button>' : '') + '</div>' +
			table + '</div>' + addForm;

		view.querySelectorAll('[data-ttab]').forEach(function (b) { b.addEventListener('click', function () { teamTab = this.getAttribute('data-ttab'); renderTeam(view, d); }); });
		var showAdd = view.querySelector('[data-show-add]');
		if (showAdd) showAdd.addEventListener('click', function () { var w = view.querySelector('[data-addwrap]'); if (w) { w.hidden = !w.hidden; if (!w.hidden) w.scrollIntoView({ behavior: 'smooth' }); } });
		view.querySelectorAll('[data-toggle]').forEach(function (b) { b.addEventListener('click', function () { teamOp(this.getAttribute('data-toggle'), 'toggle'); }); });
		view.querySelectorAll('[data-remove]').forEach(function (b) { b.addEventListener('click', function () { if (confirm('Αφαίρεση μέλους από την ομάδα;')) teamOp(this.getAttribute('data-remove'), 'remove'); }); });
		var addBtn = view.querySelector('[data-add-member]');
		if (addBtn) addBtn.addEventListener('click', function () { addMember(view, this, teamTab); });
	}

	function teamOp(id, op) {
		fetch(api('/team/' + id), { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H()), body: JSON.stringify({ op: op }) })
			.then(function (r) { return r.json(); })
			.then(function (res) { if (res && res.ok) { toast('Έγινε.'); loadTeam(); } else { toast((res && res.error) || 'Αποτυχία.', false); } })
			.catch(function () { toast('Σφάλμα δικτύου.', false); });
	}

	function addMember(view, btn, role) {
		var get = function (f) { var el = view.querySelector('[data-f="' + f + '"]'); return el ? el.value : ''; };
		var payload = { name: get('name'), email: get('email'), role: role || get('role') || 'ecrm_seller', password: get('password') };
		if (!payload.name || !payload.email) { toast('Συμπλήρωσε όνομα και email.', false); return; }
		btn.disabled = true;
		fetch(api('/team'), { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H()), body: JSON.stringify(payload) })
			.then(function (r) { return r.json(); })
			.then(function (d) {
				if (!d || !d.ok) { toast((d && d.error) || 'Αποτυχία.', false); return; }
				var msg = view.querySelector('[data-member-msg]');
				if (msg) msg.textContent = 'Δημιουργήθηκε. Username: ' + d.username + ' · Κωδικός: ' + d.password + ' (κράτησέ τον τώρα)';
				loadTeam();
			})
			.catch(function () { toast('Σφάλμα δικτύου.', false); })
			.finally(function () { btn.disabled = false; });
	}

	// ---- network ----------------------------------------------------------
	function loadNetwork() {
		var view = app.querySelector('.ecrm-view[data-view="network"]');
		fetch(api('/network'), { headers: H() })
			.then(function (r) { return r.json(); })
			.then(function (d) { renderNetwork(view, (d && d.partners) || []); })
			.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα φόρτωσης.</div></div>'; });
	}

	function networkIcon() {
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="12" r="2.4"/><circle cx="18" cy="6" r="2.4"/><circle cx="18" cy="18" r="2.4"/><path d="M8.2 10.8 15.8 7.2"/><path d="M8.2 13.2 15.8 16.8"/></svg>';
	}

	function renderNetwork(view, partners) {
		var head =
			'<header class="ecrm-head ecrm-head--row"><div class="ecrm-titlewrap"><span class="ecrm-pageicon">' + networkIcon() + '</span>' +
			'<div><h2 class="ecrm-title">Το δίκτυό μου</h2><p class="ecrm-sub">Οι υποσυνεργάτες σου. Πάτα σε έναν για να δεις τα στατιστικά του.</p></div></div>' +
			'<button type="button" class="ecrm-btn ecrm-btn--amber ecrm-btn--sm" data-show-invite>+ Νέος υποσυνεργάτης</button></header>';

		var bodyCard;
		if (partners.length) {
			var rows = partners.map(function (p) {
				return '<tr><td><span class="ecrm-cell-cust"><span class="ecrm-cell-mark ecrm-cell-mark--cust" style="--h:' + tint(p.name) + '">' + esc(initials(p.name)) + '</span><span>' + esc(p.name) + '</span></span></td>' +
					'<td>' + esc(p.email) + '</td><td>' + (p.team_size || 0) + '</td><td>' + (p.contracts || 0) + '</td></tr>';
			}).join('');
			bodyCard = '<div class="ecrm-card"><div class="ecrm-tablewrap"><table class="ecrm-table"><thead><tr><th>Συνεργάτης</th><th>Email</th><th>Ομάδα</th><th>Αιτήσεις</th></tr></thead><tbody>' + rows + '</tbody></table></div></div>';
		} else {
			bodyCard = '<div class="ecrm-card"><div class="ecrm-emptybox ecrm-emptybox--big">' +
				'<span class="ecrm-emptybox__ico">' + networkIcon() + '</span>' +
				'<div class="ecrm-emptybox__txt">Δεν έχεις υποσυνεργάτες ακόμα.</div>' +
				'<button type="button" class="ecrm-btn ecrm-btn--amber ecrm-btn--sm" data-show-invite>+ Πρόσκληση νέου υποσυνεργάτη</button></div></div>';
		}

		var inviteForm =
			'<div class="ecrm-card ecrm-addform" data-invitewrap hidden><div class="ecrm-step">Νέος υποσυνεργάτης</div>' +
			'<div class="ecrm-grid">' +
			'<label class="ecrm-field"><span class="ecrm-field__label">Ονοματεπώνυμο</span><input class="ecrm-input" data-nf="name"></label>' +
			'<label class="ecrm-field"><span class="ecrm-field__label">Email</span><input class="ecrm-input" type="email" data-nf="email"></label>' +
			'<label class="ecrm-field"><span class="ecrm-field__label">Κωδικός (προαιρετικό)</span><input class="ecrm-input" data-nf="password" placeholder="αυτόματος αν κενό"></label>' +
			'</div><button type="button" class="ecrm-btn ecrm-btn--primary ecrm-btn--sm" data-invite>+ Πρόσκληση</button>' +
			'<div class="ecrm-ai-status" data-invite-msg></div></div>';

		view.innerHTML = head + bodyCard + inviteForm;

		view.querySelectorAll('[data-show-invite]').forEach(function (b) {
			b.addEventListener('click', function () { var w = view.querySelector('[data-invitewrap]'); if (w) { w.hidden = !w.hidden; if (!w.hidden) w.scrollIntoView({ behavior: 'smooth' }); } });
		});
		var inv = view.querySelector('[data-invite]');
		if (inv) inv.addEventListener('click', function () {
			var get = function (f) { var el = view.querySelector('[data-nf="' + f + '"]'); return el ? el.value : ''; };
			var payload = { name: get('name'), email: get('email'), role: 'ecrm_partner', password: get('password') };
			if (!payload.name || !payload.email) { toast('Συμπλήρωσε όνομα και email.', false); return; }
			this.disabled = true; var b = this;
			fetch(api('/team'), { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H()), body: JSON.stringify(payload) })
				.then(function (r) { return r.json(); })
				.then(function (d) {
					if (!d || !d.ok) { toast((d && d.error) || 'Αποτυχία.', false); return; }
					var msg = view.querySelector('[data-invite-msg]');
					if (msg) msg.textContent = 'Δημιουργήθηκε. Username: ' + d.username + ' · Κωδικός: ' + d.password;
					loadNetwork();
				})
				.catch(function () { toast('Σφάλμα δικτύου.', false); })
				.finally(function () { b.disabled = false; });
		});
	}

		// ---- import provider Excel -------------------------------------------
	var importState = { columns: [], rows: [], total: 0, supplyCol: -1, statusCol: -1, statusMap: {} };

	function initImport() {
		var view = app.querySelector('.ecrm-view[data-view="import"]');
		// Only render the dropzone once per visit (reset state each entry).
		importState = { columns: [], rows: [], total: 0, supplyCol: -1, statusCol: -1, statusMap: {} };
		view.innerHTML =
			'<header class="ecrm-head"><h2 class="ecrm-title">Εισαγωγή Excel παρόχου</h2>' +
			'<p class="ecrm-sub">Ανέβασε το αρχείο του παρόχου, αντιστοίχισε στήλες και ενημέρωσε τα statuses βάσει αριθμού παροχής.</p></header>' +
			'<div class="ecrm-card ecrm-card--ai">' +
			'<div class="ecrm-drop" data-idrop tabindex="0" role="button">' +
			'<input type="file" data-ifile accept=".xlsx,.csv" hidden>' +
			'<div class="ecrm-drop__icon">⇪</div><div class="ecrm-drop__title">Σύρε το Excel/CSV εδώ</div>' +
			'<div class="ecrm-drop__hint">ή <button type="button" class="ecrm-link" data-ipick>πάτα για επιλογή</button> · .xlsx ή .csv</div>' +
			'</div><div class="ecrm-ai-status" data-istatus aria-live="polite"></div></div>' +
			'<div data-imap></div>';

		var drop = view.querySelector('[data-idrop]'), input = view.querySelector('[data-ifile]');
		view.querySelector('[data-ipick]').addEventListener('click', function () { input.click(); });
		drop.addEventListener('click', function (e) { if (e.target === drop || e.target.classList.contains('ecrm-drop__title') || e.target.classList.contains('ecrm-drop__icon')) input.click(); });
		['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-drag'); }); });
		['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('is-drag'); }); });
		drop.addEventListener('drop', function (e) { if (e.dataTransfer.files[0]) parseImport(view, e.dataTransfer.files[0]); });
		input.addEventListener('change', function () { if (this.files[0]) parseImport(view, this.files[0]); });
	}

	function parseImport(view, file) {
		var st = view.querySelector('[data-istatus]');
		st.textContent = 'Ανάγνωση αρχείου…';
		var fd = new FormData(); fd.append('file', file);
		fetch(api('/import/parse'), { method: 'POST', headers: H(), body: fd })
			.then(function (r) { return r.json(); })
			.then(function (d) {
				if (!d || !d.ok) { st.textContent = ''; toast((d && d.error) || 'Αποτυχία ανάγνωσης.', false); return; }
				importState.columns = d.columns; importState.rows = d.rows; importState.total = d.total;
				st.textContent = 'Διαβάστηκαν ' + d.total + ' γραμμές.';
				autodetect();
				renderMapping(view);
			})
			.catch(function () { st.textContent = ''; toast('Σφάλμα δικτύου.', false); });
	}

	function autodetect() {
		var cols = importState.columns;
		cols.forEach(function (c, i) {
			var l = (c || '').toLowerCase();
			if (importState.supplyCol < 0 && /(παροχ|ηκασπ|supply)/.test(l)) importState.supplyCol = i;
			if (importState.statusCol < 0 && /(κατάστ|καταστ|status|στάδιο|σταδιο)/.test(l)) importState.statusCol = i;
		});
		if (importState.supplyCol < 0) importState.supplyCol = 0;
		if (importState.statusCol < 0) importState.statusCol = Math.min(2, cols.length - 1);
	}

	function distinctStatusValues() {
		var sc = importState.statusCol, set = {};
		importState.rows.forEach(function (r) { var v = (r[sc] || '').trim(); if (v) set[v] = true; });
		return Object.keys(set);
	}

	function renderMapping(view) {
		var wrap = view.querySelector('[data-imap]');
		var colOpts = function (sel) { return importState.columns.map(function (c, i) { return '<option value="' + i + '"' + (i === sel ? ' selected' : '') + '>' + esc(c || ('Στήλη ' + (i + 1))) + '</option>'; }).join(''); };

		var statusSlugs = (ECRM && ECRM.statuses) ? ECRM.statuses : {}; // slug->label
		var distinct = distinctStatusValues();
		var mapRows = distinct.map(function (val) {
			var guess = guessStatus(val);
			var opts = '<option value="">— αγνόησε —</option>' + Object.keys(statusSlugs).map(function (sl) {
				return '<option value="' + sl + '"' + (sl === guess ? ' selected' : '') + '>' + esc(statusSlugs[sl]) + '</option>';
			}).join('');
			importState.statusMap[val] = guess || '';
			return '<tr><td>' + esc(val) + '</td><td><select class="ecrm-select" data-smap="' + esc(val) + '">' + opts + '</select></td></tr>';
		}).join('');

		wrap.innerHTML =
			'<div class="ecrm-card"><div class="ecrm-step">1 · Αντιστοίχιση στηλών</div>' +
			'<div class="ecrm-row"><span class="ecrm-row__label">Αριθμός παροχής</span><select class="ecrm-select" data-supplycol>' + colOpts(importState.supplyCol) + '</select></div>' +
			'<div class="ecrm-row"><span class="ecrm-row__label">Κατάσταση</span><select class="ecrm-select" data-statuscol>' + colOpts(importState.statusCol) + '</select></div></div>' +
			'<div class="ecrm-card"><div class="ecrm-step">2 · Αντιστοίχιση καταστάσεων παρόχου → δικές σου</div>' +
			'<div class="ecrm-tablewrap"><table class="ecrm-table"><thead><tr><th>Κατάσταση παρόχου</th><th>Δική σου κατάσταση</th></tr></thead><tbody>' + (mapRows || '<tr><td colspan="2">—</td></tr>') + '</tbody></table></div></div>' +
			'<div class="ecrm-card"><div class="ecrm-step">3 · Εφαρμογή</div>' +
			'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-preview>Προεπισκόπηση</button> ' +
			'<button type="button" class="ecrm-btn ecrm-btn--primary ecrm-btn--sm" data-apply>Ενημέρωση καταστάσεων</button>' +
			'<div class="ecrm-import-report" data-report></div></div>';

		wrap.querySelector('[data-supplycol]').addEventListener('change', function () { importState.supplyCol = +this.value; });
		wrap.querySelector('[data-statuscol]').addEventListener('change', function () { importState.statusCol = +this.value; renderMapping(view); });
		wrap.querySelectorAll('[data-smap]').forEach(function (sel) { sel.addEventListener('change', function () { importState.statusMap[this.getAttribute('data-smap')] = this.value; }); });
		wrap.querySelector('[data-preview]').addEventListener('click', function () { applyImport(view, true, this); });
		wrap.querySelector('[data-apply]').addEventListener('click', function () { if (confirm('Ενημέρωση καταστάσεων στις συμβάσεις;')) applyImport(view, false, this); });
	}

	function guessStatus(val) {
		var l = (val || '').toLowerCase();
		if (/(ενεργ|active)/.test(l)) return 'active';
		if (/(ακυρ|cancel)/.test(l)) return 'cancelled';
		if (/(εκκρεμ|pending)/.test(l)) return 'pending';
		if (/(δρομολ|rout)/.test(l)) return 'routed';
		if (/(επιλ|resolv)/.test(l)) return 'resolved';
		if (/(υπογρ|sign)/.test(l)) return 'pending_signature';
		if (/(επεξεργ|process)/.test(l)) return 'processing';
		if (/(τερματ|terminat)/.test(l)) return 'terminated';
		if (/(νέα|νεα|new)/.test(l)) return 'new';
		return '';
	}

	function buildPairs() {
		var sc = importState.supplyCol, stc = importState.statusCol, pairs = [];
		importState.rows.forEach(function (r) {
			var supply = (r[sc] || '').trim();
			var raw = (r[stc] || '').trim();
			var slug = importState.statusMap[raw] || '';
			if (supply && slug) pairs.push({ supply: supply, status: slug });
		});
		return pairs;
	}

	function applyImport(view, dry, btn) {
		var pairs = buildPairs();
		if (!pairs.length) { toast('Δεν υπάρχουν αντιστοιχισμένες εγγραφές.', false); return; }
		btn.disabled = true; var t = btn.textContent; btn.textContent = '…';
		fetch(api('/import/apply'), { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H()), body: JSON.stringify({ pairs: pairs, dry: dry }) })
			.then(function (r) { return r.json(); })
			.then(function (d) {
				if (!d || !d.ok) { toast((d && d.error) || 'Αποτυχία.', false); return; }
				var rep = view.querySelector('[data-report]');
				rep.innerHTML = '<div class="ecrm-import-stats">' +
					'<span>Βρέθηκαν: <b>' + d.matched + '</b></span>' +
					'<span>' + (dry ? 'Θα ενημερωθούν' : 'Ενημερώθηκαν') + ': <b>' + d.updated + '</b></span>' +
					'<span>Ίδια: <b>' + d.unchanged + '</b></span>' +
					'<span>Χωρίς αντιστοίχιση: <b>' + d.unmatched_total + '</b></span></div>' +
					(d.unmatched_total ? '<div class="ecrm-muted" style="margin-top:8px">Δεν βρέθηκαν: ' + d.unmatched.map(esc).join(', ') + (d.unmatched_total > d.unmatched.length ? '…' : '') + '</div>' : '');
				toast(dry ? 'Προεπισκόπηση έτοιμη.' : ('Ενημερώθηκαν ' + d.updated + ' συμβάσεις.'));
			})
			.catch(function () { toast('Σφάλμα δικτύου.', false); })
			.finally(function () { btn.disabled = false; btn.textContent = t; });
	}

		// ---- commissions ------------------------------------------------------
	var commScope = 'own';
	function loadCommissions() {
		var view = app.querySelector('.ecrm-view[data-view="commissions"]');
		fetch(api('/commissions') + '?scope=' + encodeURIComponent(commScope), { headers: H() })
			.then(function (r) { return r.json(); })
			.then(function (d) {
				if (!d || !d.ok) { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα.</div></div>'; return; }
				view.innerHTML = commissionsHTML(d);
				view.querySelectorAll('[data-cscope]').forEach(function (b) { b.addEventListener('click', function () { commScope = this.getAttribute('data-cscope'); loadCommissions(); }); });
			})
			.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα φόρτωσης.</div></div>'; });
	}

	function commissionsHTML(d) {
		var months = d.months || [];
		var range = months.length ? (months[months.length - 1].label + ' → ' + months[0].label) : '—';
		var avg = d.count ? (d.total / d.count) : 0;

		var hist = months.length
			? '<div class="ecrm-tablewrap"><table class="ecrm-table"><thead><tr><th>Περίοδος</th><th>Συμβόλαια</th><th>Κατάσταση</th><th style="text-align:right">Σύνολο</th></tr></thead><tbody>' +
				months.map(function (m) {
					return '<tr><td><strong>' + esc(m.label) + '</strong></td><td>' + m.count + ' συμβόλαια</td>' +
						'<td><span class="ecrm-badge ecrm-badge--routed">Καταχωρημένο</span></td>' +
						'<td style="text-align:right" class="ecrm-mono">' + Number(m.amount).toFixed(0) + ' €</td></tr>';
				}).join('') + '</tbody></table></div>'
			: '<div class="ecrm-empty">Δεν υπάρχουν εκκαθαρίσεις ακόμα.</div>';

		return '' +
			'<header class="ecrm-head ecrm-head--row"><div><div class="ecrm-eyebrow">Προμήθειες</div><h2 class="ecrm-title">Οι εκκαθαρίσεις μου</h2>' +
			'<p class="ecrm-sub">Ιστορικό και συνολικά έσοδα από επιτυχημένες συμβάσεις</p></div>' +
			'<div class="ecrm-scope"><button type="button" class="ecrm-scope__b' + (commScope==="own"?" is-on":"") + '" data-cscope="own">Δικά μου</button><button type="button" class="ecrm-scope__b' + (commScope==="team"?" is-on":"") + '" data-cscope="team">Ομάδας</button></div></header>' +

			// dark hero
			'<div class="ecrm-payhero">' +
			'<div class="ecrm-payhero__main"><div class="ecrm-payhero__eyebrow">💰 ΣΥΝΟΛΙΚΑ ΕΣΟΔΑ · ' + range + '</div>' +
			'<div class="ecrm-payhero__big">' + Number(d.total).toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g, ".") + ' €</div>' +
			'<div class="ecrm-payhero__sub">Από ' + months.length + ' εκκαθαρίσεις</div></div>' +
			'<div class="ecrm-payhero__side"><div class="ecrm-payhero__k">ΚΟΡΥΦΑΙΟΣ ΜΗΝΑΣ</div><div class="ecrm-payhero__v">' + esc(d.best_label || '—') + '</div>' +
			'<div class="ecrm-payhero__k" style="margin-top:12px">ΣΕ ΑΝΑΜΟΝΗ</div><div class="ecrm-payhero__v">~' + Number(d.pending_est).toFixed(0) + ' €</div></div></div>' +

			// three stat cards
			'<div class="ecrm-stats">' +
			'<div class="ecrm-stat is-routed"><div class="ecrm-stat__k">✓ Καταχωρημένες</div><div class="ecrm-stat__v">' + (d.count || 0) + '</div></div>' +
			'<div class="ecrm-stat is-today"><div class="ecrm-stat__k">💶 Πληρωμένα</div><div class="ecrm-stat__v">' + Number(d.paid_total || 0).toFixed(0) + ' €</div></div>' +
			'<div class="ecrm-stat is-pending"><div class="ecrm-stat__k">🧾 Προς πληρωμή</div><div class="ecrm-stat__v">' + Number(d.unpaid_total || 0).toFixed(0) + ' €</div></div></div>' +

			// history
			'<div class="ecrm-card"><div class="ecrm-step">Ιστορικό εκκαθαρίσεων <span class="ecrm-step__hint">μέσος όρος ' + avg.toFixed(0) + ' € / σύμβαση</span></div>' + hist + '</div>';
	}

		// ---- analytics (managers) --------------------------------------------
	var analyticsScope = 'team';
	function loadAnalytics() {
		var view = app.querySelector('.ecrm-view[data-view="analytics"]');
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
			'<div class="ecrm-stat is-today"><div class="ecrm-stat__k">⌛ Μέσος χρόνος ενεργ.</div><div class="ecrm-stat__v">' + (d.avg_days == null ? '—' : (Number(d.avg_days).toFixed(1) + ' ημ.')) + '</div></div>' +
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
			? '<div class="ecrm-tablewrap"><table class="ecrm-table"><thead><tr><th>#</th><th>Συνεργάτης</th><th>Συμβάσεις</th><th style="text-align:right">Προμήθεια €</th></tr></thead><tbody>' +
				lb.map(function (r, i) {
					var medal = i === 0 ? '🥇' : (i === 1 ? '🥈' : (i === 2 ? '🥉' : (i + 1)));
					return '<tr><td>' + medal + '</td><td><strong>' + esc(r.name) + '</strong></td><td>' + r.count + '</td>' +
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
			'<div class="ecrm-card"><div class="ecrm-step">🏆 Κατάταξη συνεργατών</div>' + lbHTML + '</div>';
	}

		// ---- tasks / callbacks ------------------------------------------------
	var tasksState = { filter: 'open', scope: 'own' };
	var taskTeamCache = null;
	function loadTasks() {
		var view = app.querySelector('.ecrm-view[data-view="tasks"]');
		fetch(api('/tasks') + '?scope=' + tasksState.scope + '&filter=' + tasksState.filter, { headers: H() })
			.then(function (r) { return r.json(); })
			.then(function (d) {
				if (!d || !d.ok) { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα.</div></div>'; return; }
				if (d.can_team && taskTeamCache === null) {
					fetch(api('/team'), { headers: H() }).then(function (r) { return r.json(); })
						.then(function (t) { taskTeamCache = (t && t.members) || (t && t.rows) || []; renderTasks(view, d); })
						.catch(function () { taskTeamCache = []; renderTasks(view, d); });
				} else { renderTasks(view, d); }
			})
			.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα δικτύου.</div></div>'; });
	}

	function taskDue(t) {
		if (!t.due_at) return '<span class="ecrm-muted">—</span>';
		var cls = t.overdue ? 'is-overdue' : '';
		return '<span class="ecrm-taskdue ' + cls + '">' + esc(fmtDate(t.due_at)) + (t.overdue ? ' • εκπρόθεσμη' : '') + '</span>';
	}

	function renderTasks(view, d) {
		var tabs = [['open','Ανοιχτές'],['today','Σήμερα'],['overdue','Εκπρόθεσμες'],['done','Ολοκληρωμένες']];
		var tabsHTML = tabs.map(function (t) {
			return '<button type="button" class="ecrm-tab' + (tasksState.filter === t[0] ? ' is-on' : '') + '" data-tfilter="' + t[0] + '">' + t[1] + '</button>';
		}).join('');

		var scopeToggle = d.can_team
			? '<div class="ecrm-scope"><button type="button" class="ecrm-scope__b' + (tasksState.scope==="own"?" is-on":"") + '" data-tscope="own">Δικά μου</button><button type="button" class="ecrm-scope__b' + (tasksState.scope==="team"?" is-on":"") + '" data-tscope="team">Ομάδας</button></div>'
			: '';

		var assigneeField = '';
		if (d.can_team && taskTeamCache && taskTeamCache.length) {
			var opts = '<option value="">— Ανάθεση σε εμένα —</option>' + taskTeamCache.map(function (m) {
				var idv = m.id || m.ID || m.user_id; var nm = m.name || m.display_name || ('#' + idv);
				return idv ? '<option value="' + idv + '">' + esc(nm) + '</option>' : '';
			}).join('');
			assigneeField = '<select class="ecrm-input" data-task-assignee>' + opts + '</select>';
		}

		var addForm = '<div class="ecrm-card"><div class="ecrm-step">Νέα εργασία</div>' +
			'<div class="ecrm-taskform">' +
			'<input type="text" class="ecrm-input" data-task-title placeholder="Π.χ. Επανάκληση πελάτη για υπογραφή">' +
			'<input type="datetime-local" class="ecrm-input" data-task-due>' +
			'<select class="ecrm-input" data-task-prio><option value="normal">Κανονική</option><option value="high">Υψηλή</option><option value="low">Χαμηλή</option></select>' +
			assigneeField +
			'<button type="button" class="ecrm-btn ecrm-btn--primary" data-task-add>Προσθήκη</button>' +
			'</div></div>';

		var tasks = d.tasks || [];
		var rows = tasks.length ? tasks.map(function (t) {
			var prioDot = '<span class="ecrm-prio ecrm-prio--' + esc(t.priority) + '" title="' + esc(t.priority) + '"></span>';
			var link = t.contract_id ? '<a href="#" class="ecrm-tasklink" data-task-open="' + t.contract_id + '">' + esc(t.contract_code || ('#' + t.contract_id)) + '</a>' : '';
			var sub = [t.customer, link, (d.team ? t.assignee : '')].filter(Boolean).join(' · ');
			var done = t.status === 'done';
			return '<li class="ecrm-task' + (done ? ' is-done' : '') + (t.overdue ? ' is-overdue' : '') + '">' +
				'<button type="button" class="ecrm-task__check" data-task-toggle="' + t.id + '" data-done="' + (done ? '1' : '0') + '" aria-label="Ολοκλήρωση">' + (done ? '✓' : '') + '</button>' +
				prioDot +
				'<div class="ecrm-task__body"><div class="ecrm-task__title">' + esc(t.title) + '</div>' +
				(sub ? '<div class="ecrm-task__sub">' + sub + '</div>' : '') +
				(t.note ? '<div class="ecrm-task__note">' + esc(t.note) + '</div>' : '') + '</div>' +
				'<div class="ecrm-task__due">' + taskDue(t) + '</div>' +
				'<button type="button" class="ecrm-task__rm" data-task-del="' + t.id + '" aria-label="Διαγραφή">✕</button>' +
				'</li>';
		}).join('') : '<div class="ecrm-empty">Καμία εργασία.</div>';

		view.innerHTML =
			'<header class="ecrm-head ecrm-head--row"><div><div class="ecrm-eyebrow">Παρακολούθηση</div><h2 class="ecrm-title">Εργασίες</h2>' +
			'<p class="ecrm-sub">Υπενθυμίσεις & επανακλήσεις</p></div>' + scopeToggle + '</header>' +
			addForm +
			'<div class="ecrm-card"><div class="ecrm-tabs">' + tabsHTML + '</div><ul class="ecrm-tasklist">' + rows + '</ul></div>';

		// wiring
		view.querySelectorAll('[data-tfilter]').forEach(function (b) { b.addEventListener('click', function () { tasksState.filter = this.getAttribute('data-tfilter'); loadTasks(); }); });
		view.querySelectorAll('[data-tscope]').forEach(function (b) { b.addEventListener('click', function () { tasksState.scope = this.getAttribute('data-tscope'); loadTasks(); }); });
		view.querySelectorAll('[data-task-open]').forEach(function (a) { a.addEventListener('click', function (e) { e.preventDefault(); openDetail(+this.getAttribute('data-task-open')); }); });

		var addBtn = view.querySelector('[data-task-add]');
		if (addBtn) addBtn.addEventListener('click', function () {
			var title = view.querySelector('[data-task-title]').value.trim();
			if (!title) { toast('Συμπλήρωσε τίτλο.', false); return; }
			var body = {
				title: title,
				due_at: view.querySelector('[data-task-due]').value,
				priority: view.querySelector('[data-task-prio]').value
			};
			var asg = view.querySelector('[data-task-assignee]');
			if (asg && asg.value) body.assigned_to = +asg.value;
			var b = this; b.disabled = true;
			fetch(api('/tasks'), { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H()), body: JSON.stringify(body) })
				.then(function (r) { return r.json(); })
				.then(function (res) { if (res && res.ok) { toast('Προστέθηκε εργασία.'); loadTasks(); } else { toast((res && res.error) || 'Αποτυχία.', false); b.disabled = false; } })
				.catch(function () { toast('Σφάλμα δικτύου.', false); b.disabled = false; });
		});

		view.querySelectorAll('[data-task-toggle]').forEach(function (b) {
			b.addEventListener('click', function () {
				var id = this.getAttribute('data-task-toggle');
				var to = this.getAttribute('data-done') === '1' ? 'open' : 'done';
				fetch(api('/tasks/' + id), { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H()), body: JSON.stringify({ status: to }) })
					.then(function (r) { return r.json(); })
					.then(function () { loadTasks(); })
					.catch(function () { toast('Σφάλμα δικτύου.', false); });
			});
		});

		view.querySelectorAll('[data-task-del]').forEach(function (b) {
			b.addEventListener('click', function () {
				if (!confirm('Διαγραφή εργασίας;')) return;
				var id = this.getAttribute('data-task-del');
				fetch(api('/tasks/' + id), { method: 'DELETE', headers: H() })
					.then(function (r) { return r.json(); })
					.then(function () { loadTasks(); })
					.catch(function () { toast('Σφάλμα δικτύου.', false); });
			});
		});
	}

		// ---- quote / savings calculator --------------------------------------
	var calcData = null;
	function loadCalc() {
		var view = app.querySelector('.ecrm-view[data-view="calc"]');
		if (calcData) { renderCalc(view); return; }
		fetch(api('/providers'), { headers: H() })
			.then(function (r) { return r.json(); })
			.then(function (d) { calcData = d || { providers: [], programs: [] }; renderCalc(view); })
			.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα φόρτωσης.</div></div>'; });
	}

	function renderCalc(view) {
		var provs = (calcData && calcData.providers) || [];
		var provOpts = '<option value="">— Επιλογή —</option>' + provs.map(function (p) { return '<option value="' + p.id + '">' + esc(p.name) + '</option>'; }).join('');

		view.innerHTML =
			'<header class="ecrm-head"><div class="ecrm-eyebrow">Εργαλείο πώλησης</div><h2 class="ecrm-title">Υπολογισμός Προσφοράς</h2>' +
			'<p class="ecrm-sub">Εκτίμηση ετήσιου κόστους & οφέλους έναντι του τρέχοντος παρόχου</p></header>' +
			'<div class="ecrm-card"><div class="ecrm-calc">' +
				'<div class="ecrm-calc__grid">' +
					'<label class="ecrm-field"><span>Ετήσια κατανάλωση (kWh)</span><input type="number" class="ecrm-input" data-c="consumption" placeholder="π.χ. 3500"></label>' +
					'<label class="ecrm-field"><span>Όνομα πελάτη (προαιρετικό)</span><input type="text" class="ecrm-input" data-c="customer"></label>' +
				'</div>' +
				'<div class="ecrm-calc__cols">' +
					'<div class="ecrm-calc__box"><div class="ecrm-calc__h">Τρέχων πάροχος</div>' +
						'<label class="ecrm-field"><span>Τιμή €/kWh</span><input type="number" step="0.00001" class="ecrm-input" data-c="current_price" placeholder="π.χ. 0.18"></label>' +
						'<label class="ecrm-field"><span>Πάγιο €/μήνα</span><input type="number" step="0.01" class="ecrm-input" data-c="current_fixed" placeholder="π.χ. 5"></label>' +
					'</div>' +
					'<div class="ecrm-calc__box ecrm-calc__box--offer"><div class="ecrm-calc__h">Πρόταση</div>' +
						'<label class="ecrm-field"><span>Πάροχος</span><select class="ecrm-input" data-c="provider">' + provOpts + '</select></label>' +
						'<label class="ecrm-field"><span>Πρόγραμμα</span><select class="ecrm-input" data-c="program"><option value="">—</option></select></label>' +
						'<label class="ecrm-field"><span>Τιμή €/kWh</span><input type="number" step="0.00001" class="ecrm-input" data-c="offered_price" placeholder="0.149"></label>' +
						'<label class="ecrm-field"><span>Πάγιο €/μήνα</span><input type="number" step="0.01" class="ecrm-input" data-c="offered_fixed" placeholder="5"></label>' +
					'</div>' +
				'</div>' +
				'<div class="ecrm-calc__result" data-calc-result></div>' +
				'<div class="ecrm-calc__actions"><button type="button" class="ecrm-btn ecrm-btn--primary" data-calc-pdf>📄 PDF Προσφοράς</button></div>' +
				'<p class="ecrm-hint">Η εκτίμηση αφορά χρέωση ενέργειας + πάγιο. Ρυθμιζόμενες χρεώσεις, φόροι και δημοτικά τέλη είναι κοινά μεταξύ παρόχων και δεν υπολογίζονται.</p>' +
			'</div></div>';

		var get = function (k) { var el = view.querySelector('[data-c="' + k + '"]'); return el ? el.value : ''; };
		var num = function (k) { return parseFloat(get(k)) || 0; };

		function recompute() {
			var cons = num('consumption');
			var ca = cons * num('current_price') + 12 * num('current_fixed');
			var oa = cons * num('offered_price') + 12 * num('offered_fixed');
			var box = view.querySelector('[data-calc-result]');
			if (!cons || (!num('current_price') && !num('offered_price'))) { box.innerHTML = ''; return; }
			var save = ca - oa, pct = ca > 0 ? (100 * save / ca) : 0, pos = save >= 0;
			box.innerHTML =
				'<div class="ecrm-calc__cmp"><div><span>Τρέχον / έτος</span><strong>' + ca.toFixed(2) + ' €</strong></div>' +
				'<div><span>Πρόταση / έτος</span><strong>' + oa.toFixed(2) + ' €</strong></div></div>' +
				'<div class="ecrm-calc__save ' + (pos ? 'is-pos' : 'is-neg') + '">' +
				'<span>' + (pos ? 'Ετήσια εξοικονόμηση' : 'Ετήσια διαφορά') + '</span>' +
				'<strong>' + Math.abs(save).toFixed(2) + ' € <small>(' + pct.toFixed(1) + '%)</small></strong></div>';
		}

		// programs filtered by provider; auto-fill price on program pick
		view.querySelector('[data-c="provider"]').addEventListener('change', function () {
			var pid = +this.value;
			var sel = view.querySelector('[data-c="program"]');
			var progs = ((calcData && calcData.programs) || []).filter(function (g) { return +g.provider_id === pid; });
			sel.innerHTML = '<option value="">—</option>' + progs.map(function (g) { return '<option value="' + g.id + '">' + esc(g.name) + '</option>'; }).join('');
		});
		view.querySelector('[data-c="program"]').addEventListener('change', function () {
			var gid = +this.value;
			var g = ((calcData && calcData.programs) || []).filter(function (x) { return +x.id === gid; })[0];
			if (g) {
				if (g.price_kwh != null && g.price_kwh !== '') view.querySelector('[data-c="offered_price"]').value = g.price_kwh;
				if (g.fixed_charge != null && g.fixed_charge !== '') view.querySelector('[data-c="offered_fixed"]').value = g.fixed_charge;
				recompute();
			}
		});

		view.querySelectorAll('[data-c]').forEach(function (el) { el.addEventListener('input', recompute); });

		view.querySelector('[data-calc-pdf]').addEventListener('click', function () {
			if (!num('consumption')) { toast('Συμπλήρωσε κατανάλωση.', false); return; }
			var provSel = view.querySelector('[data-c="provider"]'), progSel = view.querySelector('[data-c="program"]');
			var body = {
				consumption: num('consumption'),
				current_price: num('current_price'), current_fixed: num('current_fixed'),
				offered_price: num('offered_price'), offered_fixed: num('offered_fixed'),
				customer_name: get('customer'),
				provider_name: provSel.options[provSel.selectedIndex] ? provSel.options[provSel.selectedIndex].text : '',
				program_name: progSel.options[progSel.selectedIndex] ? progSel.options[progSel.selectedIndex].text : ''
			};
			var b = this; b.disabled = true; var t = b.textContent; b.textContent = 'Δημιουργία…';
			fetch(api('/quote/pdf'), { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H()), body: JSON.stringify(body) })
				.then(function (r) { return r.json(); })
				.then(function (d) {
					if (!d || !d.ok) { toast((d && d.error) || 'Αποτυχία.', false); return; }
					var bin = atob(d.b64), len = bin.length, arr = new Uint8Array(len);
					for (var i = 0; i < len; i++) arr[i] = bin.charCodeAt(i);
					var url = URL.createObjectURL(new Blob([arr], { type: d.mime || 'application/pdf' }));
					var a = document.createElement('a'); a.href = url; a.download = d.filename || 'prosfora.pdf'; document.body.appendChild(a); a.click(); a.remove();
					setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
				})
				.catch(function () { toast('Σφάλμα δικτύου.', false); })
				.finally(function () { b.disabled = false; b.textContent = t; });
		});
	}

		// ---- live team dashboard ----------------------------------------------
	var teamLiveTimer = null;
	function loadTeamLive() {
		var view = app.querySelector('.ecrm-view[data-view="teamlive"]');
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
			var v = app.querySelector('.ecrm-view[data-view="teamlive"]');
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
				'<td class="ecrm-tlnum">' + m.month + '</td>' +
				'<td class="ecrm-tlnum">' + (m.pending ? '<span class="ecrm-pillwarn">' + m.pending + '</span>' : '0') + '</td>' +
				'<td class="ecrm-tlnum">' + m.routed + '</td>' +
				'<td class="ecrm-tlnum">' + (m.open_tasks ? '<span class="ecrm-pillwarn">' + m.open_tasks + '</span>' : '0') + '</td>' +
				'<td class="ecrm-muted">' + last + '</td>' +
				'</tr>';
		}).join('');
		if (!(d.members || []).length) rows = '<tr><td colspan="7" class="ecrm-empty">Δεν υπάρχουν μέλη ομάδας.</td></tr>';

		view.innerHTML =
			'<header class="ecrm-head ecrm-head--row"><div><div class="ecrm-eyebrow">Πραγματικός χρόνος</div><h2 class="ecrm-title">Ομάδα Live</h2>' +
			'<p class="ecrm-sub">Δραστηριότητα ομάδας · αυτόματη ανανέωση κάθε 30″</p></div>' +
			'<span class="ecrm-live"><span class="ecrm-live__dot"></span> ' + esc(d.ts || '') + '</span></header>' +
			cards +
			'<div class="ecrm-card"><div class="ecrm-tablewrap"><table class="ecrm-table"><thead><tr>' +
			'<th>Μέλος</th><th>Σήμερα</th><th>Μήνας</th><th>Εκκρεμ.</th><th>Δρομ/καν</th><th>Εργασίες</th><th>Τελ. δραστηριότητα</th>' +
			'</tr></thead><tbody>' + rows + '</tbody></table></div></div>';
	}

		// ---- knowledge base ---------------------------------------------------
	var kbState = { q: '', energy: '', section: '', type: '', provider: 0 };
	var kbT;
	function loadKB() {
		var view = app.querySelector('.ecrm-view[data-view="kb"]');
		var qs = '?q=' + encodeURIComponent(kbState.q) + '&energy=' + kbState.energy + '&section=' + kbState.section + '&type=' + kbState.type + '&provider=' + kbState.provider;
		fetch(api('/kb') + qs, { headers: H() })
			.then(function (r) { return r.json(); })
			.then(function (d) {
				if (!d || !d.ok) { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα.</div></div>'; return; }
				renderKB(view, d);
			})
			.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα δικτύου.</div></div>'; });
	}

	function kbChip(group, val, label, active) {
		return '<button type="button" class="ecrm-chip2' + (active ? ' is-on' : '') + '" data-kbf="' + group + '" data-kbv="' + esc(val) + '">' + esc(label) + '</button>';
	}

	function renderKB(view, d) {
		var sections = d.sections || {};
		var types = d.types || {};
		var energyChips = [['', 'Όλα'], ['power', 'Ρεύμα'], ['gas', 'Αέριο']]
			.map(function (e) { return kbChip('energy', e[0], e[1], kbState.energy === e[0]); }).join('');
		var sectionChips = [['', 'Όλες']].concat(Object.keys(sections).map(function (k) { return [k, sections[k]]; }))
			.map(function (e) { return kbChip('section', e[0], e[1], kbState.section === e[0]); }).join('');
		var typeChips = [['', 'Όλοι']].concat(Object.keys(types).map(function (k) { return [k, types[k]]; }))
			.map(function (e) { return kbChip('type', e[0], e[1], kbState.type === e[0]); }).join('');

		var badge = function (cls, txt) { return txt ? '<span class="ecrm-kbbadge ' + cls + '">' + esc(txt) + '</span>' : ''; };

		var groupsHTML = (d.groups || []).map(function (g) {
			var entries = g.entries.map(function (e) {
				return '<div class="ecrm-kbentry" data-kbentry>' +
					'<button type="button" class="ecrm-kbentry__head" data-kbtoggle>' +
						'<span class="ecrm-kbentry__title">' + esc(e.title) + '</span>' +
						'<span class="ecrm-kbentry__badges">' +
							badge('is-energy-' + (e.energy || 'all'), e.energy_label) +
							badge('is-section', e.section_label) +
							badge('is-type', e.type_label) +
						'</span><span class="ecrm-kbentry__chev">›</span>' +
					'</button>' +
					'<div class="ecrm-kbentry__body" hidden>' + (e.body || '') + '</div>' +
				'</div>';
			}).join('');
			return '<div class="ecrm-kbgroup"><div class="ecrm-kbgroup__head">' + esc(g.provider) +
				' <span class="ecrm-kbgroup__count">' + g.entries.length + ' ενότητες</span></div>' + entries + '</div>';
		}).join('');

		if (!(d.groups || []).length) { groupsHTML = '<div class="ecrm-card"><div class="ecrm-empty">Δεν βρέθηκαν ενότητες.</div></div>'; }

		view.innerHTML =
			'<header class="ecrm-head"><div class="ecrm-eyebrow">Αναφορά</div><h2 class="ecrm-title">Βάση Γνώσης</h2>' +
			'<p class="ecrm-sub">Δικαιολογητικά, εγγυήσεις & χρεώσεις ανά πάροχο</p></header>' +
			'<div class="ecrm-kbsearchbox">' +
				'<div class="ecrm-kbsearchbox__title">Τι χρειάζεσαι για να κλείσεις τη σύμβαση;</div>' +
				'<div class="ecrm-kbsearchbox__q">🔎 <input type="text" data-kbq placeholder="Αναζήτηση ή ρώτησε τη Λίτσα…" value="' + esc(kbState.q) + '">' +
					'<button type="button" class="ecrm-kbask" data-kbask>✨ Ρώτησε τη Λίτσα</button></div>' +
				'<div class="ecrm-kbanswer" data-kbanswer hidden></div>' +
				'<div class="ecrm-kbfilters">' +
					'<div class="ecrm-kbfilter"><span>Ενέργεια</span>' + energyChips + '</div>' +
					'<div class="ecrm-kbfilter"><span>Ενότητα</span>' + sectionChips + '</div>' +
					'<div class="ecrm-kbfilter"><span>Τύπος</span>' + typeChips + '</div>' +
				'</div>' +
			'</div>' +
			'<div class="ecrm-kblist">' + groupsHTML + '</div>';

		// wiring
		var qEl = view.querySelector('[data-kbq]');
		qEl.addEventListener('input', function () { kbState.q = this.value; clearTimeout(kbT); kbT = setTimeout(loadKB, 300); });

		function kbAsk() {
			var q = (qEl.value || '').trim();
			var box = view.querySelector('[data-kbanswer]');
			if (!q) { toast('Γράψε μια ερώτηση.', false); return; }
			box.hidden = false;
			box.innerHTML = '<div class="ecrm-kbanswer__loading">Η Λίτσα ψάχνει στη Βάση Γνώσης…</div>';
			fetch(api('/kb/ask'), { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H()), body: JSON.stringify({ q: q }) })
				.then(function (r) { return r.json(); })
				.then(function (d) {
					if (!d || !d.ok) { box.innerHTML = '<div class="ecrm-kbanswer__err">' + esc((d && d.error) || 'Αποτυχία.') + '</div>'; return; }
					var html = esc(d.reply).replace(/\n/g, '<br>');
					box.innerHTML = '<div class="ecrm-kbanswer__head">✨ Λίτσα</div><div class="ecrm-kbanswer__body">' + html + '</div>' +
						'<button type="button" class="ecrm-kbanswer__close" data-kbclose>Κλείσιμο</button>';
					box.querySelector('[data-kbclose]').addEventListener('click', function () { box.hidden = true; box.innerHTML = ''; });
				})
				.catch(function () { box.innerHTML = '<div class="ecrm-kbanswer__err">Σφάλμα δικτύου.</div>'; });
		}
		view.querySelector('[data-kbask]').addEventListener('click', kbAsk);
		qEl.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); kbAsk(); } });
		view.querySelectorAll('[data-kbf]').forEach(function (b) {
			b.addEventListener('click', function () { kbState[this.getAttribute('data-kbf')] = this.getAttribute('data-kbv'); loadKB(); });
		});
		view.querySelectorAll('[data-kbtoggle]').forEach(function (b) {
			b.addEventListener('click', function () {
				var body = this.parentNode.querySelector('.ecrm-kbentry__body');
				var open = !body.hidden; body.hidden = open;
				this.parentNode.classList.toggle('is-open', !open);
			});
		});
		// keep focus in the search field after re-render
		if (document.activeElement !== qEl && kbState.q) { qEl.focus(); qEl.setSelectionRange(qEl.value.length, qEl.value.length); }
	}

		// ---- leads / funnel ---------------------------------------------------
	var leadsState = { stage: '', q: '', editing: null, showForm: false };
	var leadsT;
	function loadLeads() {
		var view = app.querySelector('.ecrm-view[data-view="leads"]');
		var qs = '?stage=' + encodeURIComponent(leadsState.stage) + '&q=' + encodeURIComponent(leadsState.q);
		fetch(api('/leads') + qs, { headers: H() })
			.then(function (r) { return r.json(); })
			.then(function (d) {
				if (!d || !d.ok) { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα.</div></div>'; return; }
				renderLeads(view, d);
			})
			.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα δικτύου.</div></div>'; });
	}

	function leadCbInput(v) {
		if (!v) return '';
		// 'YYYY-MM-DD HH:MM:SS' -> 'YYYY-MM-DDTHH:MM'
		return v.replace(' ', 'T').slice(0, 16);
	}

	function renderLeads(view, d) {
		var stages = d.stages || {}, sources = d.sources || {}, counts = d.counts || {};
		var sChips = [['', 'Όλα']].concat(Object.keys(stages).map(function (k) { return [k, stages[k]]; }))
			.map(function (e) {
				var n = e[0] === '' ? '' : (counts[e[0]] ? ' (' + counts[e[0]] + ')' : '');
				return '<button type="button" class="ecrm-chip2' + (leadsState.stage === e[0] ? ' is-on' : '') + '" data-lstage="' + esc(e[0]) + '">' + esc(e[1]) + n + '</button>';
			}).join('');

		var ed = leadsState.editing || {};
		var opts = function (map, sel) { return '<option value="">—</option>' + Object.keys(map).map(function (k) { return '<option value="' + esc(k) + '"' + (sel === k ? ' selected' : '') + '>' + esc(map[k]) + '</option>'; }).join(''); };
		var energyMap = { power: 'Ρεύμα', gas: 'Αέριο', mobile: 'Κινητή' };

		var form = !leadsState.showForm ? '' :
			'<div class="ecrm-card ecrm-leadform">' +
				'<div class="ecrm-leadform__grid">' +
					'<label class="ecrm-field"><span class="ecrm-field__label">Όνομα *</span><input class="ecrm-input" data-lf="name" value="' + esc(ed.name || '') + '"></label>' +
					'<label class="ecrm-field"><span class="ecrm-field__label">Τηλέφωνο</span><input class="ecrm-input" data-lf="phone" value="' + esc(ed.phone || '') + '"></label>' +
					'<label class="ecrm-field"><span class="ecrm-field__label">Email</span><input class="ecrm-input" data-lf="email" value="' + esc(ed.email || '') + '"></label>' +
					'<label class="ecrm-field"><span class="ecrm-field__label">Πηγή</span><select class="ecrm-input" data-lf="source">' + opts(sources, ed.source) + '</select></label>' +
					'<label class="ecrm-field"><span class="ecrm-field__label">Ενδιαφέρον για</span><select class="ecrm-input" data-lf="energy_type">' + opts(energyMap, ed.energy_type) + '</select></label>' +
					'<label class="ecrm-field"><span class="ecrm-field__label">Στάδιο</span><select class="ecrm-input" data-lf="stage">' + Object.keys(stages).map(function (k) { return '<option value="' + k + '"' + ((ed.stage || 'new') === k ? ' selected' : '') + '>' + esc(stages[k]) + '</option>'; }).join('') + '</select></label>' +
					'<label class="ecrm-field"><span class="ecrm-field__label">Επανάκληση</span><input type="datetime-local" class="ecrm-input" data-lf="callback_at" value="' + esc(leadCbInput(ed.callback_at)) + '"></label>' +
					'<label class="ecrm-field ecrm-field--wide"><span class="ecrm-field__label">Σημείωση ενδιαφέροντος</span><input class="ecrm-input" data-lf="interest" value="' + esc(ed.interest || '') + '" placeholder="π.χ. αλλαγή παρόχου ρεύματος, 2 παροχές"></label>' +
					'<label class="ecrm-field ecrm-field--wide"><span class="ecrm-field__label">Σημειώσεις</span><textarea class="ecrm-textarea" data-lf="notes" rows="2">' + esc(ed.notes || '') + '</textarea></label>' +
				'</div>' +
				'<div class="ecrm-leadform__bar"><button type="button" class="ecrm-btn ecrm-btn--primary" data-lsave>' + (ed.id ? 'Αποθήκευση' : 'Προσθήκη lead') + '</button>' +
					'<button type="button" class="ecrm-btn ecrm-btn--ghost" data-lcancel>Άκυρο</button></div>' +
			'</div>';

		var now = Date.now();
		var cards = (d.leads || []).map(function (l) {
			var cb = '';
			if (l.callback_at) {
				var due = new Date(l.callback_at.replace(' ', 'T') + 'Z').getTime();
				var overdue = due <= now && l.stage !== 'won' && l.stage !== 'lost';
				cb = '<span class="ecrm-leadcb' + (overdue ? ' is-over' : '') + '">📞 ' + esc(fmtDate(l.callback_at)) + '</span>';
			}
			var tel = l.phone ? '<a class="ecrm-leadtel" href="tel:' + esc(l.phone) + '">' + esc(l.phone) + '</a>' : '';
			var conv = (l.stage === 'won' && l.contract_id) ?
				'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-lopen="' + l.contract_id + '">Άνοιγμα σύμβασης</button>' :
				'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-lconv="' + l.id + '">➜ Μετατροπή σε σύμβαση</button>';
			return '<div class="ecrm-leadcard ecrm-stage-' + esc(l.stage) + '">' +
				'<div class="ecrm-leadcard__top"><div><strong>' + esc(l.name) + '</strong> ' + (l.energy_type ? '<span class="ecrm-muted">· ' + esc(energyMap[l.energy_type] || l.energy_type) + '</span>' : '') +
					'<div class="ecrm-leadmeta">' + tel + (l.source_label ? ' <span class="ecrm-muted">· ' + esc(l.source_label) + '</span>' : '') + '</div></div>' +
					'<span class="ecrm-leadstage">' + esc(l.stage_label) + '</span></div>' +
				(l.interest ? '<div class="ecrm-leadint">' + esc(l.interest) + '</div>' : '') +
				'<div class="ecrm-leadcard__bar">' + cb +
					'<span class="ecrm-leadcard__actions">' + conv +
						'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-ledit="' + l.id + '">Επεξεργασία</button>' +
						'<button type="button" class="ecrm-iconbtn" data-ldel="' + l.id + '" title="Διαγραφή">🗑</button>' +
					'</span></div>' +
			'</div>';
		}).join('');
		if (!(d.leads || []).length) cards = '<div class="ecrm-card"><div class="ecrm-empty">Δεν υπάρχουν leads' + (leadsState.stage ? ' σε αυτό το στάδιο' : '') + '.</div></div>';

		view.innerHTML =
			'<header class="ecrm-head ecrm-head--row"><div><div class="ecrm-eyebrow">Πριν τη σύμβαση</div><h2 class="ecrm-title">Leads</h2>' +
			'<p class="ecrm-sub">Υποψήφιοι πελάτες & επανακλήσεις</p></div>' +
			'<button type="button" class="ecrm-btn ecrm-btn--primary" data-lnew>＋ Νέο Lead</button></header>' +
			'<div class="ecrm-leadfilters"><div class="ecrm-search"><input type="search" class="ecrm-input" placeholder="Αναζήτηση ονόματος, τηλεφώνου, ενδιαφέροντος…" value="' + esc(leadsState.q) + '" data-lq></div></div>' +
			'<div class="ecrm-kbfilter ecrm-leadstages">' + sChips + '</div>' +
			form +
			'<div class="ecrm-leadlist">' + cards + '</div>';

		// wiring
		view.querySelector('[data-lnew]').addEventListener('click', function () { leadsState.editing = {}; leadsState.showForm = true; renderLeads(view, d); });
		var lq = view.querySelector('[data-lq]');
		lq.addEventListener('input', function () { leadsState.q = this.value; clearTimeout(leadsT); leadsT = setTimeout(loadLeads, 300); });
		if (leadsState.q) { lq.focus(); lq.setSelectionRange(lq.value.length, lq.value.length); }
		view.querySelectorAll('[data-lstage]').forEach(function (b) { b.addEventListener('click', function () { leadsState.stage = this.getAttribute('data-lstage'); loadLeads(); }); });

		if (leadsState.showForm) {
			view.querySelector('[data-lcancel]').addEventListener('click', function () { leadsState.showForm = false; leadsState.editing = null; renderLeads(view, d); });
			view.querySelector('[data-lsave]').addEventListener('click', function () {
				var body = {};
				view.querySelectorAll('[data-lf]').forEach(function (el) { body[el.getAttribute('data-lf')] = el.value; });
				if (!body.name || !body.name.trim()) { toast('Το όνομα είναι υποχρεωτικό.', false); return; }
				var url = api('/leads') + (leadsState.editing && leadsState.editing.id ? '/' + leadsState.editing.id : '');
				fetch(url, { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H()), body: JSON.stringify(body) })
					.then(function (r) { return r.json(); })
					.then(function (res) { if (res && res.ok) { toast('Αποθηκεύτηκε.'); leadsState.showForm = false; leadsState.editing = null; loadLeads(); } else { toast((res && res.error) || 'Αποτυχία.', false); } })
					.catch(function () { toast('Σφάλμα δικτύου.', false); });
			});
		}

		view.querySelectorAll('[data-ledit]').forEach(function (b) {
			b.addEventListener('click', function () {
				var id = +this.getAttribute('data-ledit');
				var lead = (d.leads || []).filter(function (x) { return x.id === id; })[0];
				if (lead) { leadsState.editing = lead; leadsState.showForm = true; renderLeads(view, d); window.scrollTo({ top: 0, behavior: 'smooth' }); }
			});
		});
		view.querySelectorAll('[data-ldel]').forEach(function (b) {
			b.addEventListener('click', function () {
				if (!confirm('Διαγραφή lead;')) return;
				fetch(api('/leads/' + this.getAttribute('data-ldel')), { method: 'DELETE', headers: H() })
					.then(function (r) { return r.json(); }).then(function (res) { if (res && res.ok) loadLeads(); });
			});
		});
		view.querySelectorAll('[data-lconv]').forEach(function (b) {
			b.addEventListener('click', function () {
				if (!confirm('Δημιουργία πρόχειρης σύμβασης από αυτό το lead;')) return;
				var bb = this; bb.disabled = true;
				fetch(api('/leads/' + this.getAttribute('data-lconv') + '/convert'), { method: 'POST', headers: H() })
					.then(function (r) { return r.json(); })
					.then(function (res) {
						if (res && res.ok && res.contract_id) { toast('Δημιουργήθηκε πρόχειρη σύμβαση.'); go('contracts'); setTimeout(function () { openDetail(res.contract_id); }, 60); }
						else { bb.disabled = false; toast((res && res.error) || 'Αποτυχία.', false); }
					})
					.catch(function () { bb.disabled = false; toast('Σφάλμα δικτύου.', false); });
			});
		});
		view.querySelectorAll('[data-lopen]').forEach(function (b) {
			b.addEventListener('click', function () { var id = +this.getAttribute('data-lopen'); go('contracts'); setTimeout(function () { openDetail(id); }, 60); });
		});
	}

		// ---- customers --------------------------------------------------------
	var customersState = { q: '', scope: 'own' };
	function loadCustomers() {
		var view = app.querySelector('.ecrm-view[data-view="customers"]');
		fetch(api('/customers') + '?scope=' + customersState.scope + '&q=' + encodeURIComponent(customersState.q), { headers: H() })
			.then(function (r) { return r.json(); })
			.then(function (d) {
				if (!d || !d.ok) { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα.</div></div>'; return; }
				var rows = (d.rows || []).map(function (c) {
					return '<tr>' +
						'<td><span class="ecrm-cell-cust"><span class="ecrm-cell-mark ecrm-cell-mark--cust" style="--h:' + tint(c.name) + '">' + esc(initials(c.name)) + '</span><span>' + esc(c.name) + '</span></span></td>' +
						'<td class="ecrm-mono">' + esc(c.afm || '—') + '</td>' +
						'<td>' + esc(c.phone || '—') + '</td>' +
						'<td>' + esc(c.email || '—') + '</td>' +
						'<td><span class="ecrm-tariff">' + c.contracts + '</span></td>' +
						'<td class="ecrm-muted">' + (c.last_at ? fmtDate(c.last_at) : '—') + '</td>' +
						'</tr>';
				}).join('');
				var table = (d.rows && d.rows.length)
					? '<div class="ecrm-tablewrap"><table class="ecrm-table"><thead><tr><th>Πελάτης</th><th>ΑΦΜ</th><th>Τηλέφωνο</th><th>Email</th><th>Συμβάσεις</th><th>Τελευταία</th></tr></thead><tbody>' + rows + '</tbody></table></div>'
					: '<div class="ecrm-empty">Δεν βρέθηκαν πελάτες' + (customersState.q ? ' για «' + esc(customersState.q) + '»' : '') + '.</div>';
				view.innerHTML =
					'<header class="ecrm-head ecrm-head--row"><div class="ecrm-titlewrap"><span class="ecrm-pageicon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8" cy="11" r="2"/><path d="M5 16.5a3.5 3.5 0 0 1 6 0"/><path d="M14 9h4"/><path d="M14 13h4"/></svg></span><div><h2 class="ecrm-title">Πελάτες</h2><p class="ecrm-sub">' + (d.count || 0) + ' πελάτες · μοναδικοί ανά ΑΦΜ</p></div></div>' +
					'<div class="ecrm-scope"><button type="button" class="ecrm-scope__b' + (customersState.scope==="own"?" is-on":"") + '" data-cuscope="own">Δικά μου</button><button type="button" class="ecrm-scope__b' + (customersState.scope==="team"?" is-on":"") + '" data-cuscope="team">Ομάδας</button></div></header>' +
					'<div class="ecrm-card"><div class="ecrm-search-row"><div class="ecrm-search"><input type="search" class="ecrm-input" placeholder="Αναζήτηση ονόματος, ΑΦΜ, τηλεφώνου…" value="' + esc(customersState.q) + '" data-cusearch></div></div>' + table + '</div>';
				var se = view.querySelector('[data-cusearch]');
				if (se) { var t; se.addEventListener('input', function () { clearTimeout(t); var v = this.value; t = setTimeout(function () { customersState.q = v; loadCustomers(); }, 350); }); }
				view.querySelectorAll('[data-cuscope]').forEach(function (b) { b.addEventListener('click', function () { customersState.scope = this.getAttribute('data-cuscope'); loadCustomers(); }); });
			})
			.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα φόρτωσης.</div></div>'; });
	}

		// ---- εκκρεμότητες (follow-ups) ---------------------------------------
	function loadPending() {
		var view = app.querySelector('.ecrm-view[data-view="pending"]');
		fetch(api('/notifications') + '?scope=' + (contractsState.scope || 'own'), { headers: H() })
			.then(function (r) { return r.json(); })
			.then(function (d) { if (!d || !d.ok) { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα.</div></div>'; return; } renderPending(view, d); })
			.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα φόρτωσης.</div></div>'; });
	}

	function renderPending(view, d) {
		var rows = d.rows || [];
		var statuses = (window.ECRM && ECRM.statuses) || {};
		var body = rows.map(function (r) {
			return '<tr class="ecrm-rowlink" data-id="' + r.id + '">' +
				'<td><span class="ecrm-cell-cust"><span class="ecrm-cell-mark ecrm-cell-mark--cust" style="--h:' + tint(r.customer) + '">' + esc(initials(r.customer)) + '</span><span>' + esc(r.customer) + '</span></span></td>' +
				'<td><span class="ecrm-code">' + esc(r.code || '') + '</span></td>' +
				'<td><span class="ecrm-badge ecrm-badge--' + esc(r.status) + '">' + esc(r.status_label || statuses[r.status] || r.status) + '</span></td>' +
				'<td><span class="ecrm-agepill' + (r.stale ? ' is-stale' : '') + '">' + r.age_days + ' ημέρες</span></td>' +
				'</tr>';
		}).join('');
		var table = rows.length
			? '<div class="ecrm-tablewrap"><table class="ecrm-table"><thead><tr><th>Πελάτης</th><th>Κωδικός</th><th>Κατάσταση</th><th>Ανοιχτή για</th></tr></thead><tbody>' + body + '</tbody></table></div>'
			: '<div class="ecrm-emptybox ecrm-emptybox--big"><span class="ecrm-emptybox__ico">✓</span><div class="ecrm-emptybox__txt">Καμία εκκρεμότητα — όλα εντάξει!</div></div>';

		view.innerHTML =
			'<header class="ecrm-head ecrm-head--row"><div class="ecrm-titlewrap"><span class="ecrm-pageicon">' +
			'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v5"/><circle cx="12" cy="16.5" r=".6"/><path d="M10.3 3.8 2.6 17.5A2 2 0 0 0 4.3 20.5h15.4a2 2 0 0 0 1.7-3L13.7 3.8a2 2 0 0 0-3.4 0z"/></svg></span>' +
			'<div><h2 class="ecrm-title">Εκκρεμότητες</h2><p class="ecrm-sub">' + (d.count || 0) + ' ανοιχτές · ' + (d.stale || 0) + ' πάνω από ' + d.threshold + ' ημέρες</p></div></div>' +
			'<div class="ecrm-scope"><button type="button" class="ecrm-scope__b' + ((contractsState.scope||"own")==="own"?" is-on":"") + '" data-pscope="own">Δικά μου</button><button type="button" class="ecrm-scope__b' + (contractsState.scope==="team"?" is-on":"") + '" data-pscope="team">Ομάδας</button></div></header>' +
			'<div class="ecrm-card">' + table + '</div>';

		view.querySelectorAll('.ecrm-rowlink').forEach(function (tr) { tr.addEventListener('click', function () { openDetail(this.getAttribute('data-id')); }); });
		view.querySelectorAll('[data-pscope]').forEach(function (b) { b.addEventListener('click', function () { contractsState.scope = this.getAttribute('data-pscope'); loadPending(); }); });
	}

		// ---- λήξεις / ανανεώσεις ----------------------------------------------
	var renewState = { scope: 'own' };
	function loadRenewals() {
		var view = app.querySelector('.ecrm-view[data-view="renewals"]');
		fetch(api('/renewals') + '?scope=' + renewState.scope, { headers: H() })
			.then(function (r) { return r.json(); })
			.then(function (d) { if (!d || !d.ok) { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα.</div></div>'; return; } renderRenewals(view, d); })
			.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα φόρτωσης.</div></div>'; });
	}

	function renderRenewals(view, d) {
		var rows = d.rows || [];
		var body = rows.map(function (r) {
			var pill, cls;
			if (r.expired) { pill = 'Έληξε πριν ' + Math.abs(r.days_left) + 'η'; cls = 'is-expired'; }
			else if (r.days_left <= 30) { pill = 'Λήγει σε ' + r.days_left + 'η'; cls = 'is-soon'; }
			else { pill = 'Λήγει σε ' + r.days_left + 'η'; cls = ''; }
			return '<tr>' +
				'<td><span class="ecrm-cell-cust"><span class="ecrm-cell-mark ecrm-cell-mark--cust" style="--h:' + tint(r.customer) + '">' + esc(initials(r.customer)) + '</span><span>' + esc(r.customer) + '</span></span></td>' +
				'<td><span class="ecrm-code">' + esc(r.code || '') + '</span></td>' +
				'<td>' + esc(r.provider_name || '—') + '</td>' +
				'<td>' + (r.end_date ? fmtDate(r.end_date) : '—') + '</td>' +
				'<td><span class="ecrm-agepill ' + cls + '">' + pill + '</span></td>' +
				'<td><button type="button" class="ecrm-btn ecrm-btn--primary ecrm-btn--sm" data-renew="' + r.id + '">' + svgIcon('edit') + ' Ανανέωση</button></td>' +
				'</tr>';
		}).join('');
		var table = rows.length
			? '<div class="ecrm-tablewrap"><table class="ecrm-table"><thead><tr><th>Πελάτης</th><th>Κωδικός</th><th>Πάροχος</th><th>Λήξη</th><th>Κατάσταση</th><th></th></tr></thead><tbody>' + body + '</tbody></table></div>'
			: '<div class="ecrm-emptybox ecrm-emptybox--big"><span class="ecrm-emptybox__ico">✓</span><div class="ecrm-emptybox__txt">Καμία σύμβαση δεν λήγει σύντομα.</div></div>';
		view.innerHTML =
			'<header class="ecrm-head ecrm-head--row"><div class="ecrm-titlewrap"><span class="ecrm-pageicon">' +
			'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/><path d="M3 21v-5h5"/></svg></span>' +
			'<div><h2 class="ecrm-title">Λήξεις & Ανανεώσεις</h2><p class="ecrm-sub">' + (d.count || 0) + ' συμβάσεις λήγουν έως ' + d.window + ' ημέρες · ' + (d.soon || 0) + ' άμεσα</p></div></div>' +
			'<div class="ecrm-scope"><button type="button" class="ecrm-scope__b' + (renewState.scope==="own"?" is-on":"") + '" data-rscope="own">Δικά μου</button><button type="button" class="ecrm-scope__b' + (renewState.scope==="team"?" is-on":"") + '" data-rscope="team">Ομάδας</button></div></header>' +
			'<div class="ecrm-card">' + table + '</div>';

		view.querySelectorAll('[data-rscope]').forEach(function (b) { b.addEventListener('click', function () { renewState.scope = this.getAttribute('data-rscope'); loadRenewals(); }); });
		view.querySelectorAll('[data-renew]').forEach(function (b) {
			b.addEventListener('click', function () {
				var id = this.getAttribute('data-renew'); var btn = this; btn.disabled = true;
				fetch(api('/contracts/' + id + '/renew'), { method: 'POST', headers: H(true) })
					.then(function (r) { return r.json(); })
					.then(function (d) {
						btn.disabled = false;
						if (d && d.ok && d.contract_id) {
							toast('Δημιουργήθηκε ανανέωση — άνοιξε για επεξεργασία.');
							// load the new draft into the form
							fetch(api('/contracts/' + d.contract_id), { headers: H() })
								.then(function (r) { return r.json(); })
								.then(function (dd) { var c = dd.contract || dd; openEdit(c); });
						} else { toast((d && d.error) || 'Αποτυχία.', false); }
					})
					.catch(function () { btn.disabled = false; toast('Σφάλμα δικτύου.', false); });
			});
		});
	}

		// ---- boot -------------------------------------------------------------
	// Back/forward navigation via URL hash.
	window.addEventListener('popstate', function () {
		var v = (location.hash || '').slice(1);
		if (VIEWS.indexOf(v) < 0) v = 'dashboard';
		suppressHash = true; go(v); suppressHash = false;
	});

	// Initial view: respect a #hash so links are copy/paste-able and bookmarkable.
	(function () {
		var v = (location.hash || '').slice(1);
		if (VIEWS.indexOf(v) < 0) v = 'dashboard';
		suppressHash = true; go(v); suppressHash = false;
	})();
})();
