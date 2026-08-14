import { api, can, esc, fetch, H, toast } from '@energy-crm/util';
import { wire } from '@energy-crm/navigate';
import { openExportModal } from '@energy-crm/export-modal';
import { openDetail } from '@energy-crm/view-detail';
import { loadCommissions } from '@energy-crm/view-commissions';
import { loadAnalytics } from '@energy-crm/view-analytics';
import { loadLeads } from '@energy-crm/view-leads';
import { loadRenewals } from '@energy-crm/view-renewals';
import { loadTasks } from '@energy-crm/view-tasks';
import { loadCalc } from '@energy-crm/view-calc';
import { loadCustomers } from '@energy-crm/view-customers';
import { loadDashboard } from '@energy-crm/view-dashboard';
import { initImport } from '@energy-crm/view-import';
import { loadKB } from '@energy-crm/view-kb';
import { loadNetwork } from '@energy-crm/view-network';
import { loadTeam } from '@energy-crm/view-team';
import { loadTeamLive } from '@energy-crm/view-team-live';
import { energyLabel, fmtDate, initials, svgIcon, timeAgo, tint, up } from '@energy-crm/format';

/* Energy CRM — app shell.
 * Client-side routing between views + dashboard & contracts rendering.
 * Depends on ECRM (rest, nonce) and window.ECRMForm (form init). */
(function () {
	'use strict';

	var app = document.getElementById('ecrm-app');
	if (!app || typeof ECRM === 'undefined') return;

	// Copy text to clipboard with a fallback for non-secure (HTTP) contexts where
	// navigator.clipboard is unavailable. Returns a Promise<boolean> of success.

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

	// ---- contracts list ---------------------------------------------------
	var contractsState = { status: '', q: '', scope: 'own', page: 1, pageSize: 12 };

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
		if (exportBtn) exportBtn.addEventListener('click', function () {
			openExportModal({ status: contractsState.status, scope: contractsState.scope, q: contractsState.q });
		});
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

	// The three the views are allowed to call, handed over once. They are
	// declared above rather than imported, so this is the only place the
	// direction of the dependency is decided — see ecrm-navigate.js.
	wire({ go: go, openDetail: openDetail, openEdit: openEdit });

	// ---- generic base64 download (PDF / Excel share this) ----------------

	// ---- export modal (filter + download .xlsx) --------------------------

	// ---- team -------------------------------------------------------------

	// ---- network ----------------------------------------------------------

		// ---- import provider Excel -------------------------------------------

		// ---- commissions ------------------------------------------------------

		// ---- analytics (managers) --------------------------------------------

		// ---- tasks / callbacks ------------------------------------------------

		// ---- quote / savings calculator --------------------------------------

		// ---- live team dashboard ----------------------------------------------

		// ---- knowledge base ---------------------------------------------------
	var kbT;

		// ---- leads / funnel ---------------------------------------------------
	var leadsT;

		// ---- customers --------------------------------------------------------

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
