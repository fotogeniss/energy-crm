/* Energy CRM — καρτέλα πελάτη: όλα τα στοιχεία ενός πελάτη σε μία οθόνη.
 *
 * Build queue 09, 05/09 (247). Στάδιο 1 από τρία (docs/UI-CUSTOMER-CARD.html,
 * εγκρίθηκε 05/09): μόνο ανάγνωση. Πριν από αυτή την οθόνη, «άνοιξε τον
 * πελάτη» σήμαινε openCustomerContracts() -- τη λίστα «Συμβάσεις» φιλτραρισμένη
 * στο ΑΦΜ του (βλ. σχόλιο εκεί, build queue 08). Αυτό παραμένει, αλλά δεν
 * έλυνε ποτέ το ίδιο πρόβλημα: εδώ ο συνεργάτης βλέπει τον πελάτη, όχι μια
 * λίστα σχεδόν τυχαία φιλτραρισμένη γύρω του.
 *
 * Ενα μόνο fetch (CustomersController::card()) φέρνει πελάτη + συμβάσεις +
 * έγγραφα + τα τρία KPI μαζί -- τα KPI υπολογισμένα ΕΚΕΙ, όχι εδώ, ώστε το
 * "τι είναι ενεργό" να περνά πάντα από το ίδιο ContractStatus::isTerminal()
 * που ξέρει ήδη ο υπόλοιπος κώδικας. Ιδια στήλη-κατάστασης (ecrm-badge--*) με
 * τη λίστα «Συμβάσεις» -- ο συνεργάτης δεν μαθαίνει δεύτερη γλώσσα χρωμάτων.
 */

import { api, esc, fetch, H, toast, viewEl } from '@energy-crm/util';
import { fmtDate, initials, timeAgo, tint } from '@energy-crm/format';
import { go, openDetail } from '@energy-crm/navigate';
import { openDialog } from '@energy-crm/dialog';

var TYPE_LABEL = { individual: 'ΙΔΙΩΤΗΣ', company: 'ΕΤΑΙΡΕΙΑ', sole_prop: 'ΑΤΟΜΙΚΗ' };

export function openCustomerCard(id) {
	go('customer-detail');
	var view = viewEl('customer-detail');
	if (!view) { return; }
	view.innerHTML = '<div class="ecrm-loading">Φόρτωση…</div>';
	fetch(api('/customers/' + id + '/card'), { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (!d || !d.ok) {
				view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">' +
					esc((d && d.error) || 'Δεν βρέθηκε.') + '</div></div>';
				return;
			}
			renderCard(view, id, d);
		})
		.catch(function () {
			view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα φόρτωσης.</div></div>';
		});
}

function customerName(c) {
	var name = c.company_name || ((c.first_name || '') + ' ' + (c.last_name || '')).trim();
	return name || '—';
}

function kv(label, value) {
	return '<div class="ecrm-kv"><b>' + (value ? esc(value) : '<span class="ecrm-muted">—</span>') + '</b><span>' + esc(label) + '</span></div>';
}

function contractsTable(contracts, statuses) {
	if (!contracts.length) {
		return '<div class="ecrm-empty">Καμία σύμβαση ακόμη.</div>';
	}
	var rows = contracts.map(function (c) {
		return '<tr class="ecrm-rowlink" data-open="' + c.id + '">' +
			'<td><strong>' + esc(c.code || '—') + '</strong></td>' +
			'<td class="ecrm-muted ecrm-col-sec">' + esc(c.provider_name || '—') + '</td>' +
			'<td class="ecrm-muted ecrm-col-sec">' + esc(c.program_name || '—') + '</td>' +
			'<td><span class="ecrm-badge ecrm-badge--' + esc(c.status) + '">' + esc(statuses[c.status] || c.status) + '</span></td>' +
			'<td class="ecrm-muted ecrm-col-sec">' + (c.end_date ? fmtDate(c.end_date) : '—') + '</td>' +
			'</tr>';
	}).join('');
	return '<div class="ecrm-tablewrap"><table class="ecrm-table"><thead><tr>' +
		'<th>Κωδικός</th><th class="ecrm-col-sec">Πάροχος</th><th class="ecrm-col-sec">Πρόγραμμα</th><th>Κατάσταση</th><th class="ecrm-col-sec">Λήξη</th>' +
		'</tr></thead><tbody>' + rows + '</tbody></table></div>';
}

function expiringRows(contracts) {
	// Ιδιο κριτήριο με CustomersController::card()/ContractQueries::expiring():
	// days_left έρχεται έτοιμο από το backend, δεν ξαναϋπολογίζεται εδώ.
	var upcoming = contracts.filter(function (c) {
		return c.end_date && c.days_left != null && c.status !== 'draft'
			&& c.status !== 'cancelled' && c.status !== 'terminated' && c.status !== 'rejected';
	}).sort(function (a, b) { return a.days_left - b.days_left; });

	if (!upcoming.length) {
		return '<div class="ecrm-empty">Καμία επερχόμενη λήξη.</div>';
	}

	var rows = upcoming.map(function (c) {
		var left = +c.days_left;
		var cls = left <= 30 ? 'is-down' : '';
		return '<tr><td><strong>' + esc(c.code || '—') + '</strong>' +
			(c.program_name ? '<div class="ecrm-muted ecrm-tlrole">' + esc(c.program_name) + '</div>' : '') + '</td>' +
			'<td class="ecrm-muted ecrm-col-sec">' + fmtDate(c.end_date) + '</td>' +
			'<td><span class="ecrm-delta ' + cls + '">' + (left < 0 ? 'έληξε' : 'σε ' + left + (left === 1 ? ' ημέρα' : ' ημέρες')) + '</span></td>' +
			'</tr>';
	}).join('');
	return '<div class="ecrm-tablewrap"><table class="ecrm-table"><thead><tr>' +
		'<th>Σύμβαση</th><th class="ecrm-col-sec">Λήγει</th><th>Σε</th>' +
		'</tr></thead><tbody>' + rows + '</tbody></table></div>';
}

function documentsList(documents, docKinds, contracts) {
	if (!documents.length) {
		return '<div class="ecrm-empty">Κανένα έγγραφο ακόμη.</div>';
	}
	var codeById = {};
	contracts.forEach(function (c) { codeById[c.id] = c.code; });

	// Ιδιες κλάσεις (.ecrm-files/.ecrm-file) με τη «Συμβάσεις → Εγγραφα»,
	// έτσι ένα έγγραφο δείχνει ίδιο σε δύο σημεία -- μόνη προσθήκη εδώ είναι
	// ποια σύμβαση το έφερε, που δεν έχει νόημα στην κάρτα μιας σύμβασης.
	return '<div class="ecrm-files">' + documents.map(function (doc) {
		var label = (docKinds && docKinds[doc.doc_kind]) || doc.doc_kind || 'Έγγραφο';
		var from = codeById[doc.contract_id] || '';
		var thumb = doc.is_image && doc.url ? '<img src="' + esc(doc.url) + '" alt="">' : '<span class="ecrm-file__ext">' + (doc.mime === 'application/pdf' ? 'PDF' : 'DOC') + '</span>';
		return '<a class="ecrm-file" href="' + esc(doc.url || '#') + '" target="_blank" rel="noopener">' +
			'<span class="ecrm-file__thumb">' + thumb + '</span>' +
			'<span class="ecrm-file__meta"><span class="ecrm-file__name">' + esc(doc.filename || 'έγγραφο') + '</span>' +
			'<span class="ecrm-file__kind">' + esc(label) + (from ? ' · ' + esc(from) : '') + '</span></span>' +
			'</a>';
	}).join('') + '</div>';
}

/* 247, Στάδιο 2 (docs/UI-CUSTOMER-CARD.html): ελεύθερο κείμενο για τον
 * πελάτη, εκτός τυπωμένων εντύπων. Ιδιο οπτικό πρότυπο (.ecrm-timeline) με
 * το ιστορικό σύμβασης στο ecrm-view-detail.js -- ο συνεργάτης δεν μαθαίνει
 * τρίτη γλώσσα για "ποιος έγραψε τι και πότε". Χωρίς επεξεργασία/διαγραφή:
 * append-only, ίδιο σκεπτικό με το CustomerNoteRepository στο backend.
 */
function notesBlock(notes) {
	var list = (notes && notes.length)
		? '<ul class="ecrm-timeline">' + notes.map(function (n) {
			return '<li><span class="ecrm-timeline__dot"></span><div>' +
				'<div class="ecrm-timeline__txt">' + esc(n.body || '') + '</div>' +
				'<div class="ecrm-timeline__time">' + timeAgo(n.created_at) + '</div>' +
				'<div class="ecrm-timeline__who"><span class="ecrm-cell-mark ecrm-cell-mark--cust" style="--h:' + tint(n.author || '') + '">' + esc(initials(n.author || '')) + '</span><span class="ecrm-timeline__whoname">' + esc(n.author || '—') + '</span></div>' +
				'</div></li>';
		}).join('') + '</ul>'
		: '<div class="ecrm-empty">Καμία σημείωση ακόμη.</div>';

	return list + '<div style="margin-top:10px"><button type="button" class="ecrm-btn ecrm-btn--sm" data-note-new>+ Σημείωση</button></div>';
}

function kpiBlock(kpi) {
	var next = kpi.next_expiry;
	var nextLabel = '—';
	var nextFoot = 'καμία επερχόμενη';
	if (next && next.days_left != null) {
		var left = +next.days_left;
		nextLabel = left < 0 ? 'έληξε' : 'σε ' + left + (left === 1 ? ' ημέρα' : ' ημέρες');
		nextFoot = next.code || '';
	}
	return '<div class="ecrm-kpis ecrm-kpis--4">' +
		'<div class="ecrm-kpi"><div class="ecrm-kpi__k">Συμβάσεις</div><div class="ecrm-kpi__v">' + esc(kpi.contracts_count) + '</div><div class="ecrm-kpi__f"></div></div>' +
		'<div class="ecrm-kpi"><div class="ecrm-kpi__k">Ενεργές</div><div class="ecrm-kpi__v">' + esc(kpi.active_count) + '</div><div class="ecrm-kpi__f"></div></div>' +
		'<div class="ecrm-kpi"><div class="ecrm-kpi__k">Επόμενη λήξη</div><div class="ecrm-kpi__v">' + esc(nextLabel) + '</div><div class="ecrm-kpi__f">' + esc(nextFoot) + '</div></div>' +
		'<div class="ecrm-kpi"><div class="ecrm-kpi__k">Τελευταία κίνηση</div><div class="ecrm-kpi__v">' + (kpi.last_active ? fmtDate(kpi.last_active) : '—') + '</div><div class="ecrm-kpi__f"></div></div>' +
		'</div>';
}

/* 247, Στάδιο 2: το ΜΟΝΟ σήμερα εγγράψιμο πεδίο πελάτη -- εσωτερικής χρήσης,
 * δεν τυπώνεται πουθενά (docs/UI-CUSTOMER-CARD.html). Ιδια σύμβαση με τα
 * kv() γύρω του, με ένα κουμπί επεξεργασίας από κάτω αντί για δεύτερη
 * γλώσσα inline-edit -- η οθόνη ήδη έχει openDialog() για ακριβώς αυτό.
 */
function contactPhoneBlock(phone) {
	return '<div class="ecrm-kv"><b>' + (phone ? esc(phone) : '<span class="ecrm-muted">—</span>') +
		'</b><span>Τηλ. επικοινωνίας <span class="ecrm-badge ecrm-badge--new" style="font-size:9px;padding:0 6px;vertical-align:1px;">ΝΕΟ</span></span></div>' +
		'<p class="ecrm-hint" style="margin:4px 0 8px;">Για δική σου χρήση -- δεν τυπώνεται σε κανένα έντυπο.</p>' +
		'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-phone-edit>Επεξεργασία</button>';
}

function renderCard(view, id, d) {
	var c = d.customer || {};
	var contracts = d.contracts || [];
	var documents = d.documents || [];
	var statuses = d.statuses || {};
	var name = customerName(c);

	view.innerHTML =
		'<div style="margin-bottom:10px">' +
		'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" data-back>&larr; Πελάτες</button>' +
		'</div>' +
		'<header class="ecrm-head ecrm-head--row">' +
		'<div>' +
		'<h2 class="ecrm-title">' +
		'<span class="ecrm-cell-mark ecrm-cell-mark--cust" style="--h:' + tint(name) + '">' + esc(initials(name)) + '</span> ' +
		esc(name) + '</h2>' +
		'<p class="ecrm-sub">' + esc(TYPE_LABEL[c.customer_type] || '') +
		(c.afm ? ' · ΑΦΜ ' + esc(c.afm) : '') +
		(c.created_at ? ' · πελάτης από ' + esc(fmtDate(c.created_at)) : '') + '</p>' +
		'</div>' +
		'</header>' +

		kpiBlock(d.kpi || {}) +

		'<div class="ecrm-pgrid">' +
		'<div class="ecrm-pgrid__side">' +
		'<div class="ecrm-card"><div class="ecrm-step">Ταυτότητα</div>' +
		kv('Όνομα', c.first_name) + kv('Επώνυμο', c.last_name) + kv('Πατρώνυμο', c.father_name) +
		kv('Α.Φ.Μ.', c.afm) + kv('Δ.Ο.Υ.', c.doy) + kv('Α.Δ.Τ.', c.adt) + kv('Ημ. Γέννησης', c.birth_date ? fmtDate(c.birth_date) : '') +
		'</div>' +
		'<div class="ecrm-card"><div class="ecrm-step">Διεύθυνση</div>' +
		kv('Οδός', [c.street, c.street_no].filter(Boolean).join(' ')) + kv('Πόλη', c.city) + kv('Τ.Κ.', c.postal_code) + kv('Περιοχή', c.region) +
		'</div>' +
		'<div class="ecrm-card"><div class="ecrm-step">Επικοινωνία</div>' +
		kv('Τηλέφωνο', c.phone) + kv('Κινητό', c.mobile) + kv('Email', c.email) +
		contactPhoneBlock(c.contact_phone) +
		'</div>' +
		'</div>' +

		'<div class="ecrm-pgrid__main">' +
		'<div class="ecrm-card"><div class="ecrm-step">Συμβάσεις</div>' + contractsTable(contracts, statuses) + '</div>' +
		'<div class="ecrm-card"><div class="ecrm-step">Λήξεις &amp; ανανεώσεις</div>' + expiringRows(contracts) + '</div>' +
		'<div class="ecrm-card"><div class="ecrm-step">Σημειώσεις</div>' + notesBlock(d.notes) + '</div>' +
		'<div class="ecrm-card"><div class="ecrm-step">Έγγραφα όλων των συμβάσεων</div>' + documentsList(documents, d.doc_kinds, contracts) + '</div>' +
		'</div>' +
		'</div>';

	var back = view.querySelector('[data-back]');
	if (back) { back.addEventListener('click', function () { go('customers'); }); }

	view.querySelectorAll('[data-open]').forEach(function (row) {
		row.addEventListener('click', function () { openDetail(this.getAttribute('data-open')); });
	});

	/* 247, Στάδιο 2 -- προσθήκη σημείωσης. Ξαναφτιάχνει ολόκληρη την κάρτα
	 * από το ίδιο `d` που ήδη έχουμε (η απάντηση του POST φέρνει τη νέα λίστα
	 * σημειώσεων έτοιμη με ονόματα συντακτών) αντί να χτίζει τη νέα γραμμή
	 * ξεχωριστά εδώ -- ένα σημείο αλήθειας για το πώς μοιάζει μια σημείωση. */
	var noteBtn = view.querySelector('[data-note-new]');
	if (noteBtn) noteBtn.addEventListener('click', function () {
		openDialog({
			eyebrow: 'Σημείωση',
			title: 'Νέα σημείωση για ' + name,
			body: '<div class="ecrm-modal__card ecrm-modal__stack">' +
				'<label class="ecrm-field ecrm-field--wide"><span class="ecrm-field__label">Κείμενο</span>' +
				'<textarea class="ecrm-textarea" data-note-body rows="3" placeholder="π.χ. καλεί μετά τις 17:00…"></textarea>' +
				'<span class="ecrm-field__err" data-note-err hidden>Η σημείωση δεν μπορεί να είναι κενή.</span></label>' +
				'</div>',
			confirm: 'Προσθήκη',
			onConfirm: function (el, close, btn) {
				var field = el.querySelector('[data-note-body]');
				var err = el.querySelector('[data-note-err]');
				var body = (field.value || '').trim();

				if (!body) {
					field.classList.add('is-err');
					err.hidden = false;
					field.focus();
					return;
				}

				field.classList.remove('is-err');
				err.hidden = true;

				btn.disabled = true;
				fetch(api('/customers/' + id + '/notes'), {
					method: 'POST',
					headers: Object.assign({ 'Content-Type': 'application/json' }, H()),
					body: JSON.stringify({ body: body }),
				})
					.then(function (r) { return r.json(); })
					.then(function (res) {
						if (res && res.ok) {
							close();
							d.notes = res.notes;
							renderCard(view, id, d);
							toast('Προστέθηκε η σημείωση.');
						} else {
							btn.disabled = false;
							toast((res && res.error) || 'Αποτυχία.', false);
						}
					})
					.catch(function () { btn.disabled = false; toast('Σφάλμα δικτύου.', false); });
			},
		});
	});

	/* 247, Στάδιο 2 -- επεξεργασία τηλ. επικοινωνίας. Στέλνει ΜΟΝΟ αυτό το
	 * ένα πεδίο (CustomersController::updateContactPhone() δεν δέχεται
	 * τίποτα άλλο) -- η πλήρης επεξεργασία στοιχείων μένει για το Στάδιο 3. */
	var phoneBtn = view.querySelector('[data-phone-edit]');
	if (phoneBtn) phoneBtn.addEventListener('click', function () {
		openDialog({
			eyebrow: 'Τηλ. επικοινωνίας',
			title: 'Επεξεργασία για ' + name,
			lead: 'Για δική σου χρήση -- δεν τυπώνεται σε κανένα έντυπο.',
			body: '<div class="ecrm-modal__card ecrm-modal__stack">' +
				'<label class="ecrm-field"><span class="ecrm-field__label">Τηλέφωνο</span>' +
				'<input class="ecrm-input" data-phone-value value="' + esc(c.contact_phone || '') + '"></label>' +
				'</div>',
			confirm: 'Αποθήκευση',
			onConfirm: function (el, close, btn) {
				var value = (el.querySelector('[data-phone-value]').value || '').trim();

				btn.disabled = true;
				fetch(api('/customers/' + id + '/contact-phone'), {
					method: 'PATCH',
					headers: Object.assign({ 'Content-Type': 'application/json' }, H()),
					body: JSON.stringify({ contact_phone: value }),
				})
					.then(function (r) { return r.json(); })
					.then(function (res) {
						if (res && res.ok) {
							close();
							c.contact_phone = res.contact_phone;
							renderCard(view, id, d);
							toast('Ενημερώθηκε.');
						} else {
							btn.disabled = false;
							toast((res && res.error) || 'Αποτυχία.', false);
						}
					})
					.catch(function () { btn.disabled = false; toast('Σφάλμα δικτύου.', false); });
			},
		});
	});
}
