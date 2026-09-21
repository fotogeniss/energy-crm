/* Energy CRM — network: the partner tree below the acting user. */

import { api, checkedProviderIds, esc, fetch, H, providerChipsHtml, toast, viewEl, wireProviderChips } from '@energy-crm/util';
import { initials, tint } from '@energy-crm/format';
import { openPartner } from '@energy-crm/navigate';

export function loadNetwork() {
	var view = viewEl('network');
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
		'<span><button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-show-bulk>Πάροχοι ομάδας</button> ' +
		'<button type="button" class="ecrm-btn ecrm-btn--amber ecrm-btn--sm" data-show-invite>+ Νέος υποσυνεργάτης</button></span></header>';

	var bodyCard;
	if (partners.length) {
		// Ο υπότιτλος λέει ρητά «Πάτα σε έναν για να δεις τα στατιστικά του»,
		// αλλά καμία γραμμή δεν είχε ποτέ click handler -- η υπόσχεση της
		// οθόνης της ίδιας δεν εκτελούνταν. Ίδιο μοτίβο με το
		// ecrm-view-team.js: data-member + openPartner(), προϋπάρχουσα
		// συμπεριφορά που έλειπε από εδώ, όχι νέα σχεδιαστική απόφαση.
		var rows = partners.map(function (p) {
			return '<tr class="ecrm-rowlink" data-member="' + p.id + '"><td><span class="ecrm-cell-cust"><span class="ecrm-cell-mark ecrm-cell-mark--cust" style="--h:' + tint(p.name) + '">' + esc(initials(p.name)) + '</span><span>' + esc(p.name) + '</span></span></td>' +
				'<td class="ecrm-col-sec">' + esc(p.email) + '</td><td>' + (p.team_size || 0) + '</td><td>' + (p.contracts || 0) + '</td></tr>';
		}).join('');
		bodyCard = '<div class="ecrm-card"><div class="ecrm-tablewrap"><table class="ecrm-table"><thead><tr><th>Συνεργάτης</th><th class="ecrm-col-sec">Email</th><th>Ομάδα</th><th>Αιτήσεις</th></tr></thead><tbody>' + rows + '</tbody></table></div></div>';
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
		'</div>' +
		// Ίδιο σημείο με το ecrm-view-team.js «Νέο μέλος»: παρόχοι που θα
		// βλέπει, φορτώνεται με το πρώτο άνοιγμα (279).
		'<div class="ecrm-step" style="margin-top:14px" data-newprov-step hidden>Πάροχοι που θα βλέπει ' +
		'<span><a href="#" data-pall>Ολοι</a> <a href="#" data-pnone>Κανένας</a></span></div>' +
		'<div data-newprov-chips></div>' +
		'<div class="ecrm-hint" data-newprov-hint hidden>Βλέπεις μόνο όσους παρόχους έχεις κι εσύ.</div>' +
		'<button type="button" class="ecrm-btn ecrm-btn--primary ecrm-btn--sm" data-invite>+ Πρόσκληση</button>' +
		'<div class="ecrm-ai-status" data-invite-msg></div></div>';

	view.innerHTML = head + bodyCard + inviteForm + '<div class="ecrm-card" data-bulkwrap hidden></div>';

	var providersPromise = null;
	function loadProviders() {
		if (!providersPromise) {
			providersPromise = fetch(api('/team/providers'), { headers: H() }).then(function (r) { return r.json(); });
		}
		return providersPromise;
	}

	view.querySelectorAll('[data-member]').forEach(function (row) {
		row.addEventListener('click', function (ev) {
			if (ev.target.closest('button')) { return; }
			openPartner(this.getAttribute('data-member'));
		});
	});
	view.querySelectorAll('[data-show-invite]').forEach(function (b) {
		b.addEventListener('click', function () {
			var w = view.querySelector('[data-invitewrap]');
			if (!w) return;
			w.hidden = !w.hidden;
			if (w.hidden) return;
			w.scrollIntoView({ behavior: 'smooth' });
			var chipsBox = w.querySelector('[data-newprov-chips]');
			if (chipsBox && !chipsBox.getAttribute('data-loaded')) {
				chipsBox.innerHTML = '<div class="ecrm-empty">Φόρτωση…</div>';
				loadProviders().then(function (d) {
					if (!d || !d.ok) { chipsBox.innerHTML = '<div class="ecrm-empty">Δεν φόρτωσαν οι πάροχοι.</div>'; return; }
					var providers = d.providers || [];
					var ids = providers.map(function (p) { return parseInt(p.id, 10); });
					chipsBox.innerHTML = providerChipsHtml(providers, ids, false);
					chipsBox.setAttribute('data-loaded', '1');
					wireProviderChips(w);
					var step = w.querySelector('[data-newprov-step]'); if (step) step.hidden = false;
					var hint = w.querySelector('[data-newprov-hint]'); if (hint) hint.hidden = false;
				}).catch(function () { chipsBox.innerHTML = '<div class="ecrm-empty">Δεν φόρτωσαν οι πάροχοι.</div>'; });
			}
		});
	});
	var showBulk = view.querySelector('[data-show-bulk]');
	if (showBulk) showBulk.addEventListener('click', function () {
		var w = view.querySelector('[data-bulkwrap]');
		if (!w) return;
		w.hidden = !w.hidden;
		if (w.hidden) return;
		w.scrollIntoView({ behavior: 'smooth' });
		renderBulk(w, loadProviders);
	});
	var inv = view.querySelector('[data-invite]');
	if (inv) inv.addEventListener('click', function () {
		var get = function (f) { var el = view.querySelector('[data-nf="' + f + '"]'); return el ? el.value : ''; };
		var payload = { name: get('name'), email: get('email'), role: 'ecrm_partner', password: get('password') };
		if (!payload.name || !payload.email) { toast('Συμπλήρωσε όνομα και email.', false); return; }
		var chipsWrap = view.querySelector('[data-invitewrap]');
		if (chipsWrap && chipsWrap.querySelector('[data-newprov-chips] [data-pchip]')) {
			payload.provider_ids = checkedProviderIds(chipsWrap);
		}
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

/**
 * «Πάροχοι ομάδας» (279): ίδιο σχήμα με το ecrm-view-team.js -- εδώ οι
 * «άμεσοι» είναι οι άμεσοι υποσυνεργάτες, όχι πωλητές.
 */
function renderBulk(wrap, loadProviders) {
	wrap.innerHTML = '<div class="ecrm-step">Πάροχοι ομάδας</div><div class="ecrm-empty">Φόρτωση…</div>';
	loadProviders().then(function (d) {
		if (!d || !d.ok) { wrap.innerHTML = '<div class="ecrm-empty">Δεν φόρτωσαν οι πάροχοι.</div>'; return; }
		var members = d.members || 0;
		var rows = (d.providers || []).map(function (p) {
			var has = p.has || 0;
			var pct = members ? Math.round((has / members) * 100) : 0;
			var grantBtn = has < members
				? '<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-bulk-op="grant" data-bulk-id="' + p.id + '">Σε όλους</button>'
				: '';
			var revokeBtn = has > 0
				? '<button type="button" class="ecrm-btn ecrm-btn--danger ecrm-btn--sm" data-bulk-op="revoke" data-bulk-id="' + p.id + '" data-bulk-name="' + esc(p.name) + '">Αφαίρεση από όλους</button>'
				: '';
			return '<tr><td>' + esc(p.name) + '</td>' +
				'<td><span class="ecrm-ptbl__cnt"><b>' + has + '</b> από ' + members + '</span>' +
				'<div class="ecrm-ptbl__bar"><i style="width:' + pct + '%"></i></div></td>' +
				'<td class="ecrm-ptbl__act">' + grantBtn + ' ' + revokeBtn + '</td></tr>';
		}).join('');

		wrap.innerHTML = '<div class="ecrm-step">Πάροχοι ομάδας</div>' +
			(rows
				? '<div class="ecrm-tablewrap"><table class="ecrm-ptbl"><thead><tr><th>Πάροχος</th><th>Το έχουν</th><th></th></tr></thead><tbody>' + rows + '</tbody></table></div>'
				: '<div class="ecrm-empty">Δεν έχεις κανέναν πάροχο ακόμα.</div>') +
			'<div class="ecrm-hint">Μόνο οι άμεσοι υποσυνεργάτες σου. Ο καθένας τους αποφασίζει ο ίδιος για τη δική του ομάδα.</div>';

		wrap.querySelectorAll('[data-bulk-op]').forEach(function (b) {
			b.addEventListener('click', function () {
				var op = this.getAttribute('data-bulk-op');
				var pid = this.getAttribute('data-bulk-id');
				if (op === 'revoke') {
					var name = this.getAttribute('data-bulk-name');
					var msg = 'Αφαιρείς ' + name + ' από όλους τους άμεσους υποσυνεργάτες σου. Όσοι έχουν δική ' +
						'τους ομάδα, το χάνουν κι εκείνοι από κάτω τους. Οι αιτήσεις που έχουν ήδη γίνει μένουν ' +
						'όπως είναι.\n\nΣυνέχεια;';
					if (!confirm(msg)) return;
				}
				bulkOp(wrap, pid, op);
			});
		});
	}).catch(function () { wrap.innerHTML = '<div class="ecrm-empty">Δεν φόρτωσαν οι πάροχοι.</div>'; });
}

function bulkOp(wrap, providerId, op) {
	fetch(api('/team/providers'), {
		method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H()),
		body: JSON.stringify({ provider_id: providerId, op: op })
	})
		.then(function (r) { return r.json(); })
		.then(function (res) {
			if (!res || !res.ok) { toast((res && res.error) || 'Αποτυχία.', false); return; }
			toast(res.below > 0 ? 'Έγινε -- επηρέασε και ' + res.below + ' από κάτω.' : 'Έγινε.');
			renderBulk(wrap, function () {
				return fetch(api('/team/providers'), { headers: H() }).then(function (r) { return r.json(); });
			});
		})
		.catch(function () { toast('Σφάλμα δικτύου.', false); });
}
