/* Energy CRM — αναφορές προβλημάτων (339). Mockup docs/UI-TICKETS.html.
 *
 * Τρία κομμάτια:
 *   1. Συλλογή σφαλμάτων: τα τελευταία 10 λεπτά, μόνο μήνυμα και αρχείο:γραμμή
 *      -- ποτέ τιμές πεδίων. Ο server τα ξανακαθαρίζει (TicketContext).
 *   2. Το κουμπί της πάνω μπάρας (και Ctrl+Shift+B): διάλογος αναφοράς με τα
 *      τεχνικά συμπληρωμένα και ορατά, ώστε ο χρήστης να ξέρει τι στέλνει.
 *   3. Η οθόνη «Αναφορές»: λίστα με καρτέλες κατάστασης, και μία αναφορά με τη
 *      συζήτησή της. Την κατάσταση την αλλάζει μόνο ο διαχειριστής -- και το
 *      ελέγχει ο server, εδώ απλώς δεν δείχνονται τα κουμπιά.
 */

import { api, esc, fetch, H, postJson, failureText, toast, viewEl } from '@energy-crm/util';
import { timeAgo } from '@energy-crm/format';
import { openDialog } from '@energy-crm/dialog';
import { openDetail } from '@energy-crm/navigate';

var WINDOW_MS = 10 * 60 * 1000;
var MAX_ERRORS = 10;
var errors = [];

function remember(message, source, line) {
	var where = source ? String(source).split('/').pop().split('?')[0] + (line ? ':' + line : '') : '';
	var text = String(message || '').slice(0, 240) + (where ? ' (' + where + ')' : '');
	if (!text.trim()) return;
	errors.push({ t: Date.now(), text: text });
	if (errors.length > 50) errors.shift();
}

function recentErrors() {
	var since = Date.now() - WINDOW_MS;
	var out = [];
	errors.forEach(function (e) {
		if (e.t >= since && out.indexOf(e.text) < 0) out.push(e.text);
	});
	return out.slice(-MAX_ERRORS);
}

/* Συσκευή σε μία γραμμή: λειτουργικό + browser + έκδοση. */
function device() {
	var ua = navigator.userAgent || '';
	var os = /Android/.test(ua) ? 'Android' : /iPhone|iPad|iPod/.test(ua) ? 'iOS' : /Windows/.test(ua) ? 'Windows'
		: /Mac OS X/.test(ua) ? 'macOS' : /Linux/.test(ua) ? 'Linux' : 'Άλλο';
	var m = ua.match(/(Edg|OPR|SamsungBrowser|Chrome|Firefox|Version)\/(\d+)/);
	var names = { Edg: 'Edge', OPR: 'Opera', SamsungBrowser: 'Samsung Internet', Version: 'Safari' };
	var browser = m ? (names[m[1]] || m[1]) + ' ' + m[2] : 'browser';
	return os + ' · ' + browser;
}

function appEl() { return document.getElementById('ecrm-app'); }

/* Οθόνες που δεν έχουν δικό τους στοιχείο στο μενού. */
var SUBVIEWS = {
	'contract-detail': 'Αίτηση',
	'customer-card': 'Καρτέλα πελάτη',
	'partner': 'Καρτέλα συνεργάτη',
	'new-contract': 'Νέα αίτηση',
};

/* Τι βλέπει τώρα ο χρήστης: οθόνη, αίτηση (αν είναι ανοιχτή), βήμα φόρμας. */
function context() {
	var app = appEl();
	var view = app ? app.getAttribute('data-view') || '' : '';
	// Η ετικέτα από το μενού, ΟΧΙ από τον τίτλο της οθόνης: η καρτέλα πελάτη
	// και η αίτηση έχουν για τίτλο το όνομα του πελάτη.
	var nav = app && app.querySelector('.ecrm-nav__item.is-active .ecrm-nav__txt');
	var label = SUBVIEWS[view] || (nav && nav.textContent) || '';

	var ctx = {
		view: view,
		view_label: label.trim(),
		device: device(),
		viewport: window.innerWidth + '×' + window.innerHeight,
		version: (window.ECRM && ECRM.version) || '',
		errors: recentErrors(),
	};

	if (view === 'contract-detail') {
		var detail = viewEl('contract-detail');
		if (detail && detail.getAttribute('data-contract-id')) {
			ctx.contract_id = Number(detail.getAttribute('data-contract-id'));
			ctx.contract_code = detail.getAttribute('data-contract-code') || '';
		}
	}

	if (view === 'new-contract') {
		var form = app.querySelector('.ecrm-view[data-view="new-contract"] .ecrm-form');
		if (form) ctx.step = form.getAttribute('data-step') || '';
	}

	return ctx;
}

function contextRows(ctx, extra, errText) {
	var rows = [];
	var screen = ctx.view_label || ctx.view || '—';
	if (ctx.contract_code) screen = 'Αίτηση ' + ctx.contract_code;
	if (ctx.step) screen += ' · βήμα «' + ctx.step + '»';
	rows.push(['Οθόνη', screen]);
	if (ctx.role) rows.push(['Ρόλος', ctx.role]);
	rows.push(['Συσκευή', (ctx.device || '—') + (ctx.viewport ? ' · ' + ctx.viewport : '')]);
	if (ctx.version) rows.push(['Έκδοση', ctx.version]);
	var errs = ctx.errors || [];
	rows.push(['Σφάλματα', errText || (errs.length ? errs.join('\n') : 'κανένα')]);
	(extra || []).forEach(function (r) { rows.push(r); });

	return '<dl class="ecrm-tk-ctx">' + rows.map(function (r) {
		return '<dt>' + esc(r[0]) + '</dt><dd>' + esc(r[1]) + '</dd>';
	}).join('') + '</dl>';
}

/* ---- 2. Η αναφορά ------------------------------------------------------ */

export function openReport() {
	var ctx = context();
	var errNote = ctx.errors.length
		? ctx.errors.length + ' στα τελευταία 10 λεπτά (μόνο το μήνυμα)'
		: 'κανένα';

	var body =
		'<label class="ecrm-field"><span class="ecrm-field__label">Τι πήγε στραβά; <abbr title="υποχρεωτικό">*</abbr></span>' +
			'<textarea class="ecrm-textarea" rows="4" data-tk-body maxlength="4000" placeholder="Τι έκανες και τι είδες…"></textarea></label>' +
		'<label class="ecrm-tk-check"><input type="checkbox" data-tk-urgent> Με σταματάει, δεν μπορώ να συνεχίσω τη δουλειά</label>' +
		'<div class="ecrm-tk-att"><label class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm">Επισύναψη εικόνας' +
			'<input type="file" accept="image/jpeg,image/png,image/webp" data-tk-file hidden></label>' +
			'<span class="ecrm-tk-att__name" data-tk-file-name></span></div>' +
		'<div class="ecrm-tk-auto"><b>Στέλνεται αυτόματα</b>' +
			contextRows(ctx, [], errNote) +
		'</div>' +
		'<p class="ecrm-tk-gdpr">Η εικόνα μπορεί να δείχνει στοιχεία πελάτη: τη βλέπει μόνο ο διαχειριστής και σβήνεται μόλις κλείσει η αναφορά. ΑΦΜ, τηλέφωνα και email στο κείμενο κρύβονται αυτόματα.</p>' +
		'<div class="ecrm-tk-err" data-tk-err hidden></div>';

	openDialog({
		eyebrow: 'Βοήθεια',
		title: 'Αναφορά προβλήματος',
		lead: 'Γράψε τι έκανες και τι είδες. Τα τεχνικά τα στέλνουμε εμείς.',
		body: body,
		confirm: 'Αποστολή',
		onConfirm: function (ov, close, btn) {
			var text = ov.querySelector('[data-tk-body]').value.trim();
			var err = ov.querySelector('[data-tk-err]');
			if (text.length < 5) {
				err.hidden = false; err.textContent = 'Γράψε τι πήγε στραβά.';
				return;
			}
			var fd = new FormData();
			fd.append('body', text);
			fd.append('urgent', ov.querySelector('[data-tk-urgent]').checked ? '1' : '0');
			fd.append('context', JSON.stringify(ctx));
			var file = ov.querySelector('[data-tk-file]').files[0];
			if (file) fd.append('image', file);

			btn.disabled = true;
			fetch(api('/tickets'), { method: 'POST', headers: H(), body: fd })
				.then(function (r) { return r.json().then(function (d) { return { status: r.status, d: d }; }); })
				.then(function (res) {
					if (!res.d || !res.d.ok) {
						btn.disabled = false; err.hidden = false; err.textContent = failureText(res);
						return;
					}
					close();
					toast('Η αναφορά #' + res.d.id + ' στάλθηκε. Θα δεις την απάντηση στο καμπανάκι.', true);
				})
				.catch(function () { btn.disabled = false; err.hidden = false; err.textContent = 'Σφάλμα δικτύου.'; });
		},
	}).el.querySelector('[data-tk-file]').addEventListener('change', function () {
		var name = this.files[0] ? this.files[0].name + ' · ' + Math.round(this.files[0].size / 1024) + ' KB' : '';
		this.closest('.ecrm-modal').querySelector('[data-tk-file-name]').textContent = name;
	});
}

/* ---- 3. Η οθόνη ------------------------------------------------------- */

var TABS = [
	['open', 'Ανοιχτές'],
	['new', 'Νέα'],
	['in_progress', 'Σε εξέλιξη'],
	['resolved', 'Λύθηκε'],
	['not_issue', 'Δεν είναι πρόβλημα'],
];
var state = { status: 'open' };

function pill(status, label) {
	return '<span class="ecrm-tk-st ecrm-tk-st--' + esc(status) + '">' + esc(label) + '</span>';
}

function titleOf(body) {
	var first = String(body || '').split('\n')[0];
	return first.length > 90 ? first.slice(0, 88) + '…' : first;
}

function where(ctx) {
	if (!ctx) return '';
	if (ctx.contract_code) return 'Αίτηση ' + ctx.contract_code;
	return ctx.view_label || ctx.view || '';
}

export function loadTickets() {
	var view = viewEl('tickets');
	view.innerHTML = '<div class="ecrm-card"><div class="ecrm-loading">Φόρτωση…</div></div>';

	fetch(api('/tickets?status=' + encodeURIComponent(state.status)), { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (!d || !d.ok) { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">' + esc((d && d.error) || 'Σφάλμα.') + '</div></div>'; return; }
			renderList(view, d);
		})
		.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα δικτύου.</div></div>'; });
}

function renderList(view, d) {
	var counts = d.counts || {};
	counts.open = (counts['new'] || 0) + (counts.in_progress || 0);
	var title = d.can_manage || d.team ? 'Αναφορές προβλημάτων' : 'Οι αναφορές μου';
	var sub = d.can_manage ? 'Όλες οι αναφορές της εταιρείας.' : d.team ? 'Οι δικές σου και της ομάδας σου.' : 'Ό,τι έχεις αναφέρει, και τι απάντησε ο διαχειριστής.';

	var tabs = '<div class="ecrm-xsell__tabs">' + TABS.map(function (t) {
		return '<button type="button" class="ecrm-xsell__tab' + (state.status === t[0] ? ' is-on' : '') + '" data-tk-tab="' + esc(t[0]) + '">' +
			esc(t[1]) + ' <small>' + (Number(counts[t[0]]) || 0) + '</small></button>';
	}).join('') + '</div>';

	var rows = (d.rows || []).map(function (r) {
		var meta = ['#' + r.id];
		if (!r.mine) meta.push(r.reporter);
		var w = where(r.context);
		if (w) meta.push(w);
		if (r.comments) meta.push('💬 ' + r.comments);
		if (r.has_attachment) meta.push('📷');
		return '<div class="ecrm-mgrrow" data-tk-open="' + esc(r.id) + '">' +
			'<span class="ecrm-mgrrow__badge">' + pill(r.status, r.status_label) + '</span>' +
			'<span class="ecrm-mgrrow__m"><b>' + esc(titleOf(r.body)) + (r.urgent ? ' <span class="ecrm-tk-urg">· με σταματάει</span>' : '') + '</b>' +
			'<small>' + esc(meta.join(' · ')) + '</small></span>' +
			'<span class="ecrm-tk-when">' + esc(timeAgo(r.updated_at)) + '</span></div>';
	}).join('');

	view.innerHTML = '<header class="ecrm-head"><div class="ecrm-eyebrow">Βοήθεια</div><h2 class="ecrm-title">' + esc(title) + '</h2>' +
		'<p class="ecrm-sub">' + esc(sub) + '</p>' +
		'<button type="button" class="ecrm-btn ecrm-btn--primary" data-tk-new>Νέα αναφορά</button></header>' +
		tabs + '<div class="ecrm-card"><div class="ecrm-mgrgroup">' +
		(rows || '<div class="ecrm-empty">Καμία αναφορά εδώ.</div>') + '</div></div>';

	view.querySelectorAll('[data-tk-tab]').forEach(function (b) {
		b.addEventListener('click', function () { state.status = b.getAttribute('data-tk-tab'); loadTickets(); });
	});
	view.querySelectorAll('[data-tk-open]').forEach(function (row) {
		row.addEventListener('click', function () { openTicket(row.getAttribute('data-tk-open')); });
	});
	var nb = view.querySelector('[data-tk-new]');
	if (nb) nb.addEventListener('click', openReport);
}

export function openTicket(id) {
	var view = viewEl('tickets');
	view.innerHTML = '<div class="ecrm-card"><div class="ecrm-loading">Φόρτωση…</div></div>';

	fetch(api('/tickets/' + encodeURIComponent(id)), { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (!d || !d.ok) { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">' + esc((d && d.error) || 'Σφάλμα.') + '</div></div>'; return; }
			renderTicket(view, d);
		})
		.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα δικτύου.</div></div>'; });
}

function renderTicket(view, d) {
	var t = d.ticket;
	var ctx = t.context || {};

	var thread = (d.comments || []).map(function (c) {
		if (c.kind === 'status') {
			return '<div class="ecrm-tk-sys">' + esc(c.author) + ': «' + esc(c.body) + '» · ' + esc(timeAgo(c.created_at)) + '</div>';
		}
		return '<div class="ecrm-tk-msg' + (c.mine ? ' is-mine' : '') + '"><small>' + esc(c.author) + ' · ' + esc(timeAgo(c.created_at)) + '</small>' +
			'<div>' + esc(c.body) + '</div></div>';
	}).join('');

	var actions = '<button type="button" class="ecrm-btn ecrm-btn--primary" data-tk-reply>Απάντηση</button>';
	if (t.can_status) {
		[['in_progress', 'Σε εξέλιξη'], ['resolved', '✓ Λύθηκε'], ['not_issue', 'Δεν είναι πρόβλημα']].forEach(function (s) {
			if (s[0] !== t.status) actions += '<button type="button" class="ecrm-btn ecrm-btn--ghost" data-tk-status="' + esc(s[0]) + '">' + esc(s[1]) + '</button>';
		});
	}
	if (t.can_reopen) actions += '<button type="button" class="ecrm-btn ecrm-btn--ghost" data-tk-reopen>Δεν λύθηκε;</button>';

	var extra = [];
	if (t.has_attachment) extra.push(['Εικόνα', 'φορτώνεται…']);

	view.innerHTML =
		'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-tk-back>← Όλες οι αναφορές</button>' +
		'<div class="ecrm-card ecrm-tk-one">' +
			'<div class="ecrm-tk-head">' + pill(t.status, t.status_label) + (t.urgent ? ' <span class="ecrm-tk-urg">με σταματάει</span>' : '') +
			'<h2 class="ecrm-title">#' + esc(t.id) + ' · ' + esc(titleOf(t.body)) + '</h2></div>' +
			'<p class="ecrm-tk-meta">' + esc(t.reporter) + (ctx.role ? ' · ' + esc(ctx.role) : '') + ' · ' + esc(timeAgo(t.created_at)) +
				(ctx.contract_id ? ' · <button type="button" class="ecrm-tk-link" data-tk-contract="' + esc(ctx.contract_id) + '">Άνοιγμα αίτησης ' + esc(ctx.contract_code || '') + '</button>' : '') + '</p>' +
			'<div class="ecrm-tk-body">' + esc(t.body) + '</div>' +
			'<div class="ecrm-tk-auto"><b>Τεχνικά</b>' + contextRows(ctx, extra) + '</div>' +
			'<div class="ecrm-tk-img" data-tk-img hidden></div>' +
			'<div class="ecrm-tk-thread">' + thread + '</div>' +
			'<textarea class="ecrm-textarea" rows="3" data-tk-text maxlength="4000" placeholder="Γράψε απάντηση…"></textarea>' +
			'<div class="ecrm-tk-act">' + actions + '</div>' +
			(t.can_status ? '<p class="ecrm-tk-gdpr">Με «Λύθηκε» ή «Δεν είναι πρόβλημα» η εικόνα σβήνεται οριστικά.</p>' : '') +
		'</div>';

	view.querySelector('[data-tk-back]').addEventListener('click', loadTickets);

	var cb = view.querySelector('[data-tk-contract]');
	if (cb) cb.addEventListener('click', function () { openDetail(cb.getAttribute('data-tk-contract')); });

	view.querySelector('[data-tk-reply]').addEventListener('click', function () {
		var text = view.querySelector('[data-tk-text]').value.trim();
		if (!text) { toast('Γράψε την απάντηση.'); return; }
		postJson(api('/tickets/' + t.id + '/comments'), { body: text }).then(function (res) {
			if (!res.d || !res.d.ok) { toast(failureText(res)); return; }
			openTicket(t.id);
		});
	});

	view.querySelectorAll('[data-tk-status]').forEach(function (b) {
		b.addEventListener('click', function () {
			postJson(api('/tickets/' + t.id + '/status'), { status: b.getAttribute('data-tk-status') }).then(function (res) {
				if (!res.d || !res.d.ok) { toast(failureText(res)); return; }
				openTicket(t.id);
			});
		});
	});

	var ro = view.querySelector('[data-tk-reopen]');
	if (ro) ro.addEventListener('click', function () {
		postJson(api('/tickets/' + t.id + '/reopen'), {}).then(function (res) {
			if (!res.d || !res.d.ok) { toast(failureText(res)); return; }
			openTicket(t.id);
		});
	});

	// Η εικόνα: με το nonce, ως blob -- ένα <img src> στο REST δεν στέλνει κεφαλίδα.
	if (t.has_attachment) {
		fetch(api('/tickets/' + t.id + '/attachment'), { headers: H() })
			.then(function (r) { if (!r.ok) throw new Error(); return r.blob(); })
			.then(function (blob) {
				var box = view.querySelector('[data-tk-img]');
				var url = URL.createObjectURL(blob);
				box.innerHTML = '<a href="' + esc(url) + '" target="_blank" rel="noopener"><img alt="Εικόνα της αναφοράς" src="' + esc(url) + '"></a>';
				box.hidden = false;
			})
			.catch(function () {});
	}
}

/* ---- 1. και 2.: εκκίνηση ----------------------------------------------- */

var started = false;

export function initTickets() {
	if (started) return;
	started = true;

	window.addEventListener('error', function (e) {
		remember(e.message, e.filename, e.lineno);
	});
	window.addEventListener('unhandledrejection', function (e) {
		var r = e.reason;
		remember(r && r.message ? r.message : String(r || 'Promise rejected'), r && r.fileName, r && r.lineNumber);
	});

	document.addEventListener('click', function (e) {
		var b = e.target && e.target.closest && e.target.closest('[data-report-open]');
		if (b) { e.preventDefault(); openReport(); }
	});

	document.addEventListener('keydown', function (e) {
		if ((e.ctrlKey || e.metaKey) && e.shiftKey && (e.key === 'B' || e.key === 'b')) {
			e.preventDefault();
			openReport();
		}
	});
}
