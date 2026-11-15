/* Energy CRM — λίστες επιλογής στο κινητό ως δικό μας φύλλο (396).
 * docs/UI-STATUS-CONFIRM.html (Γ).
 *
 * Στο κινητό το <select> ανοίγει τη λίστα του Android/iOS: σκούρο πλαίσιο,
 * τεράστια γράμματα, χρώματα του συστήματος («εδώ πάλι δεν έχει το δικό μας
 * ui», 15/11). Εδώ, μόνο στη νέα εμφάνιση και μόνο σε στενή οθόνη με αφή, το
 * πάτημα ανοίγει φύλλο από κάτω με τα χρώματα της εφαρμογής (ίδιο σχήμα με το
 * μενού κατάστασης). Το άγγιγμα διαλέγει και κλείνει: μια λίστα επιλογής
 * δεν αλλάζει τίποτα στη βάση, οπότε δεν θέλει «Αλλαγή».
 *
 * Το πεδίο ΜΕΝΕΙ <select>: η τιμή, το `change`, ό,τι το διαβάζει, δεν
 * αλλάζουν. Ένας listener για όλη τη σελίδα (τα πεδία ξαναζωγραφίζονται
 * συνέχεια), όπως το ecrm-datepick.js.
 * `data-native` σε ένα <select> το αφήνει στη λίστα του συστήματος.
 */

import { esc } from '@energy-crm/util';

var sheet = null;
var shade = null;
var target = null;
var touch = null;

function narrow() {
	return window.matchMedia('(max-width: 767px)').matches && window.matchMedia('(pointer: coarse)').matches;
}

function eligible(el) {
	return !!el && el.tagName === 'SELECT' && !el.multiple && !(el.size > 1) && !el.disabled &&
		!el.hasAttribute('data-native') && !!el.closest('.ecrm-app[data-ui="signature"]') && narrow();
}

/** Ο τίτλος του φύλλου: η ετικέτα του πεδίου, αλλιώς το aria-label. */
function titleOf(sel) {
	var lab = sel.labels && sel.labels[0];
	var t = sel.getAttribute('aria-label') || '';
	if (lab) {
		var c = lab.cloneNode(true);
		c.querySelectorAll('select, option, input').forEach(function (x) { x.remove(); });
		t = c.textContent.replace(/\s+/g, ' ').trim() || t;
	}
	return t;
}

function optionHTML(o, i) {
	var on = o.selected;
	return '<button type="button" class="ecrm-stmenu__opt' + (on ? ' is-picked' : '') + '" role="option"' +
		' aria-selected="' + (on ? 'true' : 'false') + '" data-i="' + i + '"' + (o.disabled ? ' disabled' : '') + '>' +
		'<span class="ecrm-stmenu__rd" aria-hidden="true"></span><span>' + esc(o.textContent.trim()) + '</span></button>';
}

function bodyHTML(sel) {
	var html = '';
	var i = 0;
	Array.prototype.forEach.call(sel.children, function (ch) {
		if (ch.tagName === 'OPTGROUP') {
			html += '<div class="ecrm-stmenu__h">' + esc(ch.label) + '</div>';
			Array.prototype.forEach.call(ch.children, function (o) { html += optionHTML(o, i); i++; });
		} else if (ch.tagName === 'OPTION') {
			if (!(ch.hidden || (ch.disabled && ch.value === '' && i === 0))) { html += optionHTML(ch, i); }
			i++;
		}
	});
	return html;
}

function open(sel) {
	close(false);
	target = sel;
	var host = sel.closest('.ecrm-app') || document.body;
	var t = titleOf(sel);

	shade = document.createElement('div');
	shade.className = 'ecrm-stmenu-shade';
	shade.addEventListener('click', function () { close(true); });

	sheet = document.createElement('div');
	sheet.className = 'ecrm-stmenu is-sheet ecrm-selsheet';
	sheet.setAttribute('role', 'listbox');
	if (t) sheet.setAttribute('aria-label', t);
	sheet.innerHTML = '<div class="ecrm-stmenu__grab"></div>' +
		(t ? '<div class="ecrm-stmenu__h ecrm-selsheet__t">' + esc(t) + '</div>' : '') +
		'<div class="ecrm-stmenu__list">' + bodyHTML(sel) + '</div>';

	host.appendChild(shade);
	host.appendChild(sheet);
	document.documentElement.classList.add('ecrm-noscroll');

	sheet.addEventListener('wheel', function (e) {
		var list = sheet.querySelector('.ecrm-stmenu__list');
		if (!list || !list.contains(e.target) || list.scrollHeight <= list.clientHeight) e.preventDefault();
	}, { passive: false });
	sheet.addEventListener('click', function (e) {
		var b = e.target.closest('[data-i]');
		if (!b || b.disabled) return;
		choose(+b.getAttribute('data-i'));
	});
	sheet.addEventListener('keydown', function (e) {
		var items = Array.prototype.slice.call(sheet.querySelectorAll('[data-i]:not([disabled])'));
		var i = items.indexOf(document.activeElement);
		if (e.key === 'ArrowDown') { e.preventDefault(); items[(i + 1) % items.length].focus(); }
		if (e.key === 'ArrowUp') { e.preventDefault(); items[(i - 1 + items.length) % items.length].focus(); }
		if (e.key === 'Escape') { e.preventDefault(); close(true); }
	});

	var cur = sheet.querySelector('.is-picked') || sheet.querySelector('[data-i]:not([disabled])');
	if (cur) {
		cur.focus({ preventScroll: true });
		cur.scrollIntoView({ block: 'center' });
	}
}

function choose(i) {
	var sel = target;
	var o = sel && sel.options[i];
	close(false);
	if (!o || o.disabled) return;
	if (sel.selectedIndex !== i) {
		sel.selectedIndex = i;
		sel.dispatchEvent(new Event('input', { bubbles: true }));
		sel.dispatchEvent(new Event('change', { bubbles: true }));
	}
	sel.focus({ preventScroll: true });
}

function close(refocus) {
	if (!sheet) return;
	sheet.remove();
	shade.remove();
	document.documentElement.classList.remove('ecrm-noscroll');
	sheet = null;
	shade = null;
	var t = target;
	target = null;
	if (refocus && t && document.contains(t)) t.focus({ preventScroll: true });
}

export function initSelectSheet() {
	if (typeof window === 'undefined' || !window.matchMedia) return;

	// Το δάχτυλο: ανοίγει στο τέλος του αγγίγματος, και μόνο αν δεν ήταν κύλιση.
	// Το preventDefault() στο touchend κόβει το click, άρα και τη λίστα του
	// συστήματος.
	document.addEventListener('touchstart', function (e) {
		touch = eligible(e.target) && e.touches.length === 1
			? { el: e.target, x: e.touches[0].clientX, y: e.touches[0].clientY } : null;
	}, { passive: true, capture: true });
	document.addEventListener('touchend', function (e) {
		var t = touch;
		touch = null;
		if (!t || e.target !== t.el || !e.changedTouches.length) return;
		var c = e.changedTouches[0];
		if (Math.abs(c.clientX - t.x) > 10 || Math.abs(c.clientY - t.y) > 10) return;
		e.preventDefault();
		open(t.el);
	}, { passive: false, capture: true });
	// Ποντίκι ή γραφίδα σε στενή οθόνη με αφή (σπάνιο): το ίδιο.
	document.addEventListener('mousedown', function (e) {
		if (eligible(e.target)) { e.preventDefault(); if (!sheet) open(e.target); }
	}, true);
	document.addEventListener('keydown', function (e) {
		if (!eligible(e.target) || sheet) return;
		if (e.key === 'Enter' || e.key === ' ' || (e.altKey && e.key === 'ArrowDown')) { e.preventDefault(); open(e.target); }
	}, true);
}
