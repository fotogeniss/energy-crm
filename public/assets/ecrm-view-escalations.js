/* Energy CRM — ομάδα · καθυστερήσεις: αδρανείς συμβάσεις της ομάδας.
 *
 * Ίδια δεδομένα με το «Της ομάδας σου» section του email digest (197) —
 * `GET /team/escalations`, πάνω στο ίδιο `ECRM_Notifications::escalations()`.
 * Ο λόγος που υπάρχει και εδώ, όχι μόνο στο mail: 31/08, ο ιδιοκτήτης —
 * «δεν υπάρχει λόγος να στέλνονται mail και μαλακίες, τα θέλουμε όλα στο
 * CRM». Το email digest ΔΕΝ αγγίζεται από αυτή τη δουλειά, μένει όπως είναι·
 * απλώς η ίδια πληροφορία γίνεται τώρα ορατή και μέσα στην εφαρμογή.
 *
 * Ομαδοποίηση ανά συνεργάτη γίνεται εδώ, στο frontend — ο server επιστρέφει
 * flat λίστα (ίδιο σχήμα με needsAttention()/attention_extra), το group-by
 * είναι παρουσίαση, όχι δεδομένο.
 *
 * Δεν έχει auto-refresh σαν το view-team-live: εκείνο δείχνει «τώρα»
 * (online/offline), αυτό δείχνει αδράνεια μερών ημερών — ένα ξαναφόρτωμα σε
 * κάθε άνοιγμα της οθόνης είναι αρκετό.
 */

import { api, esc, fetch, H, viewEl } from '@energy-crm/util';
import { openDetail } from '@energy-crm/navigate';

export function loadEscalations() {
	var view = viewEl('escalations');
	view.innerHTML = '<div class="ecrm-card"><div class="ecrm-loading">Φόρτωση…</div></div>';

	fetch(api('/team/escalations'), { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (!d || !d.ok) { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">' + esc((d && d.error) || 'Σφάλμα.') + '</div></div>'; return; }
			renderEscalations(view, d);
		})
		.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα δικτύου.</div></div>'; });
}

/* Ομαδοποίηση με διατήρηση σειράς εμφάνισης: το πρώτο όνομα που συναντάμε
 * ανοίγει την ομάδα του, ώστε η σειρά της οθόνης να ακολουθεί τη σειρά που
 * ήδη έστειλε ο server (πιο αδρανείς πρώτα — ίδιο ORDER BY με το digest). */
function groupByOwner(rows) {
	var order = [], byOwner = {};
	(rows || []).forEach(function (r) {
		var key = r.owner_name || '—';
		if (!byOwner[key]) { byOwner[key] = []; order.push(key); }
		byOwner[key].push(r);
	});
	return order.map(function (name) { return { name: name, rows: byOwner[name] }; });
}

function renderEscalations(view, d) {
	var groups = groupByOwner(d.rows);

	if (!groups.length) {
		view.innerHTML = '<header class="ecrm-head"><div class="ecrm-eyebrow">Ομάδα</div><h2 class="ecrm-title">Καθυστερήσεις</h2>' +
			'<p class="ecrm-sub">Συμβάσεις της ομάδας σου χωρίς κίνηση πάνω από ' + (Number(d.days) || 0) + ' ημέρες.</p></header>' +
			'<div class="ecrm-card"><div class="ecrm-empty">Καμία σύμβαση της ομάδας δεν είναι αδρανής αυτή τη στιγμή.</div></div>';
		return;
	}

	var body = groups.map(function (g) {
		var rows = g.rows.map(function (r) {
			var age = Number(r.age_days) || 0;
			return '<div class="ecrm-mgrrow" data-mgr-go="' + esc(r.id) + '">' +
				'<span class="ecrm-mgrrow__badge"><span class="ecrm-badge ecrm-badge--' + esc(r.status) + '">' + esc(r.status_label || r.status) + '</span></span>' +
				'<span class="ecrm-mgrrow__m"><b>' + esc(r.customer || '—') + '</b><small>#' + esc(r.code || '') + '</small></span>' +
				'<span class="ecrm-mgrrow__age' + (age >= 14 ? ' is-critical' : '') + '">' + age + (age === 1 ? ' ημέρα' : ' ημέρες') + '</span>' +
				'</div>';
		}).join('');

		return '<div class="ecrm-mgrgroup"><div class="ecrm-mgrgroup__head">' +
			'<span class="ecrm-mgrgroup__name">' + esc(g.name) + '</span>' +
			'<span class="ecrm-mgrgroup__n">' + g.rows.length + (g.rows.length === 1 ? ' σύμβαση' : ' συμβάσεις') + '</span>' +
			'</div>' + rows + '</div>';
	}).join('');

	view.innerHTML = '<header class="ecrm-head"><div class="ecrm-eyebrow">Ομάδα</div><h2 class="ecrm-title">Καθυστερήσεις</h2>' +
		'<p class="ecrm-sub">Συμβάσεις της ομάδας σου χωρίς κίνηση πάνω από ' + (Number(d.days) || 0) + ' ημέρες — ίδια λίστα με το email digest.</p></header>' +
		'<div class="ecrm-card">' + body + '</div>';

	// Ίδιο μοτίβο με το attentionHTML() του dashboard: το κλικ ανοίγει την
	// ίδια τη σύμβαση, μέσω openDetail() και όχι με δικό του route.
	view.querySelectorAll('[data-mgr-go]').forEach(function (row) {
		row.addEventListener('click', function () {
			openDetail(row.getAttribute('data-mgr-go'));
		});
	});
}
