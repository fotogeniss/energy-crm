/* Energy CRM — «Ιστορικό» στην καρτέλα πελάτη (346).
 *
 * docs/UI-CUSTOMER-HISTORY.html, εγκρίθηκε 01/11. Ό,τι έγινε με τον πελάτη, από
 * όλες του τις συμβάσεις, σε μία χρονολογική λίστα. Ο server (CustomerHistory
 * στο Domain) έχει ήδη αποφασίσει τι μπαίνει, τι λέει κάθε γραμμή και ποιο
 * χρώμα παίρνει (`tone`)· εδώ μόνο ζωγραφίζεται, φιλτράρεται και σελιδοποιείται.
 *
 * Ξεχωριστό fetch από το /card: η καρτέλα ανοίγει αμέσως και το ιστορικό
 * έρχεται λίγο μετά. Κανένα cache — κάθε άνοιγμα φέρνει φρέσκα δεδομένα.
 */

import { api, esc, fetch, H } from '@energy-crm/util';
import { fmtDate, timeAgo } from '@energy-crm/format';
import { openDetail } from '@energy-crm/navigate';

var PAGE = 15;

var FILTERS = [
	['all', 'Όλα'],
	['contract', 'Συμβάσεις'],
	['sign', 'Υπογραφές & SMS'],
	['task', 'Εργασίες & κλήσεις'],
	['note', 'Σημειώσεις'],
	['doc', 'Έγγραφα'],
	['edit', 'Αλλαγές στοιχείων'],
];

var GLYPH = { ok: '✓', bad: '!', contract: '+', sign: '✉', task: '☎', note: '✍', edit: '✎', lead: '★', doc: '▤' };

var MONTHS = ['Ιανουάριος', 'Φεβρουάριος', 'Μάρτιος', 'Απρίλιος', 'Μάιος', 'Ιούνιος',
	'Ιούλιος', 'Αύγουστος', 'Σεπτέμβριος', 'Οκτώβριος', 'Νοέμβριος', 'Δεκέμβριος'];

export function mountCustomerHistory(el, customerId) {
	if (!el) { return; }
	el.innerHTML = head(null) + '<div class="ecrm-loading">Φόρτωση…</div>';
	fetch(api('/customers/' + customerId + '/history'), { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (!d || !d.ok) {
				el.innerHTML = head(null) + '<div class="ecrm-empty">' + esc((d && d.error) || 'Δεν φορτώθηκε το ιστορικό.') + '</div>';
				return;
			}
			draw(el, { items: d.items || [], counts: d.counts || {}, truncated: !!d.truncated, filter: 'all', shown: PAGE });
		})
		.catch(function () {
			el.innerHTML = head(null) + '<div class="ecrm-empty">Σφάλμα φόρτωσης ιστορικού.</div>';
		});
}

function head(total) {
	return '<div class="ecrm-step">Ιστορικό' +
		(total ? ' <span class="ecrm-hist__count">· ' + esc(String(total)) + ' γεγονότα</span>' : '') + '</div>';
}

function draw(el, state) {
	var all = state.items;
	var list = state.filter === 'all' ? all : all.filter(function (i) { return i.kind === state.filter; });
	var visible = list.slice(0, state.shown);
	var rest = list.length - visible.length;

	if (!all.length) {
		el.innerHTML = head(null) + '<div class="ecrm-empty">Δεν έχει καταγραφεί τίποτα ακόμη.</div>';
		return;
	}

	el.innerHTML = head(state.counts.all || all.length) +
		chips(state) +
		groups(visible) +
		(rest > 0 ? '<button type="button" class="ecrm-hist__more" data-hist-more>Δείξε παλαιότερα (' + esc(String(rest)) + ')</button>' : '') +
		(state.truncated && rest <= 0 ? '<div class="ecrm-hist__cap">Φαίνονται τα ' + esc(String(all.length)) + ' πιο πρόσφατα.</div>' : '');

	el.querySelectorAll('[data-hist-filter]').forEach(function (b) {
		b.addEventListener('click', function () {
			state.filter = this.getAttribute('data-hist-filter');
			state.shown = PAGE;
			draw(el, state);
		});
	});

	var more = el.querySelector('[data-hist-more]');
	if (more) {
		more.addEventListener('click', function () {
			state.shown += PAGE;
			draw(el, state);
		});
	}

	el.querySelectorAll('[data-hist-open]').forEach(function (a) {
		a.addEventListener('click', function (ev) {
			ev.preventDefault();
			openDetail(this.getAttribute('data-hist-open'));
		});
	});
}

function chips(state) {
	return '<div class="ecrm-hist__chips">' + FILTERS.map(function (f) {
		var n = f[0] === 'all' ? (state.counts.all || 0) : (state.counts[f[0]] || 0);
		if (f[0] !== 'all' && !n) { return ''; }
		return '<button type="button" class="ecrm-filterchip' + (state.filter === f[0] ? ' is-on' : '') +
			'" data-hist-filter="' + esc(f[0]) + '">' + esc(f[1]) +
			' <span class="ecrm-filterchip__ct">' + esc(String(n)) + '</span></button>';
	}).join('') + '</div>';
}

function groups(items) {
	var out = '';
	var month = '';

	items.forEach(function (item) {
		var key = String(item.at || '').slice(0, 7);
		if (key !== month) {
			out += (month ? '</ul>' : '') + '<div class="ecrm-hist__month">' + esc(monthLabel(key)) + '</div><ul class="ecrm-hist">';
			month = key;
		}
		out += row(item);
	});

	return out + (month ? '</ul>' : '');
}

function row(item) {
	var tone = GLYPH[item.tone] ? item.tone : 'note';
	var ref = item.contract_id
		? ' <a href="#" class="ecrm-hist__ref" data-hist-open="' + esc(String(item.contract_id)) + '">' +
			'<span class="ecrm-hist__reflong">' + esc(item.contract_ref) + '</span>' +
			'<span class="ecrm-hist__refshort">' + esc(item.contract_code) + '</span></a>'
		: '';

	return '<li class="ecrm-hist__row">' +
		'<span class="ecrm-hist__ic ecrm-hist__ic--' + esc(tone) + '" aria-hidden="true">' + esc(GLYPH[tone]) + '</span>' +
		'<div class="ecrm-hist__body">' +
		'<div class="ecrm-hist__title">' + esc(item.title) + ref + '</div>' +
		(item.detail ? '<div class="ecrm-hist__detail">' + esc(item.detail) + '</div>' : '') +
		(item.actor ? '<div class="ecrm-hist__who' + (item.actor === 'Σύστημα' ? ' is-sys' : '') + '">' + esc(item.actor) + '</div>' : '') +
		'</div>' +
		'<span class="ecrm-hist__when">' + esc(when(item.at)) + '</span>' +
		'</li>';
}

function monthLabel(key) {
	var m = /^(\d{4})-(\d{2})$/.exec(key);
	return m ? MONTHS[parseInt(m[2], 10) - 1] + ' ' + m[1] : '—';
}

/* Μέσα στην εβδομάδα «πριν από…», αλλιώς ημερομηνία· το έτος μόνο όταν δεν είναι το φετινό. */
function when(at) {
	if (!at) { return ''; }
	var ago = timeAgo(at);
	var days = /^(\d+)μ πριν$/.exec(ago);
	if (!days || parseInt(days[1], 10) < 7) { return ago; }
	var date = fmtDate(at);
	return date.slice(6) === String(new Date().getFullYear()) ? date.slice(0, 5) : date;
}
