/* Energy CRM — δικό μας ημερολόγιο για τα πεδία ημερομηνίας (367).
 *
 * Το αναδυόμενο ημερολόγιο του <input type="date"> το ζωγραφίζει ο browser:
 * στα αγγλικά, με τα δικά του χρώματα, και δεν αλλάζει με CSS. Εδώ, σε
 * υπολογιστή (ποντίκι), το πάτημα στο πεδίο ανοίγει ένα ελληνικό ημερολόγιο
 * με τα χρώματα του CRM. Το πεδίο ΜΕΝΕΙ type="date": η τιμή (YYYY-MM-DD), τα
 * min/max, το γράψιμο με πληκτρολόγιο και ό,τι το διαβάζει δεν αλλάζουν.
 * Στο κινητό μένει το ημερολόγιο του κινητού, που εκεί είναι το καλύτερο.
 *
 * Ένας listener για όλη τη σελίδα, όχι ανά πεδίο: τα πεδία ημερομηνίας
 * ζωγραφίζονται και ξαναζωγραφίζονται συνέχεια (φόρμα, παράθυρα, έγγραφα).
 * Καθαρό DOM, καμία εξάρτηση.
 *
 * (378) Και τα πεδία ημερομηνίας ΜΕ ώρα (`datetime-local`: προθεσμία
 * εργασίας, επανάκληση υποψηφίου) ανοίγουν το ίδιο ημερολόγιο, με μια σειρά
 * «Ώρα» από κάτω· ο ιδιοκτήτης: «κάνε όλα τα ημερολόγια όπως κάναμε το
 * άλλο» (13/11). Η τιμή μένει YYYY-MM-DDTHH:MM. Εκεί το πάτημα σε μέρα δεν
 * κλείνει το ημερολόγιο (μένει η ώρα)· κλείνει με «Εντάξει».
 */

var MONTHS = ['Ιανουάριος', 'Φεβρουάριος', 'Μάρτιος', 'Απρίλιος', 'Μάιος', 'Ιούνιος',
	'Ιούλιος', 'Αύγουστος', 'Σεπτέμβριος', 'Οκτώβριος', 'Νοέμβριος', 'Δεκέμβριος'];
var DAYS = ['Δε', 'Τρ', 'Τε', 'Πε', 'Πα', 'Σα', 'Κυ'];

var pop = null;      // το ανοιχτό ημερολόγιο
var target = null;   // το πεδίο του
var shown = null;    // ο μήνας που φαίνεται: { y, m }
var clock = null;    // (378) η ώρα, σε πεδίο datetime-local: { h, mi }

function pad(n) { return (n < 10 ? '0' : '') + n; }
function iso(y, m, d) { return y + '-' + pad(m + 1) + '-' + pad(d); }

function parse(v) {
	var m = /^(\d{4})-(\d{2})-(\d{2})(?:T(\d{2}):(\d{2}))?/.exec(String(v || ''));
	return m ? { y: +m[1], m: +m[2] - 1, d: +m[3], h: m[4] != null ? +m[4] : null, mi: m[5] != null ? +m[5] : null } : null;
}

function withTime() { return !!target && target.type === 'datetime-local'; }

/* Η ημερομηνία μόνο, για σύγκριση με min/max ή για «σήμερα». */
function datePart(v) { return String(v || '').slice(0, 10); }

function todayParts() {
	var t = new Date();
	return { y: t.getFullYear(), m: t.getMonth(), d: t.getDate() };
}

function inRange(v) {
	if (!target) { return true; }
	if (target.min && v < datePart(target.min)) { return false; }
	if (target.max && v > datePart(target.max)) { return false; }
	return true;
}

function yearRange() {
	var now = new Date().getFullYear();
	var lo = parse(target && target.min);
	var hi = parse(target && target.max);
	return { from: lo ? lo.y : now - 110, to: hi ? hi.y : now + 30 };
}

function render() {
	var sel = parse(target.value);
	var t = todayParts();
	var tIso = iso(t.y, t.m, t.d);
	var first = new Date(shown.y, shown.m, 1);
	var lead = (first.getDay() + 6) % 7; // Δευτέρα πρώτη
	var days = new Date(shown.y, shown.m + 1, 0).getDate();

	var cells = '';
	for (var i = 0; i < lead; i++) { cells += '<span></span>'; }
	for (var d = 1; d <= days; d++) {
		var v = iso(shown.y, shown.m, d);
		var cls = 'ecrm-dp__day' + (v === tIso ? ' is-today' : '') + (sel && v === iso(sel.y, sel.m, sel.d) ? ' is-on' : '');
		cells += '<button type="button" class="' + cls + '" data-dp-day="' + v + '"' + (inRange(v) ? '' : ' disabled') + '>' + d + '</button>';
	}

	var r = yearRange();
	var years = '';
	for (var y = r.to; y >= r.from; y--) {
		years += '<option value="' + y + '"' + (y === shown.y ? ' selected' : '') + '>' + y + '</option>';
	}
	var months = MONTHS.map(function (name, i) {
		return '<option value="' + i + '"' + (i === shown.m ? ' selected' : '') + '>' + name + '</option>';
	}).join('');

	pop.innerHTML =
		'<div class="ecrm-dp__head">' +
			'<button type="button" class="ecrm-dp__nav" data-dp-move="-1" aria-label="Προηγούμενος μήνας">‹</button>' +
			'<select class="ecrm-dp__sel" data-dp-month aria-label="Μήνας">' + months + '</select>' +
			'<select class="ecrm-dp__sel" data-dp-year aria-label="Έτος">' + years + '</select>' +
			'<button type="button" class="ecrm-dp__nav" data-dp-move="1" aria-label="Επόμενος μήνας">›</button>' +
		'</div>' +
		'<div class="ecrm-dp__wd" aria-hidden="true">' + DAYS.map(function (n) { return '<span>' + n + '</span>'; }).join('') + '</div>' +
		'<div class="ecrm-dp__grid">' + cells + '</div>' +
		(withTime() ? timeRow() : '') +
		'<div class="ecrm-dp__foot">' +
			'<button type="button" class="ecrm-dp__link" data-dp-clear>Καθαρισμός</button>' +
			(inRange(tIso) ? '<button type="button" class="ecrm-dp__link" data-dp-day="' + tIso + '">Σήμερα</button>' : '') +
			(withTime() ? '<button type="button" class="ecrm-dp__ok" data-dp-ok>Εντάξει</button>' : '') +
		'</div>';
}

/* (378) «Ώρα 10 : 00»: ώρες 00–23, λεπτά ανά 5 (και το τρέχον, αν δεν είναι). */
function timeRow() {
	var hours = '';
	for (var h = 0; h < 24; h++) {
		hours += '<option value="' + h + '"' + (h === clock.h ? ' selected' : '') + '>' + pad(h) + '</option>';
	}
	var mins = '';
	var cur = Number(clock.mi) || 0; // πάντα αριθμός
	for (var mi = 0; mi < 60; mi += 5) {
		if (cur > mi - 5 && cur < mi && cur % 5) {
			mins += '<option value="' + cur + '" selected>' + pad(cur) + '</option>';
		}
		mins += '<option value="' + mi + '"' + (mi === cur ? ' selected' : '') + '>' + pad(mi) + '</option>';
	}
	if (cur > 55) { mins += '<option value="' + cur + '" selected>' + pad(cur) + '</option>'; }
	return '<div class="ecrm-dp__time"><span>Ώρα</span>' +
		'<select class="ecrm-dp__sel" data-dp-h aria-label="Ώρα">' + hours + '</select><b>:</b>' +
		'<select class="ecrm-dp__sel" data-dp-mi aria-label="Λεπτά">' + mins + '</select></div>';
}

function place() {
	var r = target.getBoundingClientRect();
	var w = pop.offsetWidth;
	var h = pop.offsetHeight;
	var left = Math.min(Math.max(8, r.left), window.innerWidth - w - 8);
	var top = r.bottom + 6;
	if (top + h > window.innerHeight - 8 && r.top - h - 6 > 8) { top = r.top - h - 6; }
	pop.style.left = left + 'px';
	pop.style.top = Math.max(8, top) + 'px';
}

function set(value) {
	// (378) Σε πεδίο με ώρα, η μέρα παίρνει την ώρα της σειράς «Ώρα».
	if (value && withTime()) { value = datePart(value) + 'T' + pad(clock.h) + ':' + pad(clock.mi); }
	target.value = value;
	target.dispatchEvent(new Event('input', { bubbles: true }));
	target.dispatchEvent(new Event('change', { bubbles: true }));
}

function close(refocus) {
	if (!pop) { return; }
	pop.remove();
	pop = null;
	var t = target;
	target = null;
	window.removeEventListener('resize', onMove);
	document.removeEventListener('scroll', onMove, true);
	if (refocus && t) { t.focus(); }
}

function onMove() { if (pop && target) { place(); } }

function open(input) {
	if (pop && target === input) { return; }
	close(false);
	target = input;
	var cur = parse(input.value) || parse(input.min && input.min > iso(todayParts().y, todayParts().m, todayParts().d) ? input.min : '') || todayParts();
	shown = { y: cur.y, m: cur.m };
	// Ώρα: του πεδίου, αλλιώς 10:00 (η συνηθισμένη ώρα για κλήση).
	var had = parse(input.value);
	clock = { h: had && had.h != null ? had.h : 10, mi: had && had.mi != null ? had.mi : 0 };

	pop = document.createElement('div');
	pop.className = 'ecrm-dp';
	pop.setAttribute('role', 'dialog');
	pop.setAttribute('aria-label', 'Ημερολόγιο');
	// Μέσα στο .ecrm-app, ώστε να παίρνει τα χρώματα της εμφάνισης (και της
	// νέας και του σκοτεινού θέματος).
	(input.closest('.ecrm-app') || document.getElementById('ecrm-app') || document.body).appendChild(pop);
	render();
	place();

	pop.addEventListener('mousedown', function (e) {
		// Το πεδίο να μη χάνει την εστίαση, εκτός από τα select μήνα/έτους.
		if (!e.target.closest('select')) { e.preventDefault(); }
	});
	pop.addEventListener('click', function (e) {
		var day = e.target.closest('[data-dp-day]');
		if (day && !day.disabled) {
			set(day.getAttribute('data-dp-day'));
			if (withTime()) {
				var p = parse(target.value);
				shown = { y: p.y, m: p.m };
				render();
				return;
			}
			close(true);
			return;
		}
		if (e.target.closest('[data-dp-ok]')) { close(true); return; }
		if (e.target.closest('[data-dp-clear]')) { set(''); close(true); return; }
		var mv = e.target.closest('[data-dp-move]');
		if (mv) {
			var n = new Date(shown.y, shown.m + Number(mv.getAttribute('data-dp-move')), 1);
			shown = { y: n.getFullYear(), m: n.getMonth() };
			render();
		}
	});
	pop.addEventListener('change', function (e) {
		if (e.target.matches('[data-dp-month]')) { shown.m = +e.target.value; render(); }
		if (e.target.matches('[data-dp-year]')) { shown.y = +e.target.value; render(); }
		// (378) Αλλαγή ώρας: γράφεται αμέσως, αν υπάρχει ήδη μέρα.
		if (e.target.matches('[data-dp-h], [data-dp-mi]')) {
			clock = { h: +pop.querySelector('[data-dp-h]').value, mi: +pop.querySelector('[data-dp-mi]').value };
			if (target.value) { set(target.value); }
		}
	});
	pop.addEventListener('keydown', function (e) {
		if (e.key === 'Escape') { e.stopPropagation(); close(true); }
	});

	window.addEventListener('resize', onMove);
	document.addEventListener('scroll', onMove, true);
}

function eligible(el) {
	return el && el.matches && el.matches('input[type="date"], input[type="datetime-local"]') && !el.disabled && !el.readOnly &&
		!!el.closest('.ecrm-app, .ecrm-modalov');
}

export function initDatePick() {
	if (typeof window === 'undefined' || !window.matchMedia || !window.matchMedia('(pointer: fine)').matches) { return; }
	document.documentElement.classList.add('ecrm-dp-on');

	document.addEventListener('click', function (e) {
		var el = e.target;
		if (eligible(el)) {
			e.preventDefault(); // όχι το ημερολόγιο του browser
			open(el);
			return;
		}
		if (pop && !pop.contains(el)) { close(false); }
	}, true);

	// Από το πληκτρολόγιο: Alt+↓ ανοίγει, όπως στο ημερολόγιο του browser.
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && pop) { close(true); return; }
		if (e.altKey && e.key === 'ArrowDown' && eligible(e.target)) { e.preventDefault(); open(e.target); }
		if (e.key === 'Tab' && pop && e.target === target) { close(false); }
	});
}
