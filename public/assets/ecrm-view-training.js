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

function renderTraining(view, d) {
	var list = flatten(d);
	var prog = d.progress || { done: 0, total: list.length };

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

	var valEl = view.querySelector('[data-train-val]');
	var fillEl = view.querySelector('[data-train-fill]');

	function paint(done, total) {
		valEl.textContent = (done | 0) + ' από ' + (total | 0);
		fillEl.style.width = total > 0 ? Math.round(done / total * 100) + '%' : '0%';
	}
	paint(prog.done, prog.total);

	var listEl = view.querySelector('.ecrm-lessonlist');

	listEl.addEventListener('click', function (ev) {
		var toggle = ev.target.closest('[data-lesson-toggle]');
		if (toggle) {
			var row = toggle.closest('.ecrm-lesson');
			var body = row.querySelector('.ecrm-lesson__body');
			var open = !body.hidden;
			body.hidden = open;
			row.classList.toggle('is-open', !open);
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
