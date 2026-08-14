/* Energy CRM — the Excel export dialog.
 *
 * Takes the filters it should default to as an argument. It used to read them
 * straight out of the contracts view's state, which was convenient and wrong:
 * once that view became a module of its own, a modal reaching into it would
 * have closed an import cycle. Being handed what it needs also means the
 * dialog can be opened from anywhere with any filters. */

import { api, esc, fetch, H, root, toast } from '@energy-crm/util';

export function openExportModal(filters) {
	filters = filters || {};
	var statuses = (window.ECRM && ECRM.statuses) || {};
	var statusOpts = '<option value="">Όλες</option>' + Object.keys(statuses).map(function (k) {
		return '<option value="' + esc(k) + '"' + (filters.status === k ? ' selected' : '') + '>' + esc(statuses[k]) + '</option>';
	}).join('');

	var ov = document.createElement('div');
	ov.className = 'ecrm-modalov';
	ov.innerHTML =
		'<div class="ecrm-modal" role="dialog" aria-modal="true">' +
			'<button type="button" class="ecrm-modal__x" data-x aria-label="Κλείσιμο">×</button>' +
			'<div class="ecrm-modal__eyebrow">⤓ Εξαγωγή σε Excel</div>' +
			'<h3 class="ecrm-modal__title">Συμβάσεις — φιλτράρισμα &amp; λήψη</h3>' +
			'<p class="ecrm-modal__lead">Διάλεξε <strong>κατάσταση</strong> και προαιρετικά <strong>εύρος ημερομηνιών</strong>. Το αρχείο κατεβαίνει σε <code>.xlsx</code> με τις στήλες της σελίδας συμβάσεων.</p>' +
			'<div class="ecrm-modal__card">' +
				'<div class="ecrm-field"><span class="ecrm-field__label">🏳 Κατάσταση</span><select class="ecrm-input" data-x-status>' + statusOpts + '</select></div>' +
			'</div>' +
			'<div class="ecrm-modal__card">' +
				'<div class="ecrm-field__label">📅 Εύρος ημερομηνιών <span class="ecrm-muted">(προαιρετικό)</span></div>' +
				'<div class="ecrm-modal__row">' +
					'<label class="ecrm-field"><span class="ecrm-field__label">Από</span><input type="date" class="ecrm-input" data-x-from></label>' +
					'<label class="ecrm-field"><span class="ecrm-field__label">Έως</span><input type="date" class="ecrm-input" data-x-to></label>' +
				'</div>' +
			'</div>' +
			'<label class="ecrm-modal__scope-sel"><span class="ecrm-field__label">👥 Συνεργάτες</span>' +
				'<select class="ecrm-input" data-x-partner><option value="me">Μόνο εγώ</option></select></label>' +
			'<div class="ecrm-modal__bar">' +
				'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-x-clear>↻ Καθαρισμός</button>' +
				'<span style="flex:1"></span>' +
				'<button type="button" class="ecrm-btn ecrm-btn--ghost" data-x>Ακύρωση</button>' +
				'<button type="button" class="ecrm-btn ecrm-btn--primary" data-x-go>⤓ Λήψη Excel</button>' +
			'</div>' +
		'</div>';
	root().appendChild(ov);

	function close() { ov.remove(); }
	ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
	ov.querySelectorAll('[data-x]').forEach(function (b) { b.addEventListener('click', close); });

	// Populate the partners dropdown (managers see team members).
	var partnerSel = ov.querySelector('[data-x-partner]');
	fetch(api('/team'), { headers: H() }).then(function (r) { return r.json(); }).then(function (t) {
		var members = (t && t.members) || [];
		if (members.length) {
			var all = document.createElement('option'); all.value = 'team'; all.textContent = 'Όλη η ομάδα'; partnerSel.appendChild(all);
			members.forEach(function (m) { var o = document.createElement('option'); o.value = String(m.id); o.textContent = m.name; partnerSel.appendChild(o); });
			if (filters.scope === 'team') partnerSel.value = 'team';
		}
	}).catch(function () {});

	ov.querySelector('[data-x-clear]').addEventListener('click', function () {
		ov.querySelector('[data-x-status]').value = '';
		ov.querySelector('[data-x-from]').value = '';
		ov.querySelector('[data-x-to]').value = '';
		partnerSel.value = 'me';
	});

	var goBtn = ov.querySelector('[data-x-go]');
	goBtn.addEventListener('click', function () {
		var status = ov.querySelector('[data-x-status]').value;
		var from = ov.querySelector('[data-x-from]').value;
		var to = ov.querySelector('[data-x-to]').value;
		var pv = partnerSel.value;
		var scope = 'own', partner = '';
		if (pv === 'team') { scope = 'team'; }
		else if (pv !== 'me') { partner = pv; }
		var qs = '?status=' + encodeURIComponent(status) + '&from=' + encodeURIComponent(from) +
			'&to=' + encodeURIComponent(to) + '&scope=' + scope + '&partner=' + encodeURIComponent(partner) +
			'&q=' + encodeURIComponent(filters.q || '');
		goBtn.disabled = true; var t = goBtn.textContent; goBtn.textContent = 'Δημιουργία…';
		fetch(api('/contracts/export') + qs, { headers: H() })
			.then(function (r) { return r.json(); })
			.then(function (d) {
				if (!d || !d.ok) { toast((d && d.error) || 'Αποτυχία.', false); goBtn.disabled = false; goBtn.textContent = t; return; }
				var bin = atob(d.b64), len = bin.length, arr = new Uint8Array(len);
				for (var i = 0; i < len; i++) { arr[i] = bin.charCodeAt(i); }
				var a = document.createElement('a');
				a.href = URL.createObjectURL(new Blob([arr], { type: d.mime }));
				a.download = d.filename || 'symvaseis.xlsx'; document.body.appendChild(a); a.click();
				setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
				toast('Εξήχθησαν ' + d.count + ' συμβάσεις.');
				close();
			})
			.catch(function () { toast('Σφάλμα δικτύου.', false); goBtn.disabled = false; goBtn.textContent = t; });
	});
}
