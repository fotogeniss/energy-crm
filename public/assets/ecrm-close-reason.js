/* Energy CRM — λόγος απόρριψης/ακύρωσης (342). docs/UI-REJECT-REASONS.html.
 *
 * Ένας διάλογος για την καρτέλα αίτησης και τη μαζική αλλαγή: λίστα λόγων
 * (window.ECRM.closeReasons, από το Domain\Contract\CloseReason) και
 * σημείωση, υποχρεωτική στο «Άλλο». Ο server ελέγχει ξανά τα ίδια.
 */

import { esc } from '@energy-crm/util';
import { openDialog } from '@energy-crm/dialog';

export function reasonsFor(status) {
	var all = (window.ECRM && ECRM.closeReasons) || {};
	return all[status] || [];
}

/**
 * @param {string} status      Η κατάσταση-στόχος.
 * @param {string} statusLabel Η ελληνική της ετικέτα.
 * @param {number} count       Πόσες αιτήσεις (μαζική αλλαγή), αλλιώς 1.
 * @return {Promise<{reason: string, note: string}|null>} null = άκυρο.
 */
export function pickReason(status, statusLabel, count) {
	var list = reasonsFor(status);
	if (!list.length) return Promise.resolve({ reason: '', note: '' });

	return new Promise(function (resolve) {
		var done = false;
		var body = '<div class="ecrm-cr" role="radiogroup">' + list.map(function (r) {
			return '<label class="ecrm-cr__opt"><input type="radio" name="ecrm-close-reason" value="' + esc(r[0]) + '"> ' + esc(r[1]) + '</label>';
		}).join('') + '</div>' +
			'<label class="ecrm-field ecrm-cr__note"><span class="ecrm-field__label">Σημείωση <span data-cr-req>(προαιρετική)</span></span>' +
			'<textarea class="ecrm-textarea" rows="2" maxlength="500" data-cr-note></textarea></label>' +
			'<div class="ecrm-cr__err" data-cr-err hidden></div>';

		var dlg = openDialog({
			eyebrow: statusLabel,
			title: status === 'cancelled' ? 'Γιατί ακυρώθηκε;' : 'Γιατί απορρίφθηκε;',
			lead: count > 1 ? 'Ο ίδιος λόγος θα γραφτεί σε ' + count + ' αιτήσεις.' : '',
			body: body,
			danger: true,
			confirm: 'Αλλαγή κατάστασης',
			onClose: function () { if (!done) resolve(null); },
			onConfirm: function (ov, close) {
				var picked = ov.querySelector('input[name="ecrm-close-reason"]:checked');
				var note = ov.querySelector('[data-cr-note]').value.trim();
				var err = ov.querySelector('[data-cr-err]');
				if (!picked) { err.hidden = false; err.textContent = 'Διάλεξε λόγο.'; return; }
				if (picked.value === 'other' && !note) { err.hidden = false; err.textContent = 'Γράψε τι έγινε στο «Άλλο».'; return; }
				done = true;
				close();
				resolve({ reason: picked.value, note: note });
			},
		});

		dlg.el.querySelectorAll('input[name="ecrm-close-reason"]').forEach(function (i) {
			i.addEventListener('change', function () {
				dlg.el.querySelector('[data-cr-req]').textContent = this.value === 'other' ? '(υποχρεωτική)' : '(προαιρετική)';
			});
		});
	});
}
