/* Energy CRM — καρτέλα πελάτη: όλα τα στοιχεία ενός πελάτη σε μία οθόνη.
 *
 * Build queue 09, 05-06/09 (247). Και τα τρία στάδια της εγκεκριμένης μακέτας
 * (docs/UI-CUSTOMER-CARD.html, εγκρίθηκε 05/09) είναι πλέον εδώ: Στάδιο 1
 * μόνο ανάγνωση, Στάδιο 2 σημειώσεις + τηλ. επικοινωνίας, Στάδιο 3 πλήρης
 * επεξεργασία στοιχείων (Ταυτότητα/Διεύθυνση/Επικοινωνία, με ιστορικό
 * αλλαγών). Πριν από αυτή την οθόνη, «άνοιξε τον πελάτη» σήμαινε
 * openCustomerContracts() -- τη λίστα «Συμβάσεις» φιλτραρισμένη στο ΑΦΜ του
 * (βλ. σχόλιο εκεί, build queue 08). Αυτό παραμένει, αλλά δεν έλυνε ποτέ το
 * ίδιο πρόβλημα: εδώ ο συνεργάτης βλέπει τον πελάτη, όχι μια λίστα σχεδόν
 * τυχαία φιλτραρισμένη γύρω του.
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

/*
 * Μετά από επιτυχή αποθήκευση (σημείωση/τηλέφωνο/πλήρης επεξεργασία), ο
 * παλιότερος κώδικας ξαναέμπαινε από openCustomerCard(id) -- σωστό για να
 * μην ξαναχτίζει την όψη από κρατημένο, ενδεχομένως stale `d`, αλλά αυτό
 * αδειάζει πρώτα ολόκληρη την όψη σε "Φόρτωση…" πριν ξαναφέρει τα δεδομένα,
 * που ο συνεργάτης το βλέπει σαν πλήρες ανανέωση σελίδας (flash + χάνεται η
 * θέση κύλισης). Εδώ κάνουμε το ίδιο φρέσκο fetch, αλλά ΧΩΡΙΣ το ενδιάμεσο
 * άδειασμα -- η παλιά όψη μένει ορατή μέχρι να είναι έτοιμη η νέα, οπότε η
 * αλλαγή μοιάζει με ενημέρωση, όχι με ανανέωση. Δεν καλεί ξανά go() -- ο
 * συνεργάτης δεν έφυγε ποτέ από την οθόνη, ο router δεν χρειάζεται να το
 * ξαναβεβαιώσει.
 */
function refreshCard(id) {
	var view = viewEl('customer-detail');
	if (!view) { return; }
	fetch(api('/customers/' + id + '/card'), { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (!d || !d.ok) { return; }
			renderCard(view, id, d);
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

/* 247, Στάδιο 3 (docs/UI-CUSTOMER-CARD.html): πλήρης επεξεργασία στοιχείων.
 * Απόφαση ιδιοκτήτη (05/09): όποιος βλέπει τον πελάτη μπορεί να τον
 * επεξεργαστεί, ΚΑΘΕ αλλαγή ορατή σε όλους στο ιστορικό (customer_events),
 * και τίποτα δεν εμποδίζει το save -- ούτε δεύτερο ΑΦΜ που ανήκει ήδη σε
 * άλλον πελάτη (μόνο προειδοποίηση, με δυνατότητα να προχωρήσει), ούτε
 * επίδραση σε ήδη κατατεθειμένες συμβάσεις (μόνο ενημέρωση πλήθους).
 */
var FIELD_LABEL = {
	first_name: 'Όνομα', last_name: 'Επώνυμο', father_name: 'Πατρώνυμο', company_name: 'Επωνυμία',
	afm: 'Α.Φ.Μ.', doy: 'Δ.Ο.Υ.', adt: 'Α.Δ.Τ.', birth_date: 'Ημ. Γέννησης',
	street: 'Οδός', street_no: 'Αριθμός', postal_code: 'Τ.Κ.', city: 'Πόλη', region: 'Περιοχή',
	phone: 'Τηλέφωνο', mobile: 'Κινητό', email: 'Email',
};

function stepHead(title, attr) {
	return '<div class="ecrm-step">' + esc(title) +
		'<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-btn--sm" style="margin-left:auto" data-' + attr + '>Επεξεργασία</button></div>';
}

/* Ενημέρωση, όχι εμπόδιο -- η μακέτα το λέει ρητά: «οι συμβάσεις με ήδη
 * κατατεθειμένο χαρτί δεν αλλάζουν μόνες τους». Το πλήθος έρχεται ήδη
 * φορτωμένο από το card() (d.kpi) -- κανένα δεύτερο αίτημα για αυτό. */
function contractImpactHint(kpi) {
	if (!kpi || !kpi.contracts_count) { return ''; }
	var txt = 'Αφορά ' + kpi.contracts_count + (kpi.contracts_count === 1 ? ' σύμβαση' : ' συμβάσεις');
	if (kpi.active_count) {
		txt += ', ' + kpi.active_count + (kpi.active_count === 1 ? ' ενεργή' : ' ενεργές');
	}
	return '<p class="ecrm-hint">' + esc(txt) + '.</p>';
}

function eventValueText(field, value) {
	if (!value) { return '—'; }
	return field === 'birth_date' ? fmtDate(value) : value;
}

/* Η γραμμή ".audit" της μακέτας -- μόνο η τελευταία αλλαγή (last_event, ήδη
 * μέσα στο card()), με σύνδεσμο για όλο το ιστορικό, ζητημένο ξεχωριστά μόνο
 * όταν ανοίξει (GET /customers/{id}/events) -- ίδιο σκεπτικό με το γιατί το
 * card() δεν στέλνει ήδη ολόκληρο το ιστορικό.
 */
function auditFooter(event) {
	if (!event) { return ''; }
	var label = FIELD_LABEL[event.field] || event.field;
	return '<div class="ecrm-notes">' +
		'Τελευταία αλλαγή στοιχείων: <b>' + esc(event.author || '—') + '</b> · ' + timeAgo(event.created_at) +
		' · άλλαξε <b>' + esc(label) + '</b> από ' + esc(eventValueText(event.field, event.old_value)) +
		' σε ' + esc(eventValueText(event.field, event.new_value)) +
		' &nbsp;·&nbsp; <a href="#" data-history>όλο το ιστορικό</a>' +
		'</div>';
}

/* Ενα generic dialog επεξεργασίας για ένα υποσύνολο πεδίων -- τα τρία μπλοκ
 * (Ταυτότητα/Διεύθυνση/Επικοινωνία) στέλνουν διαφορετική λίστα `fields`,
 * ίδιο PATCH /customers/{id} και ίδιος χειρισμός needs_confirm και για τα
 * τρία (μόνο η Ταυτότητα στέλνει ποτέ 'afm', οπότε μόνο εκεί ενεργοποιείται
 * στην πράξη).
 *
 * @param {Array<[string,string,string?]>} fields [κλειδί, ετικέτα, τύπος πεδίου]
 */
function editFields(id, name, eyebrow, kpi, fields, current) {
	var body = '<div class="ecrm-modal__card ecrm-modal__stack">' +
		fields.map(function (f) {
			var key = f[0];
			var type = f[2] || 'text';
			var val = current[key] || '';
			if (type === 'date' && val) { val = String(val).slice(0, 10); }
			return '<label class="ecrm-field"><span class="ecrm-field__label">' + esc(f[1]) + '</span>' +
				'<input class="ecrm-input" type="' + type + '" data-ef="' + key + '" value="' + esc(val) + '"></label>';
		}).join('') +
		'</div>' + contractImpactHint(kpi);

	function save(payload, confirmDuplicate, close, btn) {
		btn.disabled = true;
		if (confirmDuplicate) { payload = Object.assign({}, payload, { confirm_duplicate: true }); }

		fetch(api('/customers/' + id), {
			method: 'PATCH',
			headers: Object.assign({ 'Content-Type': 'application/json' }, H()),
			body: JSON.stringify(payload),
		})
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (res && res.ok) {
					close();
					toast((res.changed && res.changed.length) ? 'Ενημερώθηκε.' : 'Καμία αλλαγή.');
					// refreshCard(), όχι openCustomerCard() -- βλ. σχόλιό του: ίδιο φρέσκο
					// fetch, χωρίς το ενδιάμεσο άδειασμα σε "Φόρτωση…".
					refreshCard(id);
					return;
				}

				if (res && res.needs_confirm && res.reason === 'afm_duplicate') {
					btn.disabled = false;
					// window.confirm(), ίδιο μοτίβο με το SignLinkController::create()
					// στο ecrm-view-detail.js -- needs_confirm από τον server, «ναι»
					// από τον χρήστη, δεύτερη κλήση με confirm_duplicate: true.
					if (window.confirm(res.error + ' Θέλεις να συνεχίσεις;')) {
						save(payload, true, close, btn);
					}
					return;
				}

				btn.disabled = false;
				toast((res && res.error) || 'Αποτυχία.', false);
			})
			.catch(function () { btn.disabled = false; toast('Σφάλμα δικτύου.', false); });
	}

	openDialog({
		eyebrow: eyebrow,
		title: 'Επεξεργασία για ' + name,
		body: body,
		confirm: 'Αποθήκευση',
		onConfirm: function (el, close, btn) {
			var payload = {};
			fields.forEach(function (f) {
				payload[f[0]] = (el.querySelector('[data-ef="' + f[0] + '"]').value || '').trim();
			});
			save(payload, false, close, btn);
		},
	});
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
		'<div class="ecrm-card">' + stepHead('Ταυτότητα', 'edit-identity') +
		kv('Όνομα', c.first_name) + kv('Επώνυμο', c.last_name) + kv('Πατρώνυμο', c.father_name) +
		(c.customer_type !== 'individual' ? kv('Επωνυμία', c.company_name) : '') +
		kv('Α.Φ.Μ.', c.afm) + kv('Δ.Ο.Υ.', c.doy) + kv('Α.Δ.Τ.', c.adt) + kv('Ημ. Γέννησης', c.birth_date ? fmtDate(c.birth_date) : '') +
		'</div>' +
		'<div class="ecrm-card">' + stepHead('Διεύθυνση', 'edit-address') +
		kv('Οδός', [c.street, c.street_no].filter(Boolean).join(' ')) + kv('Πόλη', c.city) + kv('Τ.Κ.', c.postal_code) + kv('Περιοχή', c.region) +
		'</div>' +
		'<div class="ecrm-card">' + stepHead('Επικοινωνία', 'edit-contact') +
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
		'</div>' +

		auditFooter(d.last_event);

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
							toast('Προστέθηκε η σημείωση.');
							// refreshCard() -- φρέσκο fetch αντί για το ήδη κρατημένο,
							// ενδεχομένως stale `d`, αλλά χωρίς το άδειασμα σε "Φόρτωση…"
							// του openCustomerCard() (βλ. σχόλιό του).
							refreshCard(id);
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
							toast('Ενημερώθηκε.');
							// refreshCard(), ίδιο σκεπτικό με το addNote() παραπάνω.
							refreshCard(id);
						} else {
							btn.disabled = false;
							toast((res && res.error) || 'Αποτυχία.', false);
						}
					})
					.catch(function () { btn.disabled = false; toast('Σφάλμα δικτύου.', false); });
			},
		});
	});

	/* 247, Στάδιο 3 -- τα τρία μπλοκ επεξεργασίας. Ιδιο editFields() και για
	 * τα τρία, μόνο η λίστα πεδίων αλλάζει. */
	var editIdentityBtn = view.querySelector('[data-edit-identity]');
	if (editIdentityBtn) editIdentityBtn.addEventListener('click', function () {
		var fields = [
			['first_name', 'Όνομα'], ['last_name', 'Επώνυμο'], ['father_name', 'Πατρώνυμο'],
		];
		if (c.customer_type !== 'individual') { fields.push(['company_name', 'Επωνυμία']); }
		fields.push(['afm', 'Α.Φ.Μ.'], ['doy', 'Δ.Ο.Υ.'], ['adt', 'Α.Δ.Τ.'], ['birth_date', 'Ημ. Γέννησης', 'date']);
		editFields(id, name, 'Ταυτότητα', d.kpi, fields, c);
	});

	var editAddressBtn = view.querySelector('[data-edit-address]');
	if (editAddressBtn) editAddressBtn.addEventListener('click', function () {
		editFields(id, name, 'Διεύθυνση', d.kpi, [
			['street', 'Οδός'], ['street_no', 'Αριθμός'], ['postal_code', 'Τ.Κ.'],
			['city', 'Πόλη'], ['region', 'Περιοχή'],
		], c);
	});

	var editContactBtn = view.querySelector('[data-edit-contact]');
	if (editContactBtn) editContactBtn.addEventListener('click', function () {
		editFields(id, name, 'Επικοινωνία', d.kpi, [
			['phone', 'Τηλέφωνο'], ['mobile', 'Κινητό'], ['email', 'Email', 'email'],
		], c);
	});

	/* 247, Στάδιο 3 -- «όλο το ιστορικό» της μακέτας. Ζητιέται μόνο εδώ,
	 * ξεχωριστά από το card(), που στέλνει ήδη μόνο το τελευταίο (last_event). */
	var historyLink = view.querySelector('[data-history]');
	if (historyLink) historyLink.addEventListener('click', function (e) {
		e.preventDefault();

		fetch(api('/customers/' + id + '/events'), { headers: H() })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				var events = (res && res.ok) ? res.events : [];
				var list = events.length
					? '<ul class="ecrm-timeline">' + events.map(function (ev) {
						var label = FIELD_LABEL[ev.field] || ev.field;
						return '<li><span class="ecrm-timeline__dot"></span><div>' +
							'<div class="ecrm-timeline__txt">' + esc(label) + ': ' +
							esc(eventValueText(ev.field, ev.old_value)) + ' &rarr; ' +
							esc(eventValueText(ev.field, ev.new_value)) + '</div>' +
							'<div class="ecrm-timeline__time">' + timeAgo(ev.created_at) + '</div>' +
							'<div class="ecrm-timeline__who"><span class="ecrm-timeline__whoname">' + esc(ev.author || '—') + '</span></div>' +
							'</div></li>';
					}).join('') + '</ul>'
					: '<div class="ecrm-empty">Καμία καταγεγραμμένη αλλαγή.</div>';

				openDialog({
					eyebrow: 'Ιστορικό',
					title: 'Αλλαγές στοιχείων -- ' + name,
					body: '<div class="ecrm-modal__card">' + list + '</div>',
					// Καθαρά προβολή -- το data-dlg-confirm κλείνει σαν το data-x,
					// όχι δεύτερη σημασιολογία «αποθήκευση».
					confirm: 'Κλείσιμο',
					onConfirm: function (el, close) { close(); },
				});
			})
			.catch(function () { toast('Σφάλμα δικτύου.', false); });
	});
}
