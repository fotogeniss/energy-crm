/* Energy CRM — one contract, in full: fields, documents, history, actions.
 *
 * The heaviest screen in the app and the one an agent spends the most time
 * on. It owns no state — everything it shows comes from a single fetch, and
 * every button either navigates away or posts and re-fetches.
 *
 * copyText() and downloadBinary() came with it rather than going to the
 * shared module. Both have exactly one caller, here, and a helper with one
 * user is not shared — it is misplaced. */

import { api, esc, fetch, H, toast, viewEl } from '@energy-crm/util';
import { energyLabel, fmtDate, svgIcon, timeAgo } from '@energy-crm/format';
import { go, openEdit } from '@energy-crm/navigate';

function copyText(text) {
	text = String(text == null ? '' : text);
	if (navigator.clipboard && window.isSecureContext) {
		return navigator.clipboard.writeText(text).then(function () { return true; }, function () { return legacyCopy(text); });
	}
	return Promise.resolve(legacyCopy(text));
}
function legacyCopy(text) {
	try {
		var ta = document.createElement('textarea');
		ta.value = text;
		ta.setAttribute('readonly', '');
		ta.style.position = 'fixed';
		ta.style.top = '-1000px';
		ta.style.opacity = '0';
		document.body.appendChild(ta);
		ta.select();
		ta.setSelectionRange(0, ta.value.length);
		var ok = document.execCommand('copy');
		document.body.removeChild(ta);
		return ok;
	} catch (e) { return false; }
}
export function openDetail(id) {
	go('contract-detail');
	var view = viewEl('contract-detail');
	view.innerHTML = '<div class="ecrm-loading">Φόρτωση…</div>';
	fetch(api('/contracts/' + id), { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) { if (!d || !d.ok) { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">' + esc((d && d.error) || 'Σφάλμα.') + '</div></div>'; return; } renderDetail(view, d); })
		.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα φόρτωσης.</div></div>'; });
}
function field(label, val) {
	return '<div class="ecrm-dl"><dt>' + esc(label) + '</dt><dd>' + (val ? esc(val) : '—') + '</dd></div>';
}
function filesCard(c) {
	var files = c.files || [];
	var kindLabel = c.doc_kinds || { id_card: 'Ταυτότητα', provider_bill: 'Λογαριασμός', other: 'Έγγραφο' };

	// Required-docs checklist
	var checklist = '';
	if (c.doc_checklist && c.doc_checklist.items && c.doc_checklist.items.length) {
		var ck = c.doc_checklist;
		var rows = ck.items.map(function (it) {
			return '<li class="ecrm-check ' + (it.ok ? 'is-ok' : 'is-missing') + '">' +
				'<span class="ecrm-check__mark">' + (it.ok ? '✓' : '○') + '</span>' +
				'<span>' + esc(it.label) + '</span></li>';
		}).join('');
		var banner = ck.complete
			? '<div class="ecrm-check__note is-ok">Όλα τα δικαιολογητικά παρόντα.</div>'
			: '<div class="ecrm-check__note is-missing">Λείπουν δικαιολογητικά — δεν μπορεί να δρομολογηθεί/ενεργοποιηθεί.</div>';
		checklist = '<div class="ecrm-step">Απαιτούμενα δικαιολογητικά</div><ul class="ecrm-checklist">' + rows + '</ul>' + banner;
	}

	var list;
	if (!files.length) {
		list = '<div class="ecrm-empty">Δεν έχουν επισυναφθεί έγγραφα.</div>';
	} else {
		list = '<div class="ecrm-files">' + files.map(function (f) {
			var thumb = f.is_image && f.url ? '<img src="' + esc(f.url) + '" alt="">' : '<span class="ecrm-file__ext">' + (f.mime === 'application/pdf' ? 'PDF' : 'DOC') + '</span>';
			return '<a class="ecrm-file" href="' + esc(f.url || '#') + '" target="_blank" rel="noopener">' +
				'<span class="ecrm-file__thumb">' + thumb + '</span>' +
				'<span class="ecrm-file__meta"><span class="ecrm-file__name">' + esc(f.filename || 'έγγραφο') + '</span>' +
				'<span class="ecrm-file__kind">' + esc(kindLabel[f.doc_kind] || 'Έγγραφο') + '</span></span></a>';
		}).join('') + '</div>';
	}

	// Inline upload control
	var kindOpts = Object.keys(kindLabel).map(function (k) {
		return '<option value="' + esc(k) + '">' + esc(kindLabel[k]) + '</option>';
	}).join('');
	var upload = '<div class="ecrm-docup" data-docup="' + c.id + '">' +
		'<select class="ecrm-input ecrm-docup__kind" data-docup-kind>' + kindOpts + '</select>' +
		'<input type="file" multiple accept="image/*,application/pdf" data-docup-file>' +
		'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-docup-go>Προσθήκη</button>' +
		'<span class="ecrm-docup__msg" data-docup-msg></span></div>';

	return '<div class="ecrm-card">' + checklist + '<div class="ecrm-step">Έγγραφα</div>' + list + upload + '</div>';
}
function renderDetail(view, d) {
	var c = d.contract, statuses = d.statuses || {}, acts = d.activation_types || {};
	var name = c.company_name || ((c.first_name || '') + ' ' + (c.last_name || '')).trim() || '—';
	var energy = energyLabel(c.energy_type);

	var statusOpts = Object.keys(statuses).map(function (s) {
		return '<button type="button" class="ecrm-statuschip ecrm-badge--' + s + (c.status === s ? ' is-on' : '') + '" data-status="' + s + '">' + esc(statuses[s]) + '</button>';
	}).join('');

	var timeline = (c.events && c.events.length)
		? '<ul class="ecrm-timeline">' + c.events.map(function (e) {
			var label = e.type === 'status_change'
				? (statuses[e.from_status] || e.from_status || '—') + ' → ' + (statuses[e.to_status] || e.to_status || '—')
				: (e.message || e.type);
			return '<li><span class="ecrm-timeline__dot"></span><div><div class="ecrm-timeline__txt">' + esc(label) + '</div><div class="ecrm-timeline__time">' + timeAgo(e.created_at) + '</div></div></li>';
		}).join('') + '</ul>'
		: '<div class="ecrm-empty">Καμία καταγραφή.</div>';

	view.innerHTML = '' +
		'<div class="ecrm-detailhead"><button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-go="contracts">← Πίσω</button>' +
		'<div><div class="ecrm-eyebrow">' + esc(c.code || '') + '</div><h2 class="ecrm-title">' + esc(name) + '</h2></div>' +
		'<button type="button" class="ecrm-btn ecrm-btn--primary ecrm-btn--sm" data-detail-edit>' + svgIcon('edit') + ' Επεξεργασία</button>' +
		'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-printform="' + c.id + '">🖨 Εκτύπωση εντύπου</button>' +
		'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-provform="' + c.id + '">📄 Λήψη εντύπου παρόχου</button>' +
		'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-sign="' + c.id + '">✍ Αποστολή για υπογραφή</button>' +
		(c.track_url ? '<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-track="' + esc(c.track_url) + '">🔗 Link παρακολούθησης</button>' : '') +
		'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-task-new="' + c.id + '">＋ Εργασία</button>' +
		'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm ecrm-btn--danger" data-detail-del="' + c.id + '">🗑 Διαγραφή</button>' +
		'<span class="ecrm-badge ecrm-badge--' + esc(c.status) + '">' + esc(statuses[c.status] || c.status) + '</span>' +
		(c.consent_at ? '<span class="ecrm-chip-consent" title="Συναίνεση: ' + esc(fmtDate(c.consent_at)) + '">✓ GDPR</span>' : '') +
		'</div>' +

		'<div class="ecrm-cols">' +
		'<div class="ecrm-card"><div class="ecrm-step">Στοιχεία πελάτη</div><div class="ecrm-dlgrid">' +
		field('ΑΦΜ', c.afm) + field('ΔΟΥ', c.doy) + field('ΑΔΤ', c.adt) +
		field('Όνομα', c.first_name) + field('Επίθετο', c.last_name) + field('Πατρώνυμο', c.father_name) +
		field('Ημ. Γέννησης', c.birth_date) + field('Κινητό', c.mobile) + field('Τηλέφωνο', c.phone) +
		field('Email', c.email) + field('Διεύθυνση', [c.street, c.street_no].filter(Boolean).join(' ')) +
		field('Πόλη', c.city) + field('Νομός', c.region) + field('ΤΚ', c.postal_code) +
		'</div></div>' +
		'<div class="ecrm-card"><div class="ecrm-step">Στοιχεία αίτησης</div><div class="ecrm-dlgrid">' +
		field('Πάροχος', c.provider_name) + field('Πρόγραμμα', c.program_name) + field('Είδος', energy) +
		field('Ενεργοποίηση', acts[c.activation_type] || c.activation_type) +
		field('Αριθμός Παροχής', c.supply_number) + field('Μετρητής', c.meter_number) + field('Τιμολόγιο', c.invoice_code) +
		'</div>' + (c.notes ? '<div class="ecrm-notes"><strong>Σχόλια:</strong> ' + esc(c.notes) + '</div>' : '') + '</div>' +
		'</div>' +

		'<div class="ecrm-cols">' +
		'<div class="ecrm-card"><div class="ecrm-step">Αλλαγή κατάστασης</div><div class="ecrm-statuschips">' + statusOpts + '</div></div>' +
		'<div class="ecrm-card"><div class="ecrm-step">Ιστορικό</div>' + timeline + '</div>' +
		'</div>' +
		filesCard(c);

	view.querySelectorAll('.ecrm-statuschip').forEach(function (b) {
		b.addEventListener('click', function () {
			var to = this.getAttribute('data-status');
			if (to === c.status) return;
			fetch(api('/contracts/' + c.id + '/status'), { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H()), body: JSON.stringify({ status: to }) })
				.then(function (r) { return r.json(); })
				.then(function (res) { if (res && res.ok) { toast('Κατάσταση: ' + (statuses[to] || to)); openDetail(c.id); } else { toast((res && res.error) || 'Αποτυχία αλλαγής.', false); } })
				.catch(function () { toast('Σφάλμα δικτύου.', false); });
		});
	});

	var dEdit = view.querySelector('[data-detail-edit]');
	if (dEdit) dEdit.addEventListener('click', function () { openEdit(c); });

	var delBtn = view.querySelector('[data-detail-del]');
	if (delBtn) delBtn.addEventListener('click', function () {
		if (!window.confirm('Διαγραφή της αίτησης ' + (c.code || '') + ';\nΗ ενέργεια είναι οριστική και θα διαγράψει και τα σχετικά έγγραφα/υπογραφές.')) return;
		var b = this; b.disabled = true; var t = b.textContent; b.textContent = 'Διαγραφή…';
		fetch(api('/contracts/' + c.id), { method: 'DELETE', headers: H() })
			.then(function (r) { return r.text().then(function (x) { try { return JSON.parse(x); } catch (e) { throw new Error('HTTP ' + r.status); } }); })
			.then(function (d2) {
				if (d2 && d2.ok) { toast('Η αίτηση διαγράφηκε.', true); go('contracts'); }
				else { b.disabled = false; b.textContent = t; toast((d2 && d2.error) || 'Αποτυχία διαγραφής.', false); }
			})
			.catch(function (err) { b.disabled = false; b.textContent = t; toast((err && err.message) || 'Σφάλμα δικτύου.', false); });
	});
	var printBtn = view.querySelector('[data-printform]');
	if (printBtn) printBtn.addEventListener('click', function () {
		var b = this, win = window.open('', '_blank'); b.disabled = true; var t = b.textContent; b.textContent = 'Άνοιγμα…';
		fetch(api('/contracts/' + c.id + '/provider-form'), { headers: H() })
			.then(function (r) { return r.text(); })
			.then(function (x) {
				var d = JSON.parse(x);
				if (!d || !d.ok) throw new Error((d && d.error) || 'fail');
				var bin = atob(d.b64), len = bin.length, arr = new Uint8Array(len);
				for (var i = 0; i < len; i++) { arr[i] = bin.charCodeAt(i); }
				var url = URL.createObjectURL(new Blob([arr], { type: 'application/pdf' }));
				if (win) { win.location.href = url; } else { window.open(url, '_blank'); }
			})
			.catch(function (e) { if (win) { try { win.close(); } catch (er) {} } toast((e && e.message) || 'Αποτυχία.', false); })
			.finally(function () { b.disabled = false; b.textContent = t; });
	});
	var provBtn = view.querySelector('[data-provform]');
	if (provBtn) provBtn.addEventListener('click', function () { downloadBinary('/contracts/' + c.id + '/provider-form', this, 'Λήψη…', '📄 Λήψη εντύπου παρόχου'); });

	var signBtn = view.querySelector('[data-sign]');
	if (signBtn) signBtn.addEventListener('click', function () {
		var b = this; b.disabled = true;
		fetch(api('/contracts/' + c.id + '/sign-link'), { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H()), body: JSON.stringify({ email: true }) })
			.then(function (r) { return r.json(); })
			.then(function (d) {
				if (!d || !d.ok) { toast((d && d.error) || 'Αποτυχία.', false); return; }
				copyText(d.url).then(function (copied) {
					var lead = d.emailed
						? (copied ? 'Στάλθηκε email στον πελάτη. Σύνδεσμος (αντιγράφηκε):' : 'Στάλθηκε email στον πελάτη. Σύνδεσμος:')
						: (copied ? 'Σύνδεσμος υπογραφής (αντιγράφηκε) — στείλ τον στον πελάτη:' : 'Σύνδεσμος υπογραφής — αντίγραψέ τον και στείλ τον στον πελάτη:');
					prompt(lead, d.url);
					if (copied) { toast(d.emailed ? 'Στάλθηκε email υπογραφής στον πελάτη.' : 'Ο σύνδεσμος αντιγράφηκε.'); }
					else { toast(d.emailed ? 'Στάλθηκε email υπογραφής στον πελάτη.' : 'Ο σύνδεσμος δημιουργήθηκε.'); }
					openDetail(c.id);
				});
			})
			.catch(function () { toast('Σφάλμα δικτύου.', false); })
			.finally(function () { b.disabled = false; });
	});

	var trackBtn = view.querySelector('[data-track]');
	if (trackBtn) trackBtn.addEventListener('click', function () {
		var url = this.getAttribute('data-track');
		copyText(url).then(function (copied) {
			prompt(copied ? 'Σύνδεσμος παρακολούθησης (αντιγράφηκε) — στείλ τον στον πελάτη:' : 'Σύνδεσμος παρακολούθησης — αντίγραψέ τον και στείλ τον στον πελάτη:', url);
			toast(copied ? 'Ο σύνδεσμος παρακολούθησης αντιγράφηκε.' : 'Σύνδεσμος παρακολούθησης έτοιμος.');
		});
	});

	var taskNew = view.querySelector('[data-task-new]');
	if (taskNew) taskNew.addEventListener('click', function () {
		var title = prompt('Τίτλος εργασίας / επανάκλησης:', 'Επανάκληση πελάτη');
		if (!title) return;
		var when = prompt('Πότε; (π.χ. 2026-06-20 10:00 — άφησέ το κενό για χωρίς ημερομηνία)', '');
		fetch(api('/tasks'), { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H()), body: JSON.stringify({ title: title, due_at: when || '', contract_id: c.id }) })
			.then(function (r) { return r.json(); })
			.then(function (res) { if (res && res.ok) toast('Δημιουργήθηκε εργασία.'); else toast((res && res.error) || 'Αποτυχία.', false); })
			.catch(function () { toast('Σφάλμα δικτύου.', false); });
	});

	var docGo = view.querySelector('[data-docup-go]');
	if (docGo) docGo.addEventListener('click', function () {
		var wrap = view.querySelector('[data-docup]');
		var input = wrap.querySelector('[data-docup-file]');
		var kind = wrap.querySelector('[data-docup-kind]').value;
		var msg = wrap.querySelector('[data-docup-msg]');
		if (!input.files || !input.files.length) { msg.textContent = 'Επίλεξε αρχείο.'; msg.className = 'ecrm-docup__msg is-err'; return; }
		var fd = new FormData();
		for (var i = 0; i < input.files.length; i++) { fd.append('files[]', input.files[i]); fd.append('kinds[]', kind); }
		var b = this; b.disabled = true; msg.textContent = 'Ανέβασμα…'; msg.className = 'ecrm-docup__msg';
		fetch(api('/contracts/' + c.id + '/files'), { method: 'POST', headers: H(), body: fd })
			.then(function (r) { return r.json(); })
			.then(function (d) {
				if (d && d.ok) { toast('Προστέθηκαν ' + d.saved + ' έγγραφα.'); openDetail(c.id); }
				else { msg.textContent = (d && d.error) || 'Αποτυχία.'; msg.className = 'ecrm-docup__msg is-err'; b.disabled = false; }
			})
			.catch(function () { msg.textContent = 'Σφάλμα δικτύου.'; msg.className = 'ecrm-docup__msg is-err'; b.disabled = false; });
	});
}
function downloadBinary(path, btn, busy, idle) {
	btn.disabled = true; var t = btn.textContent; btn.textContent = busy;
	fetch(api(path), { headers: H() })
		.then(function (r) {
			return r.text().then(function (txt) {
				try { return JSON.parse(txt); }
				catch (e) { throw new Error('HTTP ' + r.status + ': ' + txt.slice(0, 300)); }
			});
		})
		.then(function (d) {
			if (!d || !d.ok) { toast((d && d.error) || 'Αποτυχία.', false); return; }
			var bin = atob(d.b64), len = bin.length, arr = new Uint8Array(len);
			for (var i = 0; i < len; i++) { arr[i] = bin.charCodeAt(i); }
			var a = document.createElement('a');
			a.href = URL.createObjectURL(new Blob([arr], { type: d.mime }));
			a.download = d.filename; document.body.appendChild(a); a.click();
			setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
		})
		.catch(function (err) { toast((err && err.message) || 'Σφάλμα δικτύου.', false); })
		.finally(function () { btn.disabled = false; btn.textContent = idle || t; });
}
