/* Energy CRM — network: the partner tree below the acting user. */

import { api, banner, checkedProviderIds, esc, failureText, fetch, H, postJson, providerChipsHtml, providerMark, toast, viewEl, wireProviderChips } from '@energy-crm/util';
import { initials, tint } from '@energy-crm/format';
import { openPartner } from '@energy-crm/navigate';

export function loadNetwork(flash) {
	var view = viewEl('network');
	fetch(api('/network'), { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) { renderNetwork(view, (d && d.partners) || [], flash); })
		.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα φόρτωσης.</div></div>'; });
}
function networkIcon() {
	return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="12" r="2.4"/><circle cx="18" cy="6" r="2.4"/><circle cx="18" cy="18" r="2.4"/><path d="M8.2 10.8 15.8 7.2"/><path d="M8.2 13.2 15.8 16.8"/></svg>';
}
/* (361) «Signature»: ο χάρτης. Πάνω εσύ με το σύνολο της ομάδας, από κάτω
 * οι άμεσοι υποσυνεργάτες ως κάρτες δεμένες με γραμμές, και τελευταία κάρτα
 * η πρόσκληση. Από κάτω, πάντα ανοιχτοί, οι πάροχοι ομάδας. Ίδια δεδομένα
 * και ίδια data-* με την κλασική εμφάνιση. docs/UI-NETWORK-FRESH.html, Α. */
function isSignature() {
	var app = document.getElementById('ecrm-app');
	return !!app && app.getAttribute('data-ui') === 'signature';
}

function sigPlural(n, one, many) { return n === 1 ? one : many; }

function sigNetworkHTML(partners, flash) {
	var below = partners.reduce(function (n, p) { return n + (Number(p.team_size) || 0); }, 0);
	var total = partners.reduce(function (n, p) { return n + (Number(p.contracts) || 0); }, 0);
	var people = partners.length + below;
	var sub = partners.length
		? partners.length + sigPlural(partners.length, ' άμεσος συνεργάτης', ' άμεσοι συνεργάτες') +
			(below ? ', ' + below + ' ακόμα από κάτω τους' : '')
		: 'Δεν έχεις υποσυνεργάτες ακόμα';

	var nodes = partners.map(function (p) {
		var n = Number(p.contracts) || 0;
		var t = Number(p.team_size) || 0;
		return '<div class="sig-nnode' + (n ? '' : ' is-quiet') + '" data-member="' + esc(String(p.id)) + '" role="button" tabindex="0">' +
			'<div class="sig-nnode__top"><span class="sig-member__av" style="--h:' + tint(p.name) + '">' + esc(initials(p.name)) + '</span>' +
			'<span class="sig-nnode__who"><b>' + esc(p.name) + '</b><small>' + esc(p.email || '') + '</small></span></div>' +
			'<div class="sig-nnode__stats"><div><b>' + esc(String(n)) + '</b><span>' + sigPlural(n, 'αίτηση', 'αιτήσεις') + '</span></div>' +
			'<div><b>' + esc(String(t)) + '</b><span>από κάτω</span></div></div>' +
			(n ? '' : '<p class="sig-nnode__note">Δεν έχει κάνει ακόμα αίτηση</p>') +
			'</div>';
	}).join('');

	return '<header class="sig-cuhead"><div><h2>Το δίκτυό μου</h2><p>Πάτα σε έναν για να δεις τα στατιστικά του.</p></div></header>' +
		(flash ? '<div class="ecrm-import-banner is-ok">' + esc(flash) + '</div>' : '') +
		'<div class="sig-nmap">' +
			'<div class="sig-nme"><span class="sig-nme__ico">' + networkIcon() + '</span>' +
				'<div class="sig-nme__who"><b>Εσύ</b><span>' + esc(sub) + '</span></div>' +
				'<div class="sig-nme__nums"><div><b>' + esc(String(people)) + '</b><span>' + sigPlural(people, 'άτομο', 'άτομα') + '</span></div>' +
				'<div><b>' + esc(String(total)) + '</b><span>αιτήσεις ομάδας</span></div></div></div>' +
			'<div class="sig-nbranch">' + nodes +
				'<button type="button" class="sig-nnode sig-nnode--add" data-show-invite>' +
				'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>' +
				'<span>' + (partners.length ? 'Νέος υποσυνεργάτης' : 'Πρόσκληση του πρώτου υποσυνεργάτη') + '</span></button>' +
			'</div>' +
		'</div>';
}

function renderNetwork(view, partners, flash) {
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
		'</div>' +
		// Ίδιο σημείο με το ecrm-view-team.js «Νέο μέλος»: παρόχοι που θα
		// βλέπει, φορτώνεται με το πρώτο άνοιγμα (279).
		'<div class="ecrm-step" style="margin-top:14px" data-newprov-step hidden>Πάροχοι που θα βλέπει ' +
		'<span><a href="#" class="ecrm-plink" data-pall>Ολοι</a><a href="#" class="ecrm-plink" data-pnone>Κανένας</a></span></div>' +
		'<div data-newprov-chips></div>' +
		'<div class="ecrm-hint" data-newprov-hint hidden>Βλέπεις μόνο όσους παρόχους έχεις κι εσύ.</div>' +
		'<div class="ecrm-addform__foot">' +
		'<button type="button" class="ecrm-btn ecrm-btn--primary" data-invite>Δημιουργία υποσυνεργάτη</button>' +
		'<span class="ecrm-hint">Θα του σταλεί email με σύνδεσμο για να ορίσει κωδικό.</span></div>' +
		'<div class="ecrm-ai-status" data-invite-msg></div></div>';

	var sig = isSignature();
	view.innerHTML = sig
		? sigNetworkHTML(partners, flash) + inviteForm + (partners.length ? '<div class="sig-npv" data-bulkwrap></div>' : '')
		: head + (flash ? '<div class="ecrm-import-banner is-ok">' + esc(flash) + '</div>' : '') + bodyCard + inviteForm + '<div class="ecrm-card" data-bulkwrap hidden></div>';

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
	view.querySelectorAll('.sig-nnode[data-member]').forEach(function (node) {
		node.addEventListener('keydown', function (ev) {
			if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); openPartner(this.getAttribute('data-member')); }
		});
	});
	if (sig && partners.length) { renderBulk(view.querySelector('[data-bulkwrap]'), loadProviders); }
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
	view.querySelectorAll('[data-invitewrap] .ecrm-input').forEach(function (el) {
		el.addEventListener('keydown', function (ev) { if (ev.key === 'Enter' && inv && !inv.disabled) { ev.preventDefault(); inv.click(); } });
	});
	if (inv) inv.addEventListener('click', function () {
		var b = this;
		var get = function (f) { var el = view.querySelector('[data-nf="' + f + '"]'); return el ? el.value.trim() : ''; };
		var out = view.querySelector('[data-invite-msg]');
		var payload = { name: get('name'), email: get('email'), role: 'ecrm_partner' };
		if (!payload.name || !payload.email) { banner(out, 'Συμπλήρωσε όνομα και email.', false); return; }
		var chipsWrap = view.querySelector('[data-invitewrap]');
		if (chipsWrap && chipsWrap.querySelector('[data-newprov-chips] [data-pchip]')) {
			payload.provider_ids = checkedProviderIds(chipsWrap);
			if (!payload.provider_ids.length) { banner(out, 'Διάλεξε τουλάχιστον έναν πάροχο.', false); return; }
		}
		// Ίδιο με το ecrm-view-team.js: το αποτέλεσμα μένει ορατό, όχι toast
		// που σβήνει. Και χωρίς «Κωδικός: undefined» -- ο server φτιάχνει δικό
		// του κωδικό και στέλνει πρόσκληση, δεν τον επιστρέφει ποτέ.
		var label = b.textContent;
		b.disabled = true; b.textContent = 'Δημιουργία…';
		if (out) out.innerHTML = '';
		postJson(api('/team'), payload)
			.then(function (res) {
				var d = res.d;
				if (!d || !d.ok) { banner(out, failureText(res), false); return; }
				loadNetwork(d.invited
					? 'Δημιουργήθηκε ο/η ' + payload.name + '. Username: ' + d.username + '. Στάλθηκε email πρόσκλησης με σύνδεσμο ορισμού κωδικού (ισχύει 24 ώρες).'
					: 'Δημιουργήθηκε ο λογαριασμός ' + d.username + ', αλλά το email πρόσκλησης ΔΕΝ στάλθηκε. Δώσε του το username και πες του να πατήσει «Ξέχασα τον κωδικό» στην οθόνη σύνδεσης.');
			})
			.catch(function () { banner(out, 'Σφάλμα δικτύου -- δεν έφτασε στον server. Ξαναδοκίμασε.', false); })
			.finally(function () { b.disabled = false; b.textContent = label; });
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

		if (isSignature()) {
			var srows = (d.providers || []).map(function (p) {
				var has = p.has || 0;
				var pct = members ? Math.round((has / members) * 100) : 0;
				var cnt = has >= members && members ? 'Όλοι' : (has ? has + ' από ' + members : 'Κανένας');
				return '<div class="sig-npv__row">' +
					'<span class="sig-npv__name">' + providerMark(p.name, p.logo) + '<b>' + esc(p.name) + '</b></span>' +
					'<span class="sig-npv__bar" aria-hidden="true"><i style="width:' + esc(String(pct)) + '%"></i></span>' +
					'<span class="sig-npv__cnt">' + esc(cnt) + '</span>' +
					'<span class="sig-npv__act">' +
					(has < members ? '<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-bulk-op="grant" data-bulk-id="' + esc(String(p.id)) + '">Σε όλους</button>' : '') +
					(has > 0 ? '<button type="button" class="ecrm-btn ecrm-btn--danger ecrm-btn--sm" data-bulk-op="revoke" data-bulk-id="' + esc(String(p.id)) + '" data-bulk-name="' + esc(p.name) + '">Αφαίρεση από όλους</button>' : '') +
					'</span></div>';
			}).join('');
			wrap.innerHTML = '<h3>Πάροχοι της ομάδας</h3>' +
				'<p>Πόσοι από τους άμεσους συνεργάτες σου βλέπουν κάθε πάροχο. Ο καθένας τους αποφασίζει μόνος για τη δική του ομάδα.</p>' +
				(srows || '<div class="ecrm-empty">Δεν έχεις κανέναν πάροχο ακόμα.</div>');
		} else {
		wrap.innerHTML = '<div class="ecrm-step">Πάροχοι ομάδας</div>' +
			(rows
				? '<div class="ecrm-tablewrap"><table class="ecrm-ptbl"><thead><tr><th>Πάροχος</th><th>Το έχουν</th><th></th></tr></thead><tbody>' + rows + '</tbody></table></div>'
				: '<div class="ecrm-empty">Δεν έχεις κανέναν πάροχο ακόμα.</div>') +
			'<div class="ecrm-hint">Μόνο οι άμεσοι υποσυνεργάτες σου. Ο καθένας τους αποφασίζει ο ίδιος για τη δική του ομάδα.</div>';
		}

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
