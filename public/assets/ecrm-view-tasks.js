/* Energy CRM — tasks and callbacks: what the partner owes somebody today.
 *
 * Caches the team list, because the assignee dropdown is rebuilt on every
 * render and the members do not change between two clicks. */

import { api, esc, fetch, H, toast, viewEl } from '@energy-crm/util';
import { fmtDate } from '@energy-crm/format';
import { openDetail } from '@energy-crm/navigate';

var tasksState = { filter: 'open', scope: 'own' };
var taskTeamCache = null;
export function loadTasks() {
	var view = viewEl('tasks');
	fetch(api('/tasks') + '?scope=' + tasksState.scope + '&filter=' + tasksState.filter, { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (!d || !d.ok) { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα.</div></div>'; return; }
			if (d.can_team && taskTeamCache === null) {
				fetch(api('/team'), { headers: H() }).then(function (r) { return r.json(); })
					.then(function (t) { taskTeamCache = (t && t.members) || (t && t.rows) || []; renderTasks(view, d); })
					.catch(function () { taskTeamCache = []; renderTasks(view, d); });
			} else { renderTasks(view, d); }
		})
		.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα δικτύου.</div></div>'; });
}
/**
 * Ο πελάτης της εργασίας, από τις στήλες που ήδη έρχονται.
 *
 * Το /tasks κάνει LEFT JOIN στον πελάτη και επιστρέφει first_name, last_name
 * και company_name — ποτέ έτοιμο «customer». Το ίδιο σύνθεμα με τη λίστα
 * συμβάσεων, γιατί ο κανόνας (εταιρεία αν υπάρχει, αλλιώς ονοματεπώνυμο)
 * είναι ο ίδιος και πρέπει να διαβάζεται ίδιος.
 *
 * Καμία από τις τρεις στήλες δεν είναι κρυπτογραφημένη — δες
 * CustomerFields::ENCRYPTED, που καλύπτει ΑΦΜ, ΑΔΤ και διεύθυνση. Αν κάποτε
 * μπει το όνομα εκεί μέσα, εδώ θα φανεί ciphertext στην οθόνη.
 */
function taskCustomer(t) {
	return t.company_name || ((t.first_name || '') + ' ' + (t.last_name || '')).trim();
}

/**
 * Το όνομα του αναδόχου, από την ήδη φορτωμένη λίστα ομάδας.
 *
 * Η γραμμή φέρνει assigned_to, δηλαδή id. Το όνομα υπάρχει ήδη στο
 * taskTeamCache που φορτώνεται για το dropdown ανάθεσης, οπότε δεν χρειάζεται
 * ούτε δεύτερη κλήση ούτε νέο πεδίο στο API.
 */
function taskAssignee(t) {
	var id = +(t.assigned_to || 0);

	if (!id || !taskTeamCache) return '';

	for (var i = 0; i < taskTeamCache.length; i++) {
		var m = taskTeamCache[i];
		if (+(m.id || m.ID || m.user_id) === id) return m.name || m.display_name || '';
	}

	return '';
}

/**
 * Ο υπότιτλος: πελάτης · σύμβαση · ανάδοχος.
 *
 * Κάθε κομμάτι περνάει από esc() ΤΗ ΣΤΙΓΜΗ που μπαίνει, όχι στο τέλος — το
 * link είναι ήδη markup και ένα esc() στο τελικό string θα το τύπωνε ως
 * κείμενο. Αυτό είναι ακριβώς το σχήμα που ο FrontendEscapingTest ΔΕΝ βλέπει:
 * μια τοπική μεταβλητή, όχι ιδιότητα αντικειμένου. Το λέει και το δικό του
 * docblock. Όποιος προσθέσει τέταρτο κομμάτι εδώ, το κάνει χωρίς δίχτυ.
 */
function taskSub(t, link, teamWide) {
	var parts = [];
	var customer = taskCustomer(t);

	if (customer) parts.push(esc(customer));
	if (link) parts.push(link);

	var who = teamWide ? taskAssignee(t) : '';

	if (who) parts.push(esc(who));

	return parts.join(' · ');
}

function taskDue(t) {
	if (!t.due_at) return '<span class="ecrm-muted">—</span>';
	var cls = t.overdue ? 'is-overdue' : '';
	return '<span class="ecrm-taskdue ' + cls + '">' + esc(fmtDate(t.due_at)) + (t.overdue ? ' • εκπρόθεσμη' : '') + '</span>';
}
function renderTasks(view, d) {
	var tabs = [['open','Ανοιχτές'],['today','Σήμερα'],['overdue','Εκπρόθεσμες'],['done','Ολοκληρωμένες']];
	var tabsHTML = tabs.map(function (t) {
		return '<button type="button" class="ecrm-tab' + (tasksState.filter === t[0] ? ' is-on' : '') + '" data-tfilter="' + t[0] + '">' + t[1] + '</button>';
	}).join('');

	var scopeToggle = d.can_team
		? '<div class="ecrm-scope"><button type="button" class="ecrm-scope__b' + (tasksState.scope==="own"?" is-on":"") + '" data-tscope="own">Δικά μου</button><button type="button" class="ecrm-scope__b' + (tasksState.scope==="team"?" is-on":"") + '" data-tscope="team">Ομάδας</button></div>'
		: '';

	var assigneeField = '';
	if (d.can_team && taskTeamCache && taskTeamCache.length) {
		var opts = '<option value="">— Ανάθεση σε εμένα —</option>' + taskTeamCache.map(function (m) {
			var idv = m.id || m.ID || m.user_id; var nm = m.name || m.display_name || ('#' + idv);
			return idv ? '<option value="' + idv + '">' + esc(nm) + '</option>' : '';
		}).join('');
		assigneeField = '<select class="ecrm-input" data-task-assignee>' + opts + '</select>';
	}

	var addForm = '<div class="ecrm-card"><div class="ecrm-step">Νέα εργασία</div>' +
		'<div class="ecrm-taskform">' +
		'<input type="text" class="ecrm-input" data-task-title placeholder="Π.χ. Επανάκληση πελάτη για υπογραφή">' +
		'<input type="datetime-local" class="ecrm-input" data-task-due>' +
		'<select class="ecrm-input" data-task-prio><option value="normal">Κανονική</option><option value="high">Υψηλή</option><option value="low">Χαμηλή</option></select>' +
		assigneeField +
		'<button type="button" class="ecrm-btn ecrm-btn--primary" data-task-add>Προσθήκη</button>' +
		'</div></div>';

	var tasks = d.rows || [];
	var rows = tasks.length ? tasks.map(function (t) {
		var prioDot = '<span class="ecrm-prio ecrm-prio--' + esc(t.priority) + '" title="' + esc(t.priority) + '"></span>';
		var link = t.contract_id ? '<a href="#" class="ecrm-tasklink" data-task-open="' + t.contract_id + '">' + esc(t.contract_code || ('#' + t.contract_id)) + '</a>' : '';
		var sub = taskSub(t, link, d.can_team);
		var done = t.status === 'done';
		return '<li class="ecrm-task' + (done ? ' is-done' : '') + (t.overdue ? ' is-overdue' : '') + '">' +
			'<button type="button" class="ecrm-task__check" data-task-toggle="' + t.id + '" data-done="' + (done ? '1' : '0') + '" aria-label="Ολοκλήρωση">' + (done ? '✓' : '') + '</button>' +
			prioDot +
			'<div class="ecrm-task__body"><div class="ecrm-task__title">' + esc(t.title) + '</div>' +
			(sub ? '<div class="ecrm-task__sub">' + sub + '</div>' : '') +
			(t.note ? '<div class="ecrm-task__note">' + esc(t.note) + '</div>' : '') + '</div>' +
			'<div class="ecrm-task__due">' + taskDue(t) + '</div>' +
			'<button type="button" class="ecrm-task__rm" data-task-del="' + t.id + '" aria-label="Διαγραφή">✕</button>' +
			'</li>';
	}).join('') : '<div class="ecrm-empty">Δεν έχεις καμία εργασία αυτή τη στιγμή. Πρόσθεσε μία παρακάτω.</div>';

	view.innerHTML =
		'<header class="ecrm-head ecrm-head--row"><div><div class="ecrm-eyebrow">Παρακολούθηση</div><h2 class="ecrm-title">Εργασίες</h2>' +
		'<p class="ecrm-sub">Υπενθυμίσεις & επανακλήσεις</p></div>' + scopeToggle + '</header>' +
		addForm +
		'<div class="ecrm-card"><div class="ecrm-tabs">' + tabsHTML + '</div><ul class="ecrm-tasklist">' + rows + '</ul></div>';

	// wiring
	view.querySelectorAll('[data-tfilter]').forEach(function (b) { b.addEventListener('click', function () { tasksState.filter = this.getAttribute('data-tfilter'); loadTasks(); }); });
	view.querySelectorAll('[data-tscope]').forEach(function (b) { b.addEventListener('click', function () { tasksState.scope = this.getAttribute('data-tscope'); loadTasks(); }); });
	view.querySelectorAll('[data-task-open]').forEach(function (a) { a.addEventListener('click', function (e) { e.preventDefault(); openDetail(+this.getAttribute('data-task-open')); }); });

	var addBtn = view.querySelector('[data-task-add]');
	if (addBtn) addBtn.addEventListener('click', function () {
		var title = view.querySelector('[data-task-title]').value.trim();
		if (!title) { toast('Συμπλήρωσε τίτλο.', false); return; }
		var body = {
			title: title,
			due_at: view.querySelector('[data-task-due]').value,
			priority: view.querySelector('[data-task-prio]').value
		};
		var asg = view.querySelector('[data-task-assignee]');
		if (asg && asg.value) body.assigned_to = +asg.value;
		var b = this; b.disabled = true;
		fetch(api('/tasks'), { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H()), body: JSON.stringify(body) })
			.then(function (r) { return r.json(); })
			.then(function (res) { if (res && res.ok) { toast('Προστέθηκε εργασία.'); loadTasks(); } else { toast((res && res.error) || 'Αποτυχία.', false); b.disabled = false; } })
			.catch(function () { toast('Σφάλμα δικτύου.', false); b.disabled = false; });
	});

	view.querySelectorAll('[data-task-toggle]').forEach(function (b) {
		b.addEventListener('click', function () {
			var id = this.getAttribute('data-task-toggle');
			var to = this.getAttribute('data-done') === '1' ? 'open' : 'done';
			fetch(api('/tasks/' + id), { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H()), body: JSON.stringify({ status: to }) })
				.then(function (r) { return r.json(); })
				.then(function () { loadTasks(); })
				.catch(function () { toast('Σφάλμα δικτύου.', false); });
		});
	});

	view.querySelectorAll('[data-task-del]').forEach(function (b) {
		b.addEventListener('click', function () {
			if (!confirm('Διαγραφή εργασίας;')) return;
			var id = this.getAttribute('data-task-del');
			fetch(api('/tasks/' + id), { method: 'DELETE', headers: H() })
				.then(function (r) { return r.json(); })
				.then(function () { loadTasks(); })
				.catch(function () { toast('Σφάλμα δικτύου.', false); });
		});
	});
}
