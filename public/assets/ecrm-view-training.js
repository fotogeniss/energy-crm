/* Energy CRM — «Εκπαίδευση»: τα μαθήματα του ίδιου του CRM, με σειρά και πρόοδο.
 *
 * Ίδιος πίνακας με τη Βάση Γνώσης (section=training), άλλη οθόνη — και αυτό
 * είναι όλη η ιδέα: το περιεχόμενο γράφεται, εισάγεται και εξάγεται από τα
 * ήδη υπάρχοντα εργαλεία του admin, ενώ ο πωλητής βλέπει κάτι που δεν μοιάζει
 * καθόλου με κατάλογο δικαιολογητικών.
 *
 * Τα μαθήματα δεν έχουν πάροχο, οπότε το endpoint τα γυρίζει σε μία ομάδα,
 * ήδη ταξινομημένα κατά sort_order. Γι' αυτό το groups απλώς ισοπεδώνεται.
 */
import { api, esc, fetch, H, toast, viewEl } from '@energy-crm/util';

export function loadTraining() {
	var view = viewEl('training');
	fetch(api('/kb') + '?section=training', { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (!d || !d.ok) { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα.</div></div>'; return; }
			renderTraining(view, d);
		})
		.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα δικτύου.</div></div>'; });
}

/* Όλα τα μαθήματα σε μία λίστα, με τη σειρά που τα έστειλε ο server. */
function flatten(d) {
	var out = [];
	(d.groups || []).forEach(function (g) {
		(g.entries || []).forEach(function (e) { out.push(e); });
	});
	return out;
}

/* Εκτίμηση χρόνου ανάγνωσης — από το ίδιο το κείμενο, όχι από πεδίο που θα
 * έπρεπε να συμπληρώνει κάποιος σωστά κάθε φορά. 180 λέξεις/λεπτό. */
function minutes(html) {
	var words = String(html || '').replace(/<[^>]*>/g, ' ').trim().split(/\s+/).length;
	return Math.max(1, Math.round(words / 180));
}

/* (363) «Signature»: η διαδρομή. Πάνω η κάρτα «Συνέχισε από εδώ» με το
 * πρώτο αδιάβαστο μάθημα και δίπλα κύκλος προόδου· από κάτω τα μαθήματα ως
 * σταθμοί σε μια γραμμή. Ίδια data-* με την κλασική εμφάνιση.
 * docs/UI-TRAINING-FRESH.html, Α. */
function isSignature() {
	var app = document.getElementById('ecrm-app');
	return !!app && app.getAttribute('data-ui') === 'signature';
}

/* Η πρώτη πρόταση του κειμένου, για την κάρτα· τα μαθήματα δεν έχουν πεδίο
 * περίληψης, και δεν χρειάζεται να συμπληρώνει κάποιος ένα ακόμα. */
function firstSentence(html) {
	var t = String(html || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
	var m = t.match(/^.{20,180}?[.;!·](\s|$)/);
	var out = m ? m[0].trim() : t.slice(0, 160);
	return out.length < t.length && !m ? out.replace(/\s+\S*$/, '') + '…' : out;
}

var RING_R = 50;
var RING_C = 2 * Math.PI * RING_R;

function sigRing() {
	return '<svg class="sig-tring__svg" viewBox="0 0 120 120" aria-hidden="true">' +
		'<circle cx="60" cy="60" r="' + RING_R + '" class="sig-tring__bg"/>' +
		'<circle cx="60" cy="60" r="' + RING_R + '" class="sig-tring__fg" data-train-ring stroke-dasharray="0 ' + RING_C.toFixed(1) + '" transform="rotate(-90 60 60)"/>' +
		'<text x="60" y="68" text-anchor="middle" data-train-pct>0%</text></svg>';
}

function sigLessonsHTML(list) {
	return list.map(function (e, i) {
		return '<div class="ecrm-lesson sig-tstep' + (e.read ? ' is-done' : '') + '" data-lesson="' + (e.id | 0) + '" data-lesson-min="' + minutes(e.body) + '">' +
			'<div class="ecrm-lesson__row">' +
				'<span class="sig-tstep__dot" aria-hidden="true" data-n="' + (i + 1) + '"></span>' +
				'<button type="button" class="ecrm-lesson__t" data-lesson-toggle aria-expanded="false">' +
					'<span class="ecrm-lesson__ttl">' + esc(e.title) + '</span>' +
					'<span class="ecrm-lesson__min">' + minutes(e.body) + ' λεπτά<em class="sig-tstep__next"> · το επόμενο</em></span>' +
				'</button>' +
				'<button type="button" class="ecrm-lesson__read" data-lesson-read>' + (e.read ? '✓ Διαβάστηκε' : 'Το διάβασα') + '</button>' +
			'</div>' +
			'<div class="ecrm-lesson__body" hidden>' + (e.body || '') + '</div>' +
		'</div>';
	}).join('');
}

function renderTraining(view, d) {
	var list = flatten(d);
	var prog = d.progress || { done: 0, total: list.length };
	var sig = isSignature();

	var rows = list.map(function (e, i) {
		return '<div class="ecrm-lesson' + (e.read ? ' is-done' : '') + '" data-lesson="' + (e.id | 0) + '">' +
			'<div class="ecrm-lesson__row">' +
				'<span class="ecrm-lesson__n">' + (i + 1) + '</span>' +
				'<button type="button" class="ecrm-lesson__t" data-lesson-toggle>' +
					'<span class="ecrm-lesson__ttl">' + esc(e.title) + '</span>' +
					'<span class="ecrm-lesson__min">' + minutes(e.body) + ' λεπτά</span>' +
				'</button>' +
				'<button type="button" class="ecrm-lesson__read" data-lesson-read>' +
					(e.read ? '✓ Διαβάστηκε' : 'Το διάβασα') +
				'</button>' +
			'</div>' +
			'<div class="ecrm-lesson__body" hidden>' + (e.body || '') + '</div>' +
		'</div>';
	}).join('');

	if (!list.length) {
		rows = '<div class="ecrm-card"><div class="ecrm-empty">Δεν έχουν προστεθεί μαθήματα ακόμα. ' +
			'Προστίθενται από Energy CRM → Βάση Γνώσης, με Ενότητα «Εκπαίδευση».</div></div>';
	}

	if (sig) {
		view.innerHTML =
			'<header class="sig-cuhead"><div><h2>Εκπαίδευση</h2><p>Με τη σειρά. Δεν χρειάζεται να τα ξέρεις όλα σήμερα.</p></div></header>' +
			(list.length
				? '<div class="sig-thero">' +
					'<div class="sig-tnext" data-train-next></div>' +
					'<div class="sig-tring">' + sigRing() + '<div><b data-train-val></b><span data-train-left></span></div></div>' +
				'</div>' +
				'<div class="ecrm-lessonlist sig-tpath">' + sigLessonsHTML(list) + '</div>'
				: '<div class="sig-tempty"><b>Δεν έχουν προστεθεί μαθήματα ακόμα.</b><span>Προστίθενται από Energy CRM → Βάση Γνώσης, με Ενότητα «Εκπαίδευση».</span></div>');
		if (!list.length) { return; }
	} else {
	view.innerHTML =
		'<header class="ecrm-head"><div class="ecrm-eyebrow">Μάθε</div><h2 class="ecrm-title">Εκπαίδευση</h2>' +
		'<p class="ecrm-sub">Με τη σειρά. Ξεκίνα από το 1 — δεν χρειάζεται να τα ξέρεις όλα σήμερα.</p></header>' +
		'<div class="ecrm-trainhead">' +
			'<div class="ecrm-trainhead__top">' +
				'<b class="ecrm-trainhead__lbl">Η πρόοδός σου</b>' +
				'<span class="ecrm-trainhead__val" data-train-val></span>' +
			'</div>' +
			'<div class="ecrm-meter"><div class="ecrm-meter__f" data-train-fill></div></div>' +
		'</div>' +
		'<div class="ecrm-lessonlist">' + rows + '</div>';
	}

	var valEl = view.querySelector('[data-train-val]');
	var fillEl = view.querySelector('[data-train-fill]');

	function paint(done, total) {
		valEl.textContent = (done | 0) + ' από ' + (total | 0);
		var pct = total > 0 ? Math.round(done / total * 100) : 0;
		if (fillEl) { fillEl.style.width = pct + '%'; }
		if (sig) { paintSig(pct); }
	}

	/* Ο κύκλος, τα λεπτά που μένουν, ο τονισμένος σταθμός και η κάρτα
	 * «Συνέχισε από εδώ» βγαίνουν από το ποια μαθήματα έχουν is-done τώρα. */
	function paintSig(pct) {
		var ring = view.querySelector('[data-train-ring]');
		if (ring) { ring.setAttribute('stroke-dasharray', (RING_C * pct / 100).toFixed(1) + ' ' + RING_C.toFixed(1)); }
		var pctEl = view.querySelector('[data-train-pct]');
		if (pctEl) { pctEl.textContent = pct + '%'; }

		var steps = Array.prototype.slice.call(view.querySelectorAll('.sig-tstep'));
		var left = 0, next = null, nextIdx = -1;
		steps.forEach(function (st, i) {
			st.classList.remove('is-cur');
			if (!st.classList.contains('is-done')) {
				left += parseInt(st.getAttribute('data-lesson-min'), 10) || 0;
				if (!next) { next = st; nextIdx = i; }
			}
		});
		if (next) { next.classList.add('is-cur'); }

		var leftEl = view.querySelector('[data-train-left]');
		if (leftEl) {
			leftEl.textContent = next
				? 'μαθήματα διαβασμένα. Περίπου ' + left + (left === 1 ? ' λεπτό' : ' λεπτά') + ' ακόμα.'
				: 'Τα διάβασες όλα. Μπράβο!';
		}

		var card = view.querySelector('[data-train-next]');
		if (!card) { return; }
		if (!next) {
			card.classList.add('is-done');
			card.innerHTML = '<small>ΟΛΟΚΛΗΡΩΘΗΚΕ</small><h3>Τα διάβασες όλα</h3>' +
				'<p>Μπορείς να ξανανοίξεις όποιο μάθημα θέλεις από τη λίστα. Όταν προστεθεί καινούριο, θα εμφανιστεί εδώ.</p>';
			return;
		}
		card.classList.remove('is-done');
		var e = list[nextIdx];
		card.innerHTML = '<small>ΣΥΝΕΧΙΣΕ ΑΠΟ ΕΔΩ · ΜΑΘΗΜΑ ' + (nextIdx + 1) + '</small>' +
			'<h3>' + esc(e.title) + '</h3>' +
			'<p>' + esc(firstSentence(e.body)) + '</p>' +
			'<button type="button" class="ecrm-btn ecrm-btn--primary" data-train-start="' + (e.id | 0) + '">Ξεκίνα · ' + minutes(e.body) + (minutes(e.body) === 1 ? ' λεπτό' : ' λεπτά') + '</button>';
	}
	paint(prog.done, prog.total);

	if (sig) {
		view.querySelector('[data-train-next]').addEventListener('click', function (ev) {
			var b = ev.target.closest('[data-train-start]');
			if (!b) { return; }
			var row = view.querySelector('[data-lesson="' + b.getAttribute('data-train-start') + '"]');
			if (!row) { return; }
			var body = row.querySelector('.ecrm-lesson__body');
			body.hidden = false;
			row.classList.add('is-open');
			var t = row.querySelector('[data-lesson-toggle]');
			if (t) { t.setAttribute('aria-expanded', 'true'); }
			row.scrollIntoView({ behavior: 'smooth', block: 'start' });
		});
	}

	var listEl = view.querySelector('.ecrm-lessonlist');

	listEl.addEventListener('click', function (ev) {
		var toggle = ev.target.closest('[data-lesson-toggle]');
		if (toggle) {
			var row = toggle.closest('.ecrm-lesson');
			var body = row.querySelector('.ecrm-lesson__body');
			var open = !body.hidden;
			body.hidden = open;
			row.classList.toggle('is-open', !open);
			toggle.setAttribute('aria-expanded', String(!open));
			return;
		}

		var btn = ev.target.closest('[data-lesson-read]');
		if (!btn) { return; }

		var lesson = btn.closest('.ecrm-lesson');
		var id = parseInt(lesson.getAttribute('data-lesson'), 10) || 0;
		var on = !lesson.classList.contains('is-done');

		/* Το κουμπί κλειδώνει όσο τρέχει το αίτημα: το διπλό κλικ το κρατάει
		 * ο unique key της βάσης, αλλά η οθόνη δεν πρέπει καν να το επιτρέψει. */
		btn.disabled = true;
		fetch(api('/kb/read'), {
			method: 'POST',
			headers: Object.assign({ 'Content-Type': 'application/json' }, H()),
			body: JSON.stringify({ id: id, read: on })
		})
			.then(function (r) { return r.json(); })
			.then(function (res) {
				btn.disabled = false;
				if (!res || !res.ok) { toast((res && res.error) || 'Αποτυχία.', false); return; }
				lesson.classList.toggle('is-done', !!res.read);
				btn.textContent = res.read ? '✓ Διαβάστηκε' : 'Το διάβασα';
				/* Το «πόσα από πόσα» έρχεται από τον server, δεν το μετράει ο
				 * browser: αν προστεθεί ή αποσυρθεί μάθημα όσο η οθόνη είναι
				 * ανοιχτή, το τοπικό άθροισμα θα ήταν λάθος σιωπηλά. */
				paint(res.done, res.total);
			})
			.catch(function () { btn.disabled = false; toast('Σφάλμα δικτύου.', false); });
	});
}
