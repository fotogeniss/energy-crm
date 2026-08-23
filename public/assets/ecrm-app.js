import { api, esc, fetch, H } from '@energy-crm/util';
import { wire } from '@energy-crm/navigate';
import { openDetail } from '@energy-crm/view-detail';
import { loadContracts, setContractsFilter } from '@energy-crm/view-contracts';
import { loadPending } from '@energy-crm/view-pending';
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
import { openPartner } from '@energy-crm/view-partner';
import { loadTeamLive } from '@energy-crm/view-team-live';

/* Energy CRM — το κέλυφος, και μόνο αυτό.
 *
 * Ό,τι απέμεινε αφού το Βήμα 9 έβγαλε τις δεκαπέντε οθόνες σε δικά τους
 * modules: η δρομολόγηση (hash ↔ όψη), το συρτάρι του κινητού, η καθολική
 * αναζήτηση της μπάρας, το καμπανάκι ειδοποιήσεων, και το boot.
 *
 * Το go() είναι ο ένας πίνακας που ξέρει ποια συνάρτηση φορτώνει ποια οθόνη.
 * Είναι σκόπιμα βαρετός: κάθε γραμμή του είναι ένα import, οπότε το γράφημα
 * εξαρτήσεων διαβάζεται από την κορυφή του αρχείου χωρίς να ψάξει κανείς.
 *
 * Οι τρεις συναρτήσεις που έχουν ανάγκη οι όψεις — go, openDetail, openEdit —
 * δίνονται μία φορά στο @energy-crm/navigate με wire(). Καμία όψη δεν εισάγει
 * το κέλυφος· έτσι δεν κλείνει κύκλος.
 *
 * Εξαρτάται από το ECRM (rest, nonce) και το window.ECRMForm (init της φόρμας). */
(function () {
	'use strict';

	var app = document.getElementById('ecrm-app');
	if (!app || typeof ECRM === 'undefined') return;

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
	// ---- εμφάνιση: ανοιχτό / σκούρο -----------------------------------------
	// Το data-theme το γράφει ήδη η PHP στο ίδιο το .ecrm, οπότε εδώ μένει μόνο
	// η εναλλαγή. Αλλάζει ΠΡΩΤΑ η οθόνη και μετά ειδοποιείται ο διακομιστής: ο
	// χρήστης δεν περιμένει δίκτυο για να δει το κλικ του. Αν το POST αποτύχει,
	// η επιλογή ισχύει για τη συνεδρία και χάνεται στο επόμενο refresh — δεν
	// γυρίζουμε την οθόνη πίσω, γιατί ένα φλας είναι χειρότερο από μια χαμένη
	// προτίμηση.
	// Ένα μόνο σημείο εναλλαγής πλέον — ο διακόπτης του topbar (23/08, το παλιό
	// κουμπί στο footer του πλαϊνού μενού αφαιρέθηκε). querySelectorAll μένει
	// ούτως ή άλλως ανεκτικό αν ξαναχρειαστεί δεύτερο σημείο κάποια στιγμή.
	var themeBtns = app.querySelectorAll('[data-theme-toggle]');
	themeBtns.forEach(function (themeBtn) {
		themeBtn.addEventListener('click', function () {
			var next = app.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
			app.setAttribute('data-theme', next);
			themeBtns.forEach(function (b) {
				if (b.hasAttribute('aria-checked')) b.setAttribute('aria-checked', next === 'dark' ? 'true' : 'false');
			});
			fetch(api('/theme'), {
				method: 'POST',
				headers: Object.assign({ 'Content-Type': 'application/json' }, H()),
				body: JSON.stringify({ theme: next })
			}).catch(function () {});
		});
	});

	app.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-go]');
		if (!btn) return;
		e.preventDefault();
		var g = btn.getAttribute('data-go');
		// Ένα KPI που ξέρει ποια κατάσταση δείχνει φιλτράρει τη λίστα αντί απλώς
		// να τη δείχνει. Μπαίνει ΠΡΙΝ το go(), γιατί το go() είναι που φορτώνει —
		// αλλιώς θα γινόταν δεύτερο fetch για ένα κλικ.
		if (g === 'contracts' && btn.hasAttribute('data-status')) {
			setContractsFilter(btn.getAttribute('data-status'));
		}
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

	// «Περισσότερα» στο πλαϊνό μενού — πρόταση #2 του χαμηλής-εξοικείωσης πάσου
	// (docs/UI-NAV-MORE-COLLAPSE.html, εγκρίθηκε 23/08). Ανοιχτό από προεπιλογή
	// ΜΟΝΟ όσο δεν υπάρχει καμία αποθηκευμένη προτίμηση — δηλαδή στο πρώτο login
	// κάποιου, ώστε καμία διαδρομή να μη χαθεί αναξήτητα· μετά θυμάται ό,τι
	// διάλεξε ο χρήστης, ανοιχτό ή κλειστό.
	var moreBtn = app.querySelector('[data-nav-more-toggle]');
	var moreBody = app.querySelector('[data-nav-more-body]');
	if (moreBtn && moreBody) {
		var moreOpen = true;
		try {
			var saved = localStorage.getItem('ecrm_nav_more');
			if (saved !== null) moreOpen = saved === '1';
		} catch (e) {}
		function paintMore() {
			moreBtn.setAttribute('aria-expanded', moreOpen ? 'true' : 'false');
			moreBody.classList.toggle('is-open', moreOpen);
		}
		paintMore();
		moreBtn.addEventListener('click', function () {
			moreOpen = !moreOpen;
			paintMore();
			try { localStorage.setItem('ecrm_nav_more', moreOpen ? '1' : '0'); } catch (e) {}
		});
	}

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
			html += '<div class="ecrm-bellpanel__empty">Καμία ανοιχτή σύμβαση</div>';
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

	function openEdit(c) {
		go('new-contract');
		if (window.ECRMForm && window.ECRMForm.edit) {
			// Clear whatever a previous edit session left behind before
			// prefilling this contract. Without this, a field this contract
			// doesn't have (e.g. price_type, father_name, a supply address)
			// keeps whatever the last-opened contract left in the DOM, and
			// collect() sends it on save as though it belonged here —
			// confirmed live on crm-test, CHANGELOG 2026-08-16 (4)/(5).
			if (window.ECRMForm.reset) { window.ECRMForm.reset(); }
			// give the view a tick to become visible, then prefill
			setTimeout(function () { window.ECRMForm.edit(c); }, 30);
		}
	}

	// The three the views are allowed to call, handed over once. They are
	// declared above rather than imported, so this is the only place the
	// direction of the dependency is decided — see ecrm-navigate.js.
	wire({ go: go, openDetail: openDetail, openEdit: openEdit, openPartner: openPartner });

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
