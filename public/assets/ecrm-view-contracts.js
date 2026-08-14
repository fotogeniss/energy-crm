/* Energy CRM — η λίστα συμβάσεων.
 *
 * Η μεγαλύτερη οθόνη του CRM και η τελευταία που έμενε στο κέλυφος: καρτέλες
 * κατάστασης με μετρητές, αναζήτηση, αποθηκευμένα φίλτρα, σελιδοποίηση στον
 * client, ανάπτυξη γραμμής, και μαζικές ενέργειες.
 *
 * Το contractsState μένει εδώ γιατί εδώ ανήκει — εκτός από το scope, που το
 * μοιράζεται με τις Εκκρεμότητες και γι' αυτό ζει στο @energy-crm/scope.
 * Δες το docblock εκεί: αν έμενε ιδιότητα αυτού του αρχείου, οι Εκκρεμότητες
 * θα εισήγαγαν τη λίστα συμβάσεων για να διαβάσουν μία λέξη.
 *
 * Η σελιδοποίηση είναι στον client: το /contracts επιστρέφει όλες τις γραμμές
 * του φίλτρου και κόβονται εδώ. Δουλεύει επειδή τα φίλτρα κρατάνε το σύνολο
 * μικρό· αν ποτέ μια σελίδα αργήσει, το πρόβλημα είναι εδώ και όχι στο δίκτυο. */

import { api, can, esc, fetch, H, toast, viewEl } from '@energy-crm/util';
import { fmtDate, initials, svgIcon, timeAgo, tint, up } from '@energy-crm/format';
import { openDetail, openEdit } from '@energy-crm/navigate';
import { openExportModal } from '@energy-crm/export-modal';
import { scope, setScope } from '@energy-crm/scope';

var contractsState = { status: '', q: '', page: 1, pageSize: 12 };

export function loadContracts() {
	var view = viewEl('contracts');
	var url = api('/contracts') + '?status=' + encodeURIComponent(contractsState.status) + '&q=' + encodeURIComponent(contractsState.q) + '&scope=' + encodeURIComponent(scope());
	fetch(url, { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) { renderContracts(view, d); })
		.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα φόρτωσης.</div></div>'; });
}

var expandCache = {};
function loadExpand(id, view) {
	var box = view.querySelector('[data-exppanel="' + id + '"]');
	if (!box) return;
	if (expandCache[id]) { box.innerHTML = expandPanel(expandCache[id], view); bindExpand(box, expandCache[id], view); return; }
	fetch(api('/contracts/' + id), { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (!d || !d.ok) { box.innerHTML = '<div class="ecrm-empty">Σφάλμα φόρτωσης.</div>'; return; }
			expandCache[id] = d.contract || d;
			box.innerHTML = expandPanel(expandCache[id], view);
			bindExpand(box, expandCache[id], view);
		})
		.catch(function () { box.innerHTML = '<div class="ecrm-empty">Σφάλμα δικτύου.</div>'; });
}

function expandPanel(c, view) {
	var statuses = (window.ECRM && ECRM.statuses) || {};
	var stLabel = statuses[c.status] || c.status;
	var name = c.company_name || ((c.first_name || '') + ' ' + (c.last_name || '')).trim() || '—';
	var mobile = c.mobile || c.phone || '';
	var dl = function (l, v) { return '<div class="ecrm-tr"><span>' + l + '</span><b>' + (v ? esc(v) : '—') + '</b></div>'; };

	var banner =
		'<div class="ecrm-expbanner ecrm-expbanner--' + esc(c.status) + '">' +
		'<span class="ecrm-expbanner__ico">' + (c.status === 'draft' ? '🗎' : (c.status === 'cancelled' || c.status === 'terminated' ? '✕' : '!')) + '</span>' +
		'<div><div class="ecrm-expbanner__eyebrow">' + esc(up(stLabel)) + '</div>' +
		'<div class="ecrm-expbanner__title">' + esc(stLabel) + ' για ' + esc(name) + '</div>' +
		'<div class="ecrm-expbanner__sub">Δημιουργήθηκε: <b>' + fmtDate(c.created_at) + '</b> · Αριθμός συμβολαίου: <b>' + esc(c.code || '—') + '</b></div></div></div>';

	var cards =
		'<div class="ecrm-expcards">' +
		'<div class="ecrm-card"><div class="ecrm-step">Στοιχεία πελάτη</div>' + dl('Ονοματεπώνυμο', name) + dl('ΑΦΜ', c.afm) + dl('Κινητό', mobile) + '</div>' +
		'<div class="ecrm-card"><div class="ecrm-step">Παροχή</div>' + dl('ΗΚΑΣΠ/Παροχή', c.supply_number) + dl('Τιμολόγιο', c.invoice_code) + dl('Πάροχος', c.provider_name) + '</div>' +
		'<div class="ecrm-card"><div class="ecrm-step">Tracking</div>' + dl('Ημ. δημιουργίας', fmtDate(c.created_at)) + dl('Ημ. Thalis', '') + dl('Ημ. Εκπροσώπησης', '') + dl('Ημ. Τερματισμού', '') + dl('Τελ. ενημέρωση', fmtDate(c.updated_at)) + '</div>' +
		'</div>';

	var phone = mobile || c.phone || '';
	var email = c.email || '';
	var viberNum = (phone || '').replace(/[^0-9+]/g, '');
	if (viberNum && viberNum.charAt(0) !== '+') { viberNum = '+30' + viberNum.replace(/^0+/, ''); }
	var actions = '<div class="ecrm-expactions">' +
		'<button type="button" class="ecrm-btn ecrm-btn--primary ecrm-btn--sm" data-exp-edit>' + svgIcon('edit') + ' Προβολή / Επεξεργασία</button>' +
		(phone ? '<a class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" href="tel:' + esc(phone) + '">' + svgIcon('phone') + ' Κάλεσε</a>' : '') +
		(viberNum ? '<a class="ecrm-btn ecrm-btn--viber ecrm-btn--sm" href="viber://chat?number=' + encodeURIComponent(viberNum) + '" target="_blank" rel="noopener">' + svgIcon('viber') + ' Viber</a>' : '') +
		(email ? '<a class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" href="mailto:' + esc(email) + '">' + svgIcon('mail') + ' Email</a>' : '') +
		(c.status === 'draft' ? '<button type="button" class="ecrm-btn ecrm-btn--danger ecrm-btn--sm" data-exp-del>' + svgIcon('trash') + ' Διαγραφή</button>' : '') +
		'</div>';

	return banner + cards + actions;
}

function bindExpand(box, c, view) {
	var edit = box.querySelector('[data-exp-edit]');
	if (edit) edit.addEventListener('click', function () { openEdit(c); });
	var del = box.querySelector('[data-exp-del]');
	if (del) del.addEventListener('click', function () {
		if (!confirm('Διαγραφή πρόχειρης σύμβασης; Δεν αναιρείται.')) return;
		var b = this; b.disabled = true;
		fetch(api('/contracts/' + c.id), { method: 'DELETE', headers: H() })
			.then(function (r) { return r.json(); })
			.then(function (d) { if (d && d.ok) { toast('Διαγράφηκε.'); delete expandCache[c.id]; loadContracts(); } else { toast((d && d.error) || 'Αποτυχία.', false); b.disabled = false; } })
			.catch(function () { toast('Σφάλμα δικτύου.', false); b.disabled = false; });
	});
}

function renderContracts(view, d) {
	var statuses = d.statuses || {}, counts = d.counts || {};
	var allRows = d.rows || [];

	// status tabs — show ALL statuses with counts + colour dots (like PSS)
	var tabs = '<button type="button" class="ecrm-tab' + (contractsState.status === '' ? ' is-on' : '') + '" data-status=""><span class="ecrm-tabdot ecrm-tabdot--all"></span>ΟΛΕΣ <b>' + (counts.all || 0) + '</b></button>';
	Object.keys(statuses).forEach(function (st) {
		tabs += '<button type="button" class="ecrm-tab' + (contractsState.status === st ? ' is-on' : '') + '" data-status="' + st + '"><span class="ecrm-tabdot ecrm-tabdot--' + esc(st) + '"></span>' + esc(up(statuses[st])) + ' <b>' + (counts[st] || 0) + '</b></button>';
	});

	// client-side pagination
	var total = allRows.length;
	var pages = Math.max(1, Math.ceil(total / contractsState.pageSize));
	if (contractsState.page > pages) contractsState.page = pages;
	var startI = (contractsState.page - 1) * contractsState.pageSize;
	var rowsPage = allRows.slice(startI, startI + contractsState.pageSize);

	// Ownership only matters when more than one partner is in view.
	var showOwner = scope() === 'team';

	var body = rowsPage.map(function (r) {
		var name = r.company_name || ((r.first_name || '') + ' ' + (r.last_name || '')).trim() || '—';
		var stLabel = statuses[r.status] || r.status;
		var prov = r.provider_logo
			? '<img class="ecrm-cell-logo" src="' + esc(r.provider_logo) + '" alt="">'
			: '<span class="ecrm-cell-mark" style="--h:' + tint(r.provider_name || r.provider_slug || '') + '">' + esc(initials(r.provider_name || '·')) + '</span>';
		var cust = '<span class="ecrm-cell-mark ecrm-cell-mark--cust" style="--h:' + tint(name) + '">' + esc(initials(name)) + '</span>';
		var inv = r.invoice_code ? '<span class="ecrm-tariff">' + esc(r.invoice_code) + '</span>' : '<span class="ecrm-muted">—</span>';
		return '<tr class="ecrm-rowlink" data-id="' + r.id + '">' +
			'<td class="ecrm-checkcol"><input type="checkbox" class="ecrm-rowcheck" data-cid="' + r.id + '"></td>' +
			'<td class="ecrm-expandcol"><button type="button" class="ecrm-expand" data-expand="' + r.id + '" aria-label="Λεπτομέρειες">+</button></td>' +
			'<td><span class="ecrm-cell-prov">' + prov + '<span>' + esc(r.provider_name || '—') + '</span></span></td>' +
			'<td>' + esc(r.program_name || '—') + '</td>' +
			'<td><span class="ecrm-cell-cust">' + cust + '<span>' + esc(name) + '</span></span></td>' +
			(showOwner ? '<td><span class="ecrm-cell-owner">' + esc(r.partner_name || '—') + '</span></td>' : '') +
			'<td class="ecrm-mono">' + esc(r.supply_number || '') + '</td>' +
			'<td>' + inv + '</td>' +
			'<td><span class="ecrm-badge ecrm-badge--' + esc(r.status) + '">' + esc(stLabel) + '</span></td>' +
			'<td class="ecrm-cell-date"><div>' + fmtDate(r.updated_at) + '</div><div class="ecrm-muted">' + timeAgo(r.updated_at) + '</div></td>' +
			'</tr>' +
			'<tr class="ecrm-exprow" data-exprow="' + r.id + '" hidden><td class="ecrm-expaccent" data-status="' + esc(r.status) + '"></td><td colspan="' + (showOwner ? 9 : 8) + '"><div class="ecrm-exppanel" data-exppanel="' + r.id + '"><div class="ecrm-loading">Φόρτωση…</div></div></td></tr>';
	}).join('');

	var table = total
		? '<div class="ecrm-bulkbar" data-bulkbar hidden><span class="ecrm-bulkbar__n"><b data-bulk-n>0</b> επιλεγμένες</span>' +
			'<select class="ecrm-input ecrm-input--sm" data-bulk-status><option value="">— Αλλαγή κατάστασης —</option>' +
			Object.keys(statuses).map(function (s) { return '<option value="' + esc(s) + '">' + esc(statuses[s]) + '</option>'; }).join('') +
			'</select><button type="button" class="ecrm-btn ecrm-btn--sm ecrm-btn--primary" data-bulk-apply-status>Εφαρμογή</button>' +
			(scope() === 'team' ? '<select class="ecrm-input ecrm-input--sm" data-bulk-assign><option value="">— Ανάθεση σε… —</option></select><button type="button" class="ecrm-btn ecrm-btn--sm" data-bulk-apply-assign>Ανάθεση</button>' : '') +
			(can('ecrm_export_data') ? '<button type="button" class="ecrm-btn ecrm-btn--sm ecrm-btn--ghost" data-bulk-export>⤓ Export επιλογής</button>' : '') +
			(can('ecrm_delete_contract') ? '<button type="button" class="ecrm-btn ecrm-btn--sm ecrm-btn--danger" data-bulk-delete>🗑 Διαγραφή</button>' : '') +
			'<button type="button" class="ecrm-btn ecrm-btn--sm ecrm-btn--ghost" data-bulk-clear>Καθαρισμός</button></div>' +
			'<div class="ecrm-tablewrap"><table class="ecrm-table ecrm-table--rich"><thead><tr>' +
			'<th class="ecrm-checkcol"><input type="checkbox" data-checkall></th><th></th><th>Πάροχος</th><th>Πρόγραμμα</th><th>Πελάτης</th>' +
			(showOwner ? '<th>Συνεργάτης</th>' : '') +
			'<th>ΗΚΑΣΠ / Παροχή</th><th>Τιμολόγιο</th><th>Κατάσταση</th><th>Ενημέρωση</th>' +
			'</tr></thead><tbody>' + body + '</tbody></table></div>' +
			'<div class="ecrm-pager"><span>Σύνολο <b>' + total + '</b> συμβάσεις</span>' +
			'<div class="ecrm-pager__nav"><button type="button" class="ecrm-pager__b" data-page="prev"' + (contractsState.page <= 1 ? ' disabled' : '') + '>‹ Προηγούμενη</button>' +
			'<span class="ecrm-pager__pos">Σελίδα ' + contractsState.page + ' από ' + pages + '</span>' +
			'<button type="button" class="ecrm-pager__b" data-page="next"' + (contractsState.page >= pages ? ' disabled' : '') + '>Επόμενη ›</button></div></div>'
		: '<div class="ecrm-empty">Δεν υπάρχουν συμβάσεις' + (contractsState.q ? ' για «' + esc(contractsState.q) + '»' : '') + '.</div>';

	view.innerHTML = '' +
		'<header class="ecrm-head ecrm-head--row"><div><h2 class="ecrm-title">Οι συμβάσεις μου</h2><p class="ecrm-sub">' + total + ' συμβάσεις · κάνε click σε γραμμή για λεπτομέρειες</p></div>' +
		'<div class="ecrm-head__actions">' +
		'<div class="ecrm-scope"><button type="button" class="ecrm-scope__b' + (scope()==="own"?" is-on":"") + '" data-scope="own">Δικά μου</button><button type="button" class="ecrm-scope__b' + (scope()==="team"?" is-on":"") + '" data-scope="team">Ομάδας</button></div>' +
		'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-export>⤓ Export Excel</button></div></header>' +
		'<div class="ecrm-card">' +
		'<div class="ecrm-search-row"><div class="ecrm-search"><input type="search" class="ecrm-input" placeholder="Αναζήτηση πελάτη, ΑΦΜ, ΗΚΑΣΠ, αριθμού συμβολαίου…" value="' + esc(contractsState.q) + '" data-search></div>' +
		'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-save-filter>★ Αποθήκευση φίλτρου</button>' +
		'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-toggle-filters>⚙ Φίλτρα</button></div>' +
		'<div class="ecrm-savedfilters" data-savedfilters></div>' +
		'<div class="ecrm-tabs" data-tabs>' + tabs + '</div>' +
		table + '</div>';

	view.querySelectorAll('.ecrm-tab').forEach(function (t) {
		t.addEventListener('click', function () { contractsState.status = this.getAttribute('data-status'); contractsState.page = 1; loadContracts(); });
	});
	var search = view.querySelector('[data-search]');
	if (search) {
		var timer;
		search.addEventListener('input', function () { clearTimeout(timer); var val = this.value; timer = setTimeout(function () { contractsState.q = val; contractsState.page = 1; loadContracts(); }, 350); });
	}
	var ft = view.querySelector('[data-toggle-filters]');
	if (ft) ft.addEventListener('click', function () { var t = view.querySelector('[data-tabs]'); if (t) t.classList.toggle('is-hidden'); });

	// ---- saved filters ----
	var sfBox = view.querySelector('[data-savedfilters]');
	function renderSavedFilters(list) {
		if (!sfBox) return;
		if (!list.length) { sfBox.innerHTML = ''; return; }
		sfBox.innerHTML = '<span class="ecrm-savedfilters__lbl">Αποθηκευμένα:</span>' + list.map(function (f, i) {
			return '<span class="ecrm-savedchip" data-sf="' + i + '"><button type="button" class="ecrm-savedchip__go" data-sfgo="' + i + '">' + esc(f.name) + '</button>' +
				'<button type="button" class="ecrm-savedchip__x" data-sfdel="' + i + '" aria-label="Διαγραφή">×</button></span>';
		}).join('');
		sfBox.querySelectorAll('[data-sfgo]').forEach(function (b) {
			b.addEventListener('click', function () {
				var f = list[+this.getAttribute('data-sfgo')];
				contractsState.status = f.status || ''; contractsState.q = f.q || ''; setScope((f.scope === 'team' ? 'team' : 'own')); contractsState.page = 1;
				loadContracts();
			});
		});
		sfBox.querySelectorAll('[data-sfdel]').forEach(function (b) {
			b.addEventListener('click', function () {
				fetch(api('/filters/' + this.getAttribute('data-sfdel')), { method: 'DELETE', headers: H() })
					.then(function (r) { return r.json(); }).then(function (d) { if (d && d.ok) renderSavedFilters(d.filters || []); });
			});
		});
	}
	fetch(api('/filters'), { headers: H() }).then(function (r) { return r.json(); })
		.then(function (d) { if (d && d.ok) renderSavedFilters(d.filters || []); }).catch(function () {});

	var saveBtn = view.querySelector('[data-save-filter]');
	if (saveBtn) saveBtn.addEventListener('click', function () {
		var dflt = (statuses[contractsState.status] || 'Όλες') + (contractsState.q ? ' · ' + contractsState.q : '') + (scope() === 'team' ? ' · Ομάδας' : '');
		var name = prompt('Όνομα φίλτρου:', dflt);
		if (!name) return;
		fetch(api('/filters'), { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H()),
			body: JSON.stringify({ name: name, status: contractsState.status, q: contractsState.q, scope: scope() }) })
			.then(function (r) { return r.json(); })
			.then(function (d) { if (d && d.ok) { toast('Αποθηκεύτηκε φίλτρο.'); renderSavedFilters(d.filters || []); } else { toast((d && d.error) || 'Αποτυχία.', false); } })
			.catch(function () { toast('Σφάλμα δικτύου.', false); });
	});

	view.querySelectorAll('.ecrm-rowlink').forEach(function (tr) {
		tr.addEventListener('click', function () { openDetail(this.getAttribute('data-id')); });
	});
	view.querySelectorAll('[data-expand]').forEach(function (b) {
		b.addEventListener('click', function (e) {
			e.stopPropagation();
			var id = this.getAttribute('data-expand');
			var row = view.querySelector('[data-exprow="' + id + '"]');
			if (!row) return;
			row.hidden = !row.hidden;
			this.textContent = row.hidden ? '+' : '×';
			this.classList.toggle('is-open', !row.hidden);
			if (!row.hidden) loadExpand(id, view);
		});
	});
	view.querySelectorAll('[data-page]').forEach(function (b) {
		b.addEventListener('click', function () {
			if (this.disabled) return;
			contractsState.page += (this.getAttribute('data-page') === 'next' ? 1 : -1);
			loadContracts();
		});
	});

	var exportBtn = view.querySelector('[data-export]');
	if (exportBtn) exportBtn.addEventListener('click', function () {
		openExportModal({ status: contractsState.status, scope: scope(), q: contractsState.q });
	});
	view.querySelectorAll('[data-scope]').forEach(function (b) { b.addEventListener('click', function () { setScope(this.getAttribute('data-scope')); contractsState.page = 1; loadContracts(); }); });

	// ---- bulk selection & actions ----
	var bar = view.querySelector('[data-bulkbar]');
	function selectedIds() { return Array.prototype.slice.call(view.querySelectorAll('.ecrm-rowcheck:checked')).map(function (c) { return +c.getAttribute('data-cid'); }); }
	function refreshBar() {
		if (!bar) return;
		var ids = selectedIds();
		bar.hidden = ids.length === 0;
		var n = bar.querySelector('[data-bulk-n]'); if (n) n.textContent = ids.length;
	}
	view.querySelectorAll('.ecrm-rowcheck').forEach(function (cb) {
		cb.addEventListener('click', function (e) { e.stopPropagation(); refreshBar(); });
	});
	var checkAll = view.querySelector('[data-checkall]');
	if (checkAll) checkAll.addEventListener('click', function (e) {
		e.stopPropagation();
		var on = this.checked;
		view.querySelectorAll('.ecrm-rowcheck').forEach(function (c) { c.checked = on; });
		refreshBar();
	});
	if (bar) {
		// populate assignee list (team scope)
		var assignSel = bar.querySelector('[data-bulk-assign]');
		if (assignSel) {
			fetch(api('/team'), { headers: H() }).then(function (r) { return r.json(); }).then(function (t) {
				(t && t.members || []).forEach(function (m) { var o = document.createElement('option'); o.value = m.id; o.textContent = m.name; assignSel.appendChild(o); });
			}).catch(function () {});
		}
		function runBulk(body, okMsg) {
			var ids = selectedIds();
			if (!ids.length) { toast('Δεν επιλέχθηκαν συμβάσεις.', false); return; }
			body.ids = ids;
			fetch(api('/contracts/bulk'), { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H()), body: JSON.stringify(body) })
				.then(function (r) { return r.json(); })
				.then(function (d) {
					if (!d || !d.ok) { toast((d && d.error) || 'Αποτυχία.', false); return; }
					if (d.b64) {
						var bin = atob(d.b64), len = bin.length, arr = new Uint8Array(len);
						for (var i = 0; i < len; i++) arr[i] = bin.charCodeAt(i);
						var url = URL.createObjectURL(new Blob([arr], { type: d.mime }));
						var a = document.createElement('a'); a.href = url; a.download = d.filename || 'export.xlsx'; document.body.appendChild(a); a.click(); a.remove();
						setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
						toast('Εξήχθησαν ' + d.count + ' συμβάσεις.');
						return;
					}
					// Nothing moved and the pipeline explained why: lead with
					// that, instead of reporting a success that never happened.
					if (d.notice && !d.updated) { toast(d.notice, false); loadContracts(); return; }

					var msg = okMsg + (d.updated != null ? ' (' + d.updated + ')' : '');
					if (d.notice) msg += ' · ' + d.notice;
					if (d.skipped) msg += ' · παραλείφθηκαν ' + d.skipped + ' (ελλιπή δικαιολογητικά)';
					toast(msg);
					loadContracts();
				})
				.catch(function () { toast('Σφάλμα δικτύου.', false); });
		}
		var bs = bar.querySelector('[data-bulk-apply-status]');
		if (bs) bs.addEventListener('click', function () {
			var v = bar.querySelector('[data-bulk-status]').value;
			if (!v) { toast('Διάλεξε κατάσταση.', false); return; }
			runBulk({ action: 'status', value: v }, 'Ενημερώθηκαν');
		});
		var ba = bar.querySelector('[data-bulk-apply-assign]');
		if (ba) ba.addEventListener('click', function () {
			var v = assignSel ? assignSel.value : '';
			if (!v) { toast('Διάλεξε μέλος.', false); return; }
			runBulk({ action: 'assign', value: +v }, 'Ανατέθηκαν');
		});
		var be = bar.querySelector('[data-bulk-export]');
		if (be) be.addEventListener('click', function () { runBulk({ action: 'export' }, ''); });
		var bd = bar.querySelector('[data-bulk-delete]');
		if (bd) bd.addEventListener('click', function () {
			var ids = selectedIds();
			if (!ids.length) { toast('Δεν επιλέχθηκαν αιτήσεις.', false); return; }
			if (!window.confirm('Διαγραφή ' + ids.length + ' αιτήσεων;\nΗ ενέργεια είναι οριστική και θα διαγράψει και τα σχετικά έγγραφα/υπογραφές.')) return;
			runBulk({ action: 'delete' }, 'Διαγράφηκαν');
		});
		var bc = bar.querySelector('[data-bulk-clear]');
		if (bc) bc.addEventListener('click', function () {
			view.querySelectorAll('.ecrm-rowcheck').forEach(function (c) { c.checked = false; });
			if (checkAll) checkAll.checked = false;
			refreshBar();
		});
	}
}
