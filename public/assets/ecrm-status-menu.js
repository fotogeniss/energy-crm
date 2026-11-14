/* Energy CRM — αλλαγή κατάστασης από τη λίστα (384). docs/UI-STATUS-INLINE.html.
 *
 * Το σήμα της κατάστασης γίνεται κουμπί: ανοίγει μενού (στο κινητό φύλλο από
 * κάτω) με τις επιτρεπτές επόμενες καταστάσεις της γραμμής (`allowed_next`,
 * ίδιες με της καρτέλας). Απόρριψη/ακύρωση ζητούν λόγο (`pickReason`). Η
 * αλλαγή πάει στο ίδιο `POST /contracts/{id}/status` με την καρτέλα· ο server
 * ελέγχει ξανά τα πάντα και απαντά με τις νέες επόμενες, ώστε η γραμμή να
 * αλλάξει επιτόπου.
 */

import { api, esc, fetch, H, toast } from '@energy-crm/util';
import { pickReason, reasonsFor } from '@energy-crm/close-reason';

var CANCEL = 'cancelled';
var open = null;

function isNarrow() { return window.matchMedia('(max-width: 767px)').matches; }

/**
 * Το σήμα μιας γραμμής: κουμπί όταν υπάρχει πού να πάει, αλλιώς απλό σήμα
 * (τελική κατάσταση ή χρήστης χωρίς δικαίωμα αλλαγής).
 */
export function statusBadgeHTML(r, label) {
	var st = esc(r.status);
	var txt = esc(label);
	if (!r.allowed_next || !r.allowed_next.length) {
		return '<span class="ecrm-badge ecrm-badge--' + st + '">' + txt + '</span>';
	}
	var id = esc(String(r.id));
	return '<button type="button" class="ecrm-badge ecrm-badge--' + st + ' ecrm-stbtn" data-stmenu="' + id + '"' +
		' aria-haspopup="menu" aria-expanded="false" title="Αλλαγή κατάστασης"><span class="ecrm-stbtn__t">' + txt + '</span>' +
		'<span class="ecrm-stbtn__car" aria-hidden="true">▾</span></button>';
}

/**
 * @param {Element} root
 * @param {{rows: Object<string, Object>, statuses: Object<string, string>,
 *          open: function(string), changed: function(string, string, Object),
 *          openLink: (boolean|undefined)}} opts
 *        rows: id => γραμμή της λίστας· open: άνοιγμα της αίτησης·
 *        changed: μετά από επιτυχή αλλαγή (id, νέα κατάσταση, γραμμή)·
 *        openLink: false κρύβει το «Άνοιγμα αίτησης» (μέσα στην καρτέλα).
 */
export function wireStatusMenus(root, opts) {
	root.querySelectorAll('[data-stmenu]').forEach(function (b) { bind(b, opts); });
}

function bind(btn, opts) {
	btn.addEventListener('click', function (e) {
		// Η γραμμή ανοίγει την αίτηση με κλικ· το σήμα όχι.
		e.stopPropagation();
		e.preventDefault();
		var id = this.getAttribute('data-stmenu');
		if (open && open.btn === this) { closeMenu(true); return; }
		closeMenu(false);
		showMenu(this, id, opts);
	});
	btn.addEventListener('touchstart', function (e) { e.stopPropagation(); }, { passive: true });
}

function optionHTML(slug, label) {
	var s = esc(slug);
	var l = esc(label);
	var why = reasonsFor(slug).length ? '<small>λόγος</small>' : '';
	return '<button type="button" class="ecrm-stmenu__opt' + (slug === CANCEL ? ' is-danger' : '') + '" role="menuitem" data-to="' + s + '">' +
		'<span class="ecrm-stmenu__dot ecrm-badge--' + s + '"></span><span>' + l + '</span>' + why + '</button>';
}

function showMenu(btn, id, opts) {
	var row = opts.rows[id];
	if (!row) return;
	var labels = opts.statuses || {};
	var next = (row.allowed_next || []).filter(function (s) { return s !== CANCEL; });
	var hasCancel = (row.allowed_next || []).indexOf(CANCEL) !== -1;
	var narrow = isNarrow();
	var name = row.company_name || ((row.first_name || '') + ' ' + (row.last_name || '')).trim();
	var head = narrow && name ? name + ' · ' + (labels[row.status] || row.status) : 'Αλλαγή κατάστασης';

	var el = document.createElement('div');
	el.className = 'ecrm-stmenu' + (narrow ? ' is-sheet' : '');
	el.setAttribute('role', 'menu');
	el.innerHTML = (narrow ? '<div class="ecrm-stmenu__grab"></div>' : '') +
		'<div class="ecrm-stmenu__h">' + esc(head) + '</div>' +
		'<div class="ecrm-stmenu__list">' +
		next.map(function (s) { return optionHTML(s, labels[s] || s); }).join('') +
		'</div>' +
		(hasCancel ? '<div class="ecrm-stmenu__sep"></div>' + optionHTML(CANCEL, labels[CANCEL] || CANCEL) : '') +
		(opts.openLink === false ? '' :
			'<div class="ecrm-stmenu__f"><button type="button" class="ecrm-stmenu__link" data-stmenu-open>Άνοιγμα αίτησης →</button></div>');

	var host = document.getElementById('ecrm-app') || document.body;
	var shade = null;
	if (narrow) {
		shade = document.createElement('div');
		shade.className = 'ecrm-stmenu-shade';
		shade.addEventListener('click', function () { closeMenu(true); });
		host.appendChild(shade);
	}
	host.appendChild(el);
	if (!narrow) place(el, btn);

	btn.setAttribute('aria-expanded', 'true');
	open = { el: el, shade: shade, btn: btn };

	el.addEventListener('click', function (e) { e.stopPropagation(); });
	el.querySelectorAll('[data-to]').forEach(function (o) {
		o.addEventListener('click', function () {
			var to = this.getAttribute('data-to');
			closeMenu(false);
			change(id, to, opts);
		});
	});
	var openBtn = el.querySelector('[data-stmenu-open]');
	if (openBtn) openBtn.addEventListener('click', function () {
		closeMenu(false);
		opts.open(id);
	});
	el.addEventListener('keydown', function (e) {
		var items = Array.prototype.slice.call(el.querySelectorAll('button'));
		var i = items.indexOf(document.activeElement);
		if (e.key === 'ArrowDown') { e.preventDefault(); items[(i + 1) % items.length].focus(); }
		if (e.key === 'ArrowUp') { e.preventDefault(); items[(i - 1 + items.length) % items.length].focus(); }
		if (e.key === 'Escape' || e.key === 'Tab') { e.preventDefault(); closeMenu(true); }
	});

	var first = el.querySelector('[data-to]');
	if (first) first.focus({ preventScroll: true });
}

/** Κάτω από το σήμα· από πάνω όταν δεν χωρά, ποτέ έξω από την οθόνη. */
function place(el, btn) {
	var r = btn.getBoundingClientRect();
	var w = el.offsetWidth;
	var h = el.offsetHeight;
	var top = r.bottom + 6;
	if (top + h > window.innerHeight - 8 && r.top - h - 6 > 8) top = r.top - h - 6;
	var left = Math.min(r.left, window.innerWidth - w - 8);
	el.style.top = Math.max(8, top) + 'px';
	el.style.left = Math.max(8, left) + 'px';
}

function closeMenu(refocus) {
	if (!open) return;
	var o = open;
	open = null;
	o.el.remove();
	if (o.shade) o.shade.remove();
	o.btn.setAttribute('aria-expanded', 'false');
	if (refocus && document.contains(o.btn)) o.btn.focus({ preventScroll: true });
}

document.addEventListener('click', function () { closeMenu(false); });
window.addEventListener('resize', function () { closeMenu(false); });
window.addEventListener('scroll', function (e) {
	// Η κύλιση της ίδιας της λίστας του μενού δεν το κλείνει.
	if (open && !open.shade && !open.el.contains(e.target)) closeMenu(false);
}, true);

function badgesOf(id) {
	return document.querySelectorAll('[data-stmenu="' + id + '"]');
}

/**
 * Η αλλαγή. `undoOf` = η κατάσταση από την οποία γυρνάμε (αναίρεση): τότε
 * χωρίς λόγο και χωρίς νέα «Αναίρεση».
 */
function change(id, to, opts, undoOf) {
	var row = opts.rows[id];
	var labels = opts.statuses || {};
	var from = row.status;
	var label = labels[to] || to;

	// Πρόχειρο που πετιέται: χωρίς λόγο (CloseReason::required()), όπως η καρτέλα.
	var ask = undoOf || from === 'draft' ? Promise.resolve({ reason: '', note: '' }) : pickReason(to, label, 1);

	ask.then(function (picked) {
		if (!picked) return;
		badgesOf(id).forEach(function (b) { b.classList.add('is-busy'); b.disabled = true; });

		fetch(api('/contracts/' + id + '/status'), {
			method: 'POST',
			headers: Object.assign({ 'Content-Type': 'application/json' }, H()),
			body: JSON.stringify({ status: to, reason: picked.reason, message: picked.note }),
		})
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (!res || !res.ok) {
					badgesOf(id).forEach(function (b) { b.classList.remove('is-busy'); b.disabled = false; });
					toast((res && res.error) || 'Αποτυχία αλλαγής.', false);
					return;
				}

				row.status = to;
				row.allowed_next = res.allowed_next || [];
				badgesOf(id).forEach(function (b) {
					var t = document.createElement('template');
					t.innerHTML = statusBadgeHTML(row, label);
					var nb = t.content.firstChild;
					b.replaceWith(nb);
					if (nb.hasAttribute('data-stmenu')) bind(nb, opts);
				});
				opts.changed(id, to, row);

				if (undoOf) { toast('Επέστρεψε σε «' + label + '».'); return; }

				// Αναίρεση μόνο όπου ο γυρισμός δεν θέλει λόγο και ο κανόνας τον
				// επιτρέπει (π.χ. όχι πίσω στο πρόχειρο, όχι μετά από ακύρωση).
				var back = from !== 'draft' && !reasonsFor(from).length && row.allowed_next.indexOf(from) !== -1;
				toast('Η κατάσταση άλλαξε σε «' + label + '».', true, back ? {
					label: 'Αναίρεση',
					run: function () { change(id, from, opts, to); },
				} : null);
			})
			.catch(function () {
				badgesOf(id).forEach(function (b) { b.classList.remove('is-busy'); b.disabled = false; });
				toast('Σφάλμα δικτύου.', false);
			});
	});
}
