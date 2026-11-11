/* Energy CRM — «Κολλημένες αιτήσεις» (338).
 *
 * Ήταν «Καθυστερήσεις ομάδας» (31/08): μόνο για υπεύθυνους, με «μέρες χωρίς
 * καμία αλλαγή» και ένα όριο για όλα. Τώρα `GET /stuck`: ο κανόνας της κάρτας
 * «Γιατί κάθεται» (μέρες στην ίδια κατάσταση, διπλάσιο από το συνηθισμένο
 * του παρόχου) για όλους, ο καθένας στο scope του. Mockup docs/UI-STUCK-LIST.html.
 *
 * Το `group` το λέει ο server: `provider` για όποιον βλέπει όλη την εταιρεία
 * (back office, διαχειριστής), `owner` για τον υπεύθυνο, `none` για τον
 * πωλητή. Η ομαδοποίηση είναι παρουσίαση και γίνεται εδώ.
 *
 * Το email digest (`/team/escalations`) δεν αλλάζει.
 *
 * (357) Στη νέα εμφάνιση «Signature»: μεγάλος τίτλος, οι ομάδες ως κάρτες με
 * γραμμές. Ίδιο markup γραμμών (ecrm-mgrrow), μόνο ρούχο στο CSS.
 */

import { api, esc, fetch, H, viewEl } from '@energy-crm/util';
import { openDetail } from '@energy-crm/navigate';

var state = { data: null, provider: '' };

export function loadEscalations() {
	var view = viewEl('escalations');
	view.innerHTML = '<div class="ecrm-card"><div class="ecrm-loading">Φόρτωση…</div></div>';

	fetch(api('/stuck'), { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (!d || !d.ok) { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">' + esc((d && d.error) || 'Σφάλμα.') + '</div></div>'; return; }
			state.data = d;
			state.provider = '';
			render(view);
		})
		.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα δικτύου.</div></div>'; });
}

/* Ομαδοποίηση με διατήρηση σειράς: ο server στέλνει πιο παλιά πρώτα, άρα
 * πρώτη ομάδα είναι αυτή με την πιο κολλημένη αίτηση. */
function groupBy(rows, key) {
	var order = [], by = {};
	rows.forEach(function (r) {
		var k = r[key] || '—';
		if (!by[k]) { by[k] = []; order.push(k); }
		by[k].push(r);
	});
	return order.map(function (name) { return { name: name, rows: by[name] }; });
}

function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }

function rowHTML(r, group) {
	var days = Number(r.days) || 0;
	var meta = ['#' + (r.code || '')];
	if (group !== 'provider') meta.push(r.provider);
	if (r.program) meta.push(r.program);
	if (group === 'provider') meta.push('πωλητής ' + r.owner_name);
	var cmp = r.compared
		? 'συνήθως ' + (Number(r.typical) || 0)
		: 'χωρίς σύγκριση · όριο ' + (Number(r.limit) || 0);
	// Κόκκινο από το τριπλάσιο του συνηθισμένου (ή 1,5 × όριο), αλλιώς πορτοκαλί.
	var critical = r.compared ? days >= 3 * Math.max(1, Number(r.typical) || 0) : days >= 1.5 * (Number(r.limit) || 0);

	return '<div class="ecrm-mgrrow" data-mgr-go="' + esc(r.id) + '">' +
		'<span class="ecrm-mgrrow__badge"><span class="ecrm-badge ecrm-badge--' + esc(r.status) + '">' + esc(r.status_label || r.status) + '</span></span>' +
		'<span class="ecrm-mgrrow__m"><b>' + esc(r.customer || '—') + '</b><small>' + esc(meta.join(' · ')) + '</small></span>' +
		'<span class="ecrm-mgrrow__age' + (critical ? ' is-critical' : '') + '">' + esc(plural(days, 'μέρα', 'μέρες')) +
		'<small>' + esc(cmp) + '</small></span>' +
		'</div>';
}

function render(view) {
	var d = state.data;
	var all = d.rows || [];
	var group = d.group || 'none';
	var sub = { provider: 'Όλη η εταιρεία', owner: 'Της ομάδας σου', none: 'Δικές σου' }[group] || '';
	var head = isSignature()
		? '<header class="sig-cuhead"><div><h2>Κολλημένες αιτήσεις</h2><p>' + esc(sub) + ': όσες κάθονται στην ίδια κατάσταση πάνω από το διπλάσιο του συνηθισμένου για τον πάροχο</p></div></header>'
		: '<header class="ecrm-head"><div class="ecrm-eyebrow">' + esc(sub) + '</div><h2 class="ecrm-title">Κολλημένες αιτήσεις</h2>' +
		'<p class="ecrm-sub">Αιτήσεις που κάθονται στην ίδια κατάσταση πάνω από το διπλάσιο του συνηθισμένου για τον πάροχο.</p></header>';

	if (!all.length) {
		view.innerHTML = head + (isSignature()
			? '<div class="sig-tempty"><b>Καμία κολλημένη αίτηση.</b><span>Όλες κινούνται κανονικά.</span></div>'
			: '<div class="ecrm-card"><div class="ecrm-empty">Καμία κολλημένη αίτηση αυτή τη στιγμή.</div></div>');
		return;
	}

	var tabs = '';
	var rows = all;

	if (group === 'provider') {
		var providers = groupBy(all, 'provider');
		tabs = '<div class="ecrm-xsell__tabs">' +
			'<button type="button" class="ecrm-xsell__tab' + (state.provider === '' ? ' is-on' : '') + '" data-stuck-prov="">Όλες <small>' + all.length + '</small></button>' +
			providers.map(function (p) {
				return '<button type="button" class="ecrm-xsell__tab' + (state.provider === p.name ? ' is-on' : '') + '" data-stuck-prov="' + esc(p.name) + '">' +
					esc(p.name) + ' <small>' + p.rows.length + '</small></button>';
			}).join('') + '</div>';
		if (state.provider !== '') {
			rows = all.filter(function (r) { return r.provider === state.provider; });
		}
	}

	var body;
	if (group === 'none') {
		body = '<div class="ecrm-mgrgroup">' + rows.map(function (r) { return rowHTML(r, group); }).join('') + '</div>';
	} else {
		body = groupBy(rows, group === 'provider' ? 'provider' : 'owner_name').map(function (g) {
			return '<div class="ecrm-mgrgroup"><div class="ecrm-mgrgroup__head">' +
				'<span class="ecrm-mgrgroup__name">' + esc(g.name) + '</span>' +
				'<span class="ecrm-mgrgroup__n">' + esc(plural(g.rows.length, 'αίτηση', 'αιτήσεις')) + '</span>' +
				'</div>' + g.rows.map(function (r) { return rowHTML(r, group); }).join('') + '</div>';
		}).join('');
	}

	view.innerHTML = head + tabs + (isSignature() ? '<div class="sig-stuck">' + body + '</div>' : '<div class="ecrm-card">' + body + '</div>');

	view.querySelectorAll('[data-stuck-prov]').forEach(function (b) {
		b.addEventListener('click', function () {
			state.provider = b.getAttribute('data-stuck-prov') || '';
			render(view);
		});
	});

	// Ίδιο μοτίβο με το attentionHTML() του dashboard: το κλικ ανοίγει την
	// ίδια τη σύμβαση, μέσω openDetail() και όχι με δικό του route.
	view.querySelectorAll('[data-mgr-go]').forEach(function (row) {
		row.addEventListener('click', function () {
			openDetail(row.getAttribute('data-mgr-go'));
		});
	});
}

// (357) «Signature»

function isSignature() {
	var app = document.getElementById('ecrm-app');
	return !!app && app.getAttribute('data-ui') === 'signature';
}
