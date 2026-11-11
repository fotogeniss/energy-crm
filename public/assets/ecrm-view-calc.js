/* Energy CRM — quote calculator: current cost against the offer.
 *
 * (328) Πάνω από τον χειροκίνητο υπολογισμό: η πρόταση από λογαριασμό. Ο
 * πωλητής ανεβάζει τον λογαριασμό, το AI διαβάζει κατανάλωση και τιμές
 * (POST /bill/read), και ο server κατατάσσει όλα τα προγράμματα με τιμή
 * (GET /bill/compare) -- οι κανόνες κλιμακίων και πακέτων ζουν σε ένα σημείο,
 * το Domain\Pricing\ProgramPricing. Ο λογαριασμός μένει στη μνήμη της
 * σελίδας: ανεβαίνει στον server μόνο αν πατηθεί «Νέα αίτηση με αυτό», μαζί
 * με την αίτηση. Mockup: docs/UI-BILL-ADVISOR.html.
 *
 * (360) Νέα εμφάνιση «Signature»: «Ο λογαριασμός και η κούρσα», mockup
 * docs/UI-QUOTE-FRESH.html, κατεύθυνση Α (ο ιδιοκτήτης διάλεξε πρώτα τη Β
 * και μετά την άλλαξε, 11/11). Αριστερά ο λογαριασμός με τα νούμερα που
 * διάβασε το AI· δεξιά «σήμερα → με το καλύτερο», το κέρδος, και όλα τα
 * προγράμματα ως μπάρες ετήσιου κόστους με γραμμή στο σημερινό. Ίδια
 * κατάταξη, ίδια «Νέα αίτηση με αυτό» και PDF (data-bill-apply,
 * data-bill-pdf)· η αναλυτική λίστα μένει στις «Λεπτομέρειες», ο
 * χειροκίνητος υπολογισμός σε ένα «Χωρίς λογαριασμό;».
 */

import { api, esc, fetch, H, providerMark, toast, viewEl } from '@energy-crm/util';
import { openEdit } from '@energy-crm/navigate';

var calcData = null;
export function loadCalc() {
	var view = viewEl('calc');
	if (calcData) { renderCalc(view); return; }
	fetch(api('/providers'), { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) { calcData = d || { providers: [], programs: [] }; renderCalc(view); })
		.catch(function () { view.innerHTML = '<div class="ecrm-card"><div class="ecrm-empty">Σφάλμα φόρτωσης.</div></div>'; });
}
function renderCalc(view) {
	var provs = (calcData && calcData.providers) || [];
	var provOpts = '<option value="">— Επιλογή —</option>' + provs.map(function (p) { return '<option value="' + p.id + '">' + esc(p.name) + '</option>'; }).join('');

	var sig = isSignature();
	view.innerHTML = (sig
		? '<header class="sig-cuhead"><div><h2>Προσφορά</h2><p>Πόσα κερδίζει ο πελάτης, από τον λογαριασμό του</p></div></header>' +
			advisorMarkup() + '<details class="sig-qmanual"><summary><span><b>Χωρίς λογαριασμό;</b> Βάλε κατανάλωση και τιμή με το χέρι.</span><i>Χειροκίνητα</i></summary>'
		: '<header class="ecrm-head"><div class="ecrm-eyebrow">Εργαλείο πώλησης</div><h2 class="ecrm-title">Υπολογισμός Προσφοράς</h2>' +
			'<p class="ecrm-sub">Εκτίμηση ετήσιου κόστους & οφέλους έναντι του τρέχοντος παρόχου</p></header>' +
			advisorMarkup()) +
		'<div class="ecrm-card"><div class="ecrm-calc">' +
			'<div class="ecrm-calc__h">Χειροκίνητος υπολογισμός · ένα πρόγραμμα</div>' +
			'<div class="ecrm-calc__grid">' +
				'<label class="ecrm-field"><span>Ετήσια κατανάλωση (kWh)</span><input type="number" class="ecrm-input" data-c="consumption" placeholder="π.χ. 3500"></label>' +
				'<label class="ecrm-field"><span>Όνομα πελάτη (προαιρετικό)</span><input type="text" class="ecrm-input" data-c="customer"></label>' +
			'</div>' +
			'<div class="ecrm-calc__cols">' +
				'<div class="ecrm-calc__box"><div class="ecrm-calc__h">Τρέχων πάροχος</div>' +
					'<label class="ecrm-field"><span>Τιμή €/kWh</span><input type="number" step="0.00001" class="ecrm-input" data-c="current_price" placeholder="π.χ. 0.18"></label>' +
					'<label class="ecrm-field"><span>Πάγιο €/μήνα</span><input type="number" step="0.01" class="ecrm-input" data-c="current_fixed" placeholder="π.χ. 5"></label>' +
				'</div>' +
				'<div class="ecrm-calc__box ecrm-calc__box--offer"><div class="ecrm-calc__h">Πρόταση</div>' +
					'<label class="ecrm-field"><span>Πάροχος</span><select class="ecrm-input" data-c="provider">' + provOpts + '</select></label>' +
					'<label class="ecrm-field"><span>Πρόγραμμα</span><select class="ecrm-input" data-c="program"><option value="">—</option></select></label>' +
					'<label class="ecrm-field"><span>Τιμή €/kWh</span><input type="number" step="0.00001" class="ecrm-input" data-c="offered_price" placeholder="0.149"></label>' +
					'<label class="ecrm-field"><span>Πάγιο €/μήνα</span><input type="number" step="0.01" class="ecrm-input" data-c="offered_fixed" placeholder="5"></label>' +
				'</div>' +
			'</div>' +
			'<div class="ecrm-calc__result" data-calc-result></div>' +
			'<div class="ecrm-calc__actions"><button type="button" class="ecrm-btn ecrm-btn--primary" data-calc-pdf><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4M9 13h6M9 17h4"/></svg> PDF Προσφοράς</button></div>' +
			'<p class="ecrm-hint">Η εκτίμηση αφορά χρέωση ενέργειας + πάγιο. Ρυθμιζόμενες χρεώσεις, φόροι και δημοτικά τέλη είναι κοινά μεταξύ παρόχων και δεν υπολογίζονται.</p>' +
		'</div></div>' + (sig ? '</details>' : '');

	wireAdvisor(view);

	var get = function (k) { var el = view.querySelector('[data-c="' + k + '"]'); return el ? el.value : ''; };
	var num = function (k) { return parseFloat(get(k)) || 0; };

	function recompute() {
		var cons = num('consumption');
		var ca = cons * num('current_price') + 12 * num('current_fixed');
		var oa = cons * num('offered_price') + 12 * num('offered_fixed');
		var box = view.querySelector('[data-calc-result]');
		if (!cons || (!num('current_price') && !num('offered_price'))) { box.innerHTML = ''; return; }
		var save = ca - oa, pct = ca > 0 ? (100 * save / ca) : 0, pos = save >= 0;
		box.innerHTML =
			'<div class="ecrm-calc__cmp"><div><span>Τρέχον / έτος</span><strong>' + ca.toFixed(2) + ' €</strong></div>' +
			'<div><span>Πρόταση / έτος</span><strong>' + oa.toFixed(2) + ' €</strong></div></div>' +
			'<div class="ecrm-calc__save ' + (pos ? 'is-pos' : 'is-neg') + '">' +
			'<span>' + (pos ? 'Ετήσια εξοικονόμηση' : 'Ετήσια διαφορά') + '</span>' +
			'<strong>' + Math.abs(save).toFixed(2) + ' € <small>(' + pct.toFixed(1) + '%)</small></strong></div>';
	}

	// programs filtered by provider; auto-fill price on program pick
	view.querySelector('[data-c="provider"]').addEventListener('change', function () {
		var pid = +this.value;
		var sel = view.querySelector('[data-c="program"]');
		var progs = ((calcData && calcData.programs) || []).filter(function (g) { return +g.provider_id === pid; });
		sel.innerHTML = '<option value="">—</option>' + progs.map(function (g) { return '<option value="' + g.id + '">' + esc(g.name) + '</option>'; }).join('');
	});
	view.querySelector('[data-c="program"]').addEventListener('change', function () {
		var gid = +this.value;
		var g = ((calcData && calcData.programs) || []).filter(function (x) { return +x.id === gid; })[0];
		if (g) {
			if (g.price_kwh != null && g.price_kwh !== '') view.querySelector('[data-c="offered_price"]').value = g.price_kwh;
			if (g.fixed_charge != null && g.fixed_charge !== '') view.querySelector('[data-c="offered_fixed"]').value = g.fixed_charge;
			recompute();
		}
	});

	view.querySelectorAll('[data-c]').forEach(function (el) { el.addEventListener('input', recompute); });

	view.querySelector('[data-calc-pdf]').addEventListener('click', function () {
		if (!num('consumption')) { toast('Συμπλήρωσε κατανάλωση.', false); return; }
		var provSel = view.querySelector('[data-c="provider"]'), progSel = view.querySelector('[data-c="program"]');
		var body = {
			consumption: num('consumption'),
			current_price: num('current_price'), current_fixed: num('current_fixed'),
			offered_price: num('offered_price'), offered_fixed: num('offered_fixed'),
			customer_name: get('customer'),
			provider_name: provSel.options[provSel.selectedIndex] ? provSel.options[provSel.selectedIndex].text : '',
			program_name: progSel.options[progSel.selectedIndex] ? progSel.options[progSel.selectedIndex].text : ''
		};
		var b = this; b.disabled = true; var t = b.textContent; b.textContent = 'Δημιουργία…';
		fetch(api('/quote/pdf'), { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H()), body: JSON.stringify(body) })
			.then(function (r) { return r.json(); })
			.then(function (d) {
				if (!d || !d.ok) { toast((d && d.error) || 'Αποτυχία.', false); return; }
				var bin = atob(d.b64), len = bin.length, arr = new Uint8Array(len);
				for (var i = 0; i < len; i++) arr[i] = bin.charCodeAt(i);
				var url = URL.createObjectURL(new Blob([arr], { type: d.mime || 'application/pdf' }));
				var a = document.createElement('a'); a.href = url; a.download = d.filename || 'prosfora.pdf'; document.body.appendChild(a); a.click(); a.remove();
				setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
			})
			.catch(function () { toast('Σφάλμα δικτύου.', false); })
			.finally(function () { b.disabled = false; b.textContent = t; });
	});
}

// ---------------------------------------------------------------------
// (328) Πρόταση από λογαριασμό
// ---------------------------------------------------------------------

var ICON_DOC = '<svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4M9 13h6M9 17h4"/></svg>';
var ICON_NEW = '<svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>';
var ICON_CAM = '<svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5"/></svg>';

// Ο λογαριασμός και ό,τι διάβασε το AI για τον πελάτη. Μόνο στη μνήμη της
// σελίδας -- φεύγουν με την ανανέωση ή με νέο λογαριασμό.
var advisor = { file: null, customer: null, result: null, timer: null };

function money(v) {
	return Math.round(v).toLocaleString('el-GR') + ' €';
}

function num4(v) {
	return (+v).toLocaleString('el-GR', { minimumFractionDigits: 4, maximumFractionDigits: 4 });
}

function advisorMarkup() {
	return '<div class="ecrm-card ecrm-bill">' +
		'<div class="ecrm-bill__step">1 · Ο λογαριασμός</div>' +
		'<div class="ecrm-bill__drop" data-bill-drop>' +
			'<b>Φωτογραφία ή PDF του λογαριασμού ρεύματος / αερίου</b>' +
			'<span>Το AI διαβάζει κατανάλωση, περίοδο, τιμή προμήθειας και πάγιο</span>' +
			'<div class="ecrm-bill__picks">' +
				'<button type="button" class="ecrm-btn" data-bill-cam>' + ICON_CAM + ' Φωτογραφία</button>' +
				'<button type="button" class="ecrm-btn" data-bill-pick>' + ICON_DOC + ' Επιλογή αρχείου</button>' +
			'</div>' +
			'<input type="file" accept="image/*" capture="environment" data-bill-camera hidden>' +
			'<input type="file" accept="application/pdf,image/jpeg,image/png" data-bill-file hidden>' +
			'<label class="ecrm-bill__consent"><input type="checkbox" data-bill-consent> ' +
				'<span>Ο πελάτης ενημερώθηκε ότι ο λογαριασμός του διαβάζεται από υπηρεσία τεχνητής νοημοσύνης για τον υπολογισμό της προσφοράς.</span></label>' +
			'<div class="ecrm-bill__status" data-bill-status></div>' +
		'</div>' +
		'<div data-bill-read hidden>' +
			'<div class="ecrm-bill__step">Διαβάστηκε από τον λογαριασμό · διόρθωσε αν χρειάζεται</div>' +
			'<div class="ecrm-bill__read">' +
				readField('Πάροχος σήμερα', '<input type="text" class="ecrm-input" data-b="provider_name">') +
				readField('Ενέργεια', '<select class="ecrm-input" data-b="energy_type"><option value="power">Ρεύμα</option><option value="gas">Φυσικό αέριο</option></select>') +
				readField('Κατηγορία', '<select class="ecrm-input" data-b="category"><option value="home">Οικιακό</option><option value="business">Επαγγελματικό</option></select>') +
				readField('Περίοδος (ημέρες)', '<input type="number" min="1" class="ecrm-input" data-b="period_days">') +
				readField('Κατανάλωση περιόδου (kWh)', '<input type="number" min="0" class="ecrm-input" data-b="kwh">') +
				readField('Ανά έτος (kWh)', '<input type="number" min="0" class="ecrm-input" data-b="annual_kwh">') +
				readField('Τιμή προμήθειας €/kWh', '<input type="number" step="0.0001" min="0" class="ecrm-input" data-b="price_kwh">') +
				readField('Πάγιο €/μήνα', '<input type="number" step="0.01" min="0" class="ecrm-input" data-b="fixed_month">') +
			'</div>' +
		'</div>' +
		'<div data-bill-rank hidden></div>' +
	'</div>';
}

function readField(label, input) {
	return '<label class="ecrm-field"><span>' + esc(label) + '</span>' + input + '</label>';
}

function wireAdvisor(view) {
	var q = function (sel) { return view.querySelector(sel); };
	var fileIn = q('[data-bill-file]'), camIn = q('[data-bill-camera]');

	q('[data-bill-pick]').addEventListener('click', function () { fileIn.click(); });
	q('[data-bill-cam]').addEventListener('click', function () { camIn.click(); });
	[fileIn, camIn].forEach(function (inp) {
		inp.addEventListener('change', function () {
			if (inp.files && inp.files[0]) { readBill(view, inp.files[0]); }
			inp.value = '';
		});
	});

	var drop = q('[data-bill-drop]');
	drop.addEventListener('dragover', function (e) { e.preventDefault(); drop.classList.add('is-over'); });
	drop.addEventListener('dragleave', function () { drop.classList.remove('is-over'); });
	drop.addEventListener('drop', function (e) {
		e.preventDefault(); drop.classList.remove('is-over');
		if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]) { readBill(view, e.dataTransfer.files[0]); }
	});

	view.querySelectorAll('[data-b]').forEach(function (el) {
		el.addEventListener('input', function () {
			var k = el.getAttribute('data-b');
			// Η εκτίμηση έτους ακολουθεί την περίοδο, εκτός αν τη γράψει ο ίδιος.
			if (k === 'kwh' || k === 'period_days') {
				var kwh = +bval(view, 'kwh'), days = +bval(view, 'period_days');
				if (kwh > 0 && days > 0) { q('[data-b="annual_kwh"]').value = Math.round(kwh * 365 / days); }
			}
			scheduleCompare(view);
		});
		el.addEventListener('change', function () { scheduleCompare(view); });
	});

	// Επιστροφή στην οθόνη: ό,τι είχε διαβαστεί μένει.
	if (advisor.result) { fillRead(view, advisor.result); compare(view); }
}

function bval(view, k) {
	var el = view.querySelector('[data-b="' + k + '"]');
	return el ? el.value : '';
}

function status(view, text, bad) {
	var el = view.querySelector('[data-bill-status]');
	el.textContent = text || '';
	el.className = 'ecrm-bill__status' + (bad ? ' is-bad' : '');
}

function readBill(view, file) {
	if (!view.querySelector('[data-bill-consent]').checked) {
		status(view, 'Τσέκαρε πρώτα ότι ο πελάτης ενημερώθηκε.', true);
		return;
	}
	if (!/\.(pdf|jpe?g|png)$/i.test(file.name) && ['application/pdf', 'image/jpeg', 'image/png'].indexOf(file.type) < 0) {
		status(view, 'Μόνο PDF, JPG ή PNG.', true);
		return;
	}

	advisor.file = file; advisor.customer = null; advisor.result = null; advisor.pick = null;
	status(view, 'Διαβάζω τον λογαριασμό…');

	var fd = new FormData();
	fd.append('file', file);
	fd.append('consent', '1');

	fetch(api('/bill/read'), { method: 'POST', headers: H(), body: fd })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (!d || !d.ok) { status(view, (d && d.error) || 'Ο λογαριασμός δεν διαβάστηκε.', true); return; }
			advisor.customer = d.customer || {};
			advisor.result = Object.assign({}, d.bill || {}, { annual_kwh: d.annual_kwh });
			status(view, 'Διαβάστηκε: ' + file.name);
			fillRead(view, advisor.result);
			compare(view);
		})
		.catch(function () { status(view, 'Σφάλμα δικτύου.', true); });
}

function fillRead(view, b) {
	view.querySelector('[data-bill-read]').hidden = false;
	// (360) Ο λογαριασμός διαβάστηκε: στη νέα εμφάνιση η ζώνη μαζεύει σε γραμμή.
	var card = view.querySelector('.ecrm-bill');
	if (card) { card.classList.add('has-read'); }
	['provider_name', 'period_days', 'kwh', 'annual_kwh', 'price_kwh', 'fixed_month'].forEach(function (k) {
		var el = view.querySelector('[data-b="' + k + '"]');
		if (el) { el.value = b[k] != null ? b[k] : ''; el.classList.toggle('is-missing', b[k] == null); }
	});
	view.querySelector('[data-b="energy_type"]').value = b.energy_type || 'power';
	view.querySelector('[data-b="category"]').value = b.category || 'home';
	if (isSignature()) { sigBillSheet(view); }
}

function scheduleCompare(view) {
	clearTimeout(advisor.timer);
	advisor.timer = setTimeout(function () { compare(view); }, 350);
}

function compare(view) {
	var box = view.querySelector('[data-bill-rank]');
	advisor.pick = null; // νέα κατάταξη, η κάρτα ξεκινά από το καλύτερο
	var kwh = +bval(view, 'annual_kwh');
	if (!(kwh > 0)) { box.hidden = false; box.innerHTML = '<div class="ecrm-bill__miss">Συμπλήρωσε κατανάλωση για να γίνει η σύγκριση.</div>'; return; }

	var qs = '?kwh=' + encodeURIComponent(kwh) +
		'&energy=' + encodeURIComponent(bval(view, 'energy_type')) +
		'&category=' + encodeURIComponent(bval(view, 'category')) +
		'&price_kwh=' + encodeURIComponent(+bval(view, 'price_kwh') || 0) +
		'&fixed_month=' + encodeURIComponent(+bval(view, 'fixed_month') || 0);

	fetch(api('/bill/compare') + qs, { headers: H() })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (!d || !d.ok) { box.hidden = false; box.innerHTML = '<div class="ecrm-bill__miss">Η σύγκριση απέτυχε.</div>'; return; }
			renderRank(view, d);
		})
		.catch(function () { box.hidden = false; box.innerHTML = '<div class="ecrm-bill__miss">Σφάλμα δικτύου.</div>'; });
}

// «Protergia · Protergia Picasso» όχι: τα ονόματα του καταλόγου έχουν ήδη τον πάροχο.
function withProvider(provider, name) {
	if (!provider || String(name).toLowerCase().indexOf(String(provider).toLowerCase()) === 0) { return name; }
	return provider + ' · ' + name;
}

// (332) Picasso: ποιο μέγεθος ταιριάζει. Το κόστος του χρόνου είναι ίδιο σε
// όλα (εκκαθάριση με Πακέτο Αναφοράς)· αλλάζει η μηνιαία δόση.
function fitBlock(r, kwh) {
	var f = r.fit;
	if (!f) { return ''; }
	var bars = (f.sizes || []).map(function (sz) {
		return '<span class="ecrm-bill__size' + (sz.code === f.reference_code ? ' is-on' : '') + '">' + esc(sz.label) + '</span>';
	}).join('');
	var head = 'Για <b>' + Math.round(kwh).toLocaleString('el-GR') + ' kWh τον χρόνο</b> ταιριάζει το <b>' + esc(f.reference_label) + ' €</b>.';
	if (!f.reference_available) {
		head += ' Δεν έχει ακόμα έντυπο· η πλησιέστερη διαθέσιμη επιλογή είναι το ' + esc(f.chosen_label) + ' €, και η διαφορά θα έρχεται στην εκκαθάριση (' +
			(f.settlement >= 0 ? '+' : '−') + money(Math.abs(f.settlement)) + '/έτος).';
	}
	return '<div class="ecrm-bill__fit">' + head + '<div class="ecrm-bill__sizes">' + bars + '</div>' +
		'<span>Με μικρότερο πακέτο πληρώνει λιγότερο τον μήνα και η διαφορά έρχεται στην εκκαθάριση· με μεγαλύτερο, του επιστρέφεται. Το σύνολο του χρόνου είναι ίδιο.</span></div>';
}

var TYPE_LABEL = { fixed: 'Σταθερό', variable: 'Κυμαινόμενο', dynamic: 'Δυναμικό', special: 'Ειδικό' };

function ruleText(r) {
	var rule = r.rule || {};
	var dt = r.price_updated ? r.price_updated.split('-').reverse().join('/') : '';
	var parts = [];
	if (rule.kind === 'tiered') {
		parts.push((rule.tiers || []).map(function (t) {
			return (t[0] == null ? 'μετά ' : 'έως ' + t[0] + ' kWh ') + num4(t[1]);
		}).join(' · ') + ' €/kWh ανά 30 ημ.');
		parts.push('πάγιο ' + (+rule.fixed).toFixed(2).replace('.', ',') + ' €');
	} else if (rule.kind === 'package') {
		parts.push('πακέτο ' + (+rule.package_kwh).toLocaleString('el-GR') + ' kWh/έτος · προμήθεια ' + (+rule.supply_month).toFixed(2).replace('.', ',') + ' €/μήνα');
		parts.push('πάνω από το πακέτο ' + num4(rule.excess_kwh) + ' €/kWh');
	} else {
		parts.push(num4(rule.price_kwh) + ' €/kWh');
		parts.push('πάγιο ' + (+rule.fixed).toFixed(2).replace('.', ',') + ' €');
	}
	if (rule.note) { parts.push(rule.note); }
	if (dt) { parts.push('τιμή ' + dt); }
	return parts.join(' · ');
}

function renderRank(view, d) {
	var box = view.querySelector('[data-bill-rank]');
	box.hidden = false;
	advisor.ranked = d.ranked || [];

	var head = '<div class="ecrm-bill__step">2 · Τι συμφέρει</div>' +
		'<div class="ecrm-bill__cur"><span>Σύγκριση για <b>' + Math.round(d.annual_kwh).toLocaleString('el-GR') + ' kWh/έτος</b> · μόνο ενέργεια + πάγιο</span>' +
		(d.current_annual != null ? '<span>Σήμερα: <b>' + money(d.current_annual) + '/έτος</b></span>' : '<span>Χωρίς τιμή σήμερα · μόνο κόστος</span>') + '</div>';

	var rows = (d.ranked || []).map(function (r, i) {
		var save = r.saving;
		var sv = save == null
			? '<b>' + money(r.annual) + '</b><span>/έτος</span>'
			: '<b class="' + (save >= 0 ? '' : 'is-neg') + '">' + (save >= 0 ? '−' : '+') + money(Math.abs(save)) + '/έτος</b>' +
				'<span>' + money(r.annual) + '/έτος · ' + (save >= 0 ? '−' : '+') + Math.abs(r.saving_pct).toLocaleString('el-GR') + '%</span>';
		var kind = r.rule && r.rule.kind === 'package' ? 'Πακέτο' : (r.rule && r.rule.kind === 'tiered' ? 'Κλιμακωτό' : (TYPE_LABEL[r.price_type] || ''));
		return '<div class="ecrm-bill__rank' + (i === 0 ? ' is-best' : '') + '">' +
			'<div class="ecrm-bill__n">' + (i + 1) + '</div>' +
			'<div><div class="ecrm-bill__nm">' + esc(withProvider(r.provider, r.name)) +
				(kind ? ' <span class="ecrm-bill__pill' + (r.price_type === 'fixed' ? ' is-fx' : ' is-vr') + '">' + esc(kind) + '</span>' : '') +
				(r.stale ? ' <span class="ecrm-bill__pill is-old">παλιά τιμή — έλεγξέ τη</span>' : '') + '</div>' +
				'<div class="ecrm-bill__mt">' + esc(ruleText(r)) + '</div></div>' +
			'<div class="ecrm-bill__sv">' + sv + '</div>' +
			fitBlock(r, d.annual_kwh) +
			'<div class="ecrm-bill__acts">' +
				'<button type="button" class="ecrm-btn ecrm-btn--primary" data-bill-apply="' + i + '">' + ICON_NEW + ' Νέα αίτηση με αυτό</button>' +
				'<button type="button" class="ecrm-btn" data-bill-pdf="' + i + '">' + ICON_DOC + ' PDF προσφοράς</button>' +
			'</div></div>';
	}).join('');

	if (!rows) { rows = '<div class="ecrm-bill__miss">Κανένα ενεργό πρόγραμμα αυτής της κατηγορίας δεν έχει καταχωρημένη τιμή.</div>'; }

	var un = d.unpriced || [];
	var miss = un.length
		? '<details class="ecrm-bill__miss"><summary>' + un.length + (un.length === 1 ? ' πρόγραμμα' : ' προγράμματα') + ' χωρίς καταχωρημένη τιμή δεν συγκρίνονται. Ποια είναι →</summary><ul>' +
			un.map(function (u) { return '<li>' + esc(withProvider(u.provider, u.name)) + '</li>'; }).join('') +
			'</ul><p>Η τιμή τους μπαίνει στη σελίδα «Πάροχοι & Προγράμματα».</p></details>'
		: '';

	var hint = '<p class="ecrm-hint">Ρυθμιζόμενες χρεώσεις, ΥΚΩ, φόροι και δημοτικά είναι ίδια σε όλους τους παρόχους και δεν υπολογίζονται. Κλιμακωτά και πακέτα υπολογίζονται με τον κανόνα τους· στα πακέτα δεν υπολογίζεται επιστροφή για κατανάλωση κάτω από το πακέτο.</p>';
	if (isSignature()) {
		box.innerHTML = sigQuoteHTML(view, d) +
			'<details class="sig-qdetails"><summary><span><b>Θες τα νούμερα αναλυτικά;</b> Τιμές ανά kWh, κλιμάκια, πακέτα, όλα τα προγράμματα.</span><i>Λεπτομέρειες</i></summary>' +
			head + rows + miss + hint + '</details>';
		wireSigQuote(view, d);
	} else {
		box.innerHTML = head + rows + miss + hint;
	}

	box.querySelectorAll('[data-bill-apply]').forEach(function (b) {
		b.addEventListener('click', function () { applyProgram(view, advisor.ranked[+b.getAttribute('data-bill-apply')]); });
	});
	box.querySelectorAll('[data-bill-pdf]').forEach(function (b) {
		b.addEventListener('click', function () { quotePdf(view, advisor.ranked[+b.getAttribute('data-bill-pdf')], b, d); });
	});
}

function d0kwh(view) {
	return +bval(view, 'annual_kwh') || 0;
}

function applyProgram(view, r) {
	if (!r) { return; }
	var cust = advisor.customer || {};
	var c = {};
	Object.keys(cust).forEach(function (k) { if (cust[k] != null && cust[k] !== '') { c[k] = cust[k]; } });
	c.provider_id = r.provider_id;
	c.program_id = r.id;
	c.energy_type = bval(view, 'energy_type');
	c.category = bval(view, 'category');
	if (r.price_type) { c.price_type = r.price_type; }
	if (!c.customer_type) { c.customer_type = 'individual'; }

	var file = advisor.file;
	openEdit(c);
	// Η φόρμα ανοίγει σε επόμενο tick (openEdit → setTimeout 30)· ο
	// λογαριασμός μπαίνει στα έγγραφά της μετά από αυτό.
	setTimeout(function () {
		if (!window.ECRMForm) { return; }
		if (file && window.ECRMForm.attach) { window.ECRMForm.attach([file], 'provider_bill'); }
		// (331) Η εξοικονόμηση πάει στο «Τι λέω στον πελάτη» της φόρμας.
		if (window.ECRMForm.advice) { window.ECRMForm.advice({ program_id: r.id, saving: r.saving, kwh: d0kwh(view) }); }
	}, 80);
}

function quotePdf(view, r, btn, d) {
	if (!r) { return; }
	var rule = r.rule || {};
	var kwh = d.annual_kwh;
	// Το PDF δείχνει τιμή και πάγιο: για κλιμάκια/πακέτα η ισοδύναμη μέση
	// τιμή που δίνει το ίδιο ετήσιο κόστος.
	var fixed = rule.kind === 'flat' ? +rule.fixed : (rule.kind === 'tiered' ? +rule.fixed : 0);
	var price = rule.kind === 'flat' ? +rule.price_kwh : (kwh > 0 ? (r.annual - 12 * fixed) / kwh : 0);
	var body = {
		consumption: kwh,
		current_price: +bval(view, 'price_kwh') || 0,
		current_fixed: +bval(view, 'fixed_month') || 0,
		offered_price: Math.round(price * 100000) / 100000,
		offered_fixed: fixed,
		customer_name: advisor.customer ? [advisor.customer.last_name, advisor.customer.first_name].filter(Boolean).join(' ') || advisor.customer.company_name || '' : '',
		provider_name: r.provider,
		program_name: r.name
	};
	var t = btn.innerHTML; btn.disabled = true; btn.textContent = 'Δημιουργία…';
	fetch(api('/quote/pdf'), { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H()), body: JSON.stringify(body) })
		.then(function (res) { return res.json(); })
		.then(function (out) {
			if (!out || !out.ok) { toast((out && out.error) || 'Αποτυχία.', false); return; }
			var bin = atob(out.b64), arr = new Uint8Array(bin.length);
			for (var i = 0; i < bin.length; i++) { arr[i] = bin.charCodeAt(i); }
			var url = URL.createObjectURL(new Blob([arr], { type: out.mime || 'application/pdf' }));
			var a = document.createElement('a'); a.href = url; a.download = out.filename || 'prosfora.pdf'; document.body.appendChild(a); a.click(); a.remove();
			setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
		})
		.catch(function () { toast('Σφάλμα δικτύου.', false); })
		.finally(function () { btn.disabled = false; btn.innerHTML = t; });
}

// ---------------------------------------------------------------------
// (360) «Signature»: ο λογαριασμός και η κούρσα
// ---------------------------------------------------------------------

function isSignature() {
	var app = document.getElementById('ecrm-app');
	return !!app && app.getAttribute('data-ui') === 'signature';
}

function sigProviderLogo(r) {
	var p = ((calcData && calcData.providers) || []).filter(function (x) { return +x.id === +r.provider_id; })[0];
	return providerMark(r.provider, p && p.logo_url);
}

var SIG_KIND = { package: 'πακέτο', tiered: 'κλιμακωτό' };

function sigKind(r) {
	return SIG_KIND[r.rule && r.rule.kind] || (TYPE_LABEL[r.price_type] || '').toLowerCase();
}

function sigNum(v, digits) {
	return (+v).toLocaleString('el-GR', { minimumFractionDigits: digits, maximumFractionDigits: digits });
}

/* Αριστερά: ο λογαριασμός όπως τον διάβασε το AI. Τα τρία νούμερα που
 * κρίνουν τη σύγκριση σημειώνονται πράσινα· ό,τι λείπει, πορτοκαλί. Το
 * «Διόρθωσε» ανοίγει τα ίδια πεδία (data-b) από κάτω. */
function sigBillSheet(view) {
	var read = view.querySelector('[data-bill-read]');
	if (!read) { return; }
	var sheet = read.querySelector('[data-sig-bill]');
	if (!sheet) {
		sheet = document.createElement('div');
		sheet.setAttribute('data-sig-bill', '');
		read.insertBefore(sheet, read.firstChild);
		sheet.addEventListener('click', function (e) {
			if (e.target.closest && e.target.closest('[data-sig-fix]')) { read.classList.toggle('is-editing'); }
		});
	}
	var kwh = +bval(view, 'kwh'), days = +bval(view, 'period_days'), annual = +bval(view, 'annual_kwh');
	var price = +bval(view, 'price_kwh'), fixed = +bval(view, 'fixed_month');
	function line(label, val, ok) {
		return '<div class="sig-bill__hl' + (ok ? '' : ' is-miss') + '"><span>' + esc(label) + '</span><b>' + esc(ok ? val : 'λείπει') + '</b>' +
			'<small>' + (ok ? 'διαβάστηκε' : 'συμπλήρωσε') + '</small></div>';
	}
	var energy = view.querySelector('[data-b="energy_type"]'), cat = view.querySelector('[data-b="category"]');
	var chips = [
		energy && energy.selectedIndex >= 0 ? energy.options[energy.selectedIndex].text + ' · ' + (cat && cat.selectedIndex >= 0 ? cat.options[cat.selectedIndex].text : '') : '',
		days > 0 ? 'Περίοδος ' + days + ' ημέρες' : '',
		annual > 0 ? 'Τον χρόνο ' + Math.round(annual).toLocaleString('el-GR') + ' kWh' : ''
	].filter(Boolean);
	sheet.innerHTML = '<div class="sig-bill">' +
		'<div class="sig-bill__hd"><b>' + esc(bval(view, 'provider_name') || 'Πάροχος σήμερα') + '</b><span>Λογαριασμός</span></div>' +
		'<i class="sig-bill__ln is-m"></i><i class="sig-bill__ln is-s"></i>' +
		line('Κατανάλωση', sigNum(kwh, 0) + ' kWh', kwh > 0) +
		'<i class="sig-bill__ln is-s"></i>' +
		line('Τιμή προμήθειας', sigNum(price, 4) + ' €/kWh', price > 0) +
		line('Πάγιο', sigNum(fixed, 2) + ' €/μήνα', bval(view, 'fixed_month') !== '') +
		'<i class="sig-bill__ln is-m"></i><i class="sig-bill__ln"></i>' +
	'</div>' +
	'<div class="sig-bill__chips">' + chips.map(function (c) { return '<span>' + esc(c) + '</span>'; }).join('') +
		'<button type="button" class="sig-bill__fix" data-sig-fix>Διόρθωσε</button></div>';
}

/* Δεξιά: σήμερα → το επιλεγμένο, το κέρδος, και η «κούρσα» όλων. */
function sigQuoteHTML(view, d) {
	sigBillSheet(view);
	var ranked = d.ranked || [];
	if (!ranked.length) { return ''; }
	var i = advisor.pick != null && ranked[advisor.pick] ? advisor.pick : 0;
	var r = ranked[i];
	var cur = d.current_annual;
	var today = bval(view, 'provider_name') || 'Σήμερα';
	var save = cur != null && r.saving != null ? r.saving : null;

	var verdict = '<div class="sig-qv">' +
		(cur != null ? '<div class="sig-qv__box"><small>Σήμερα, ' + esc(today) + '</small><b>' + money(cur) + '</b><em>τον χρόνο, ενέργεια και πάγιο</em></div><span class="sig-qv__arrow" aria-hidden="true">→</span>' : '') +
		'<div class="sig-qv__box is-new"><small>Με ' + esc(withProvider(r.provider, r.name)) + '</small><b>' + money(r.annual) + '</b><em>τον χρόνο</em></div>' +
	'</div>';

	var strip = save == null
		? '<div class="sig-qsave is-plain"><span>Γράψε την τιμή που πληρώνει σήμερα, για να φανεί το κέρδος.</span></div>'
		: (save > 0
			? '<div class="sig-qsave"><span>Ο πελάτης κερδίζει</span><b>' + money(save) + ' τον χρόνο</b><span>≈ ' + esc(String(Math.round(save / 12))) + ' € τον μήνα</span></div>'
			: '<div class="sig-qsave is-neg"><span>Πιο ακριβό από σήμερα</span><b>+' + money(Math.abs(save)) + ' τον χρόνο</b><span>διάλεξε άλλο από κάτω</span></div>');

	// Η κούρσα: τα προγράμματα και το «σήμερα», από το φθηνότερο.
	var lanes = ranked.slice(0, 8).map(function (x, j) { return { x: x, j: j, annual: x.annual }; });
	if (cur != null) { lanes.push({ cur: true, annual: cur }); }
	lanes.sort(function (a, b) { return a.annual - b.annual; });
	var top = lanes.reduce(function (m, l) { return Math.max(m, l.annual); }, 1);
	var nowAt = cur != null ? Math.round(cur / top * 100) : null;
	var race = lanes.map(function (l) {
		var w = esc(String(Math.max(2, Math.round(l.annual / top * 100))));
		if (l.cur) {
			return '<div class="sig-qlane is-cur"><span class="sig-qlane__nm">Σήμερα · ' + esc(today) + '</span>' +
				'<span class="sig-qlane__tr"><i style="width:' + w + '%"></i></span><span class="sig-qlane__v">' + money(l.annual) + '<small>τώρα</small></span></div>';
		}
		var x = l.x;
		var diff = x.saving != null ? '<small class="' + (x.saving >= 0 ? '' : 'is-neg') + '">' + (x.saving >= 0 ? '−' : '+') + money(Math.abs(x.saving)) + '</small>' : '';
		return '<button type="button" class="sig-qlane' + (l.j === 0 ? ' is-best' : '') + (l.j === i ? ' is-on' : '') + '" data-q-pick="' + esc(String(l.j)) + '">' +
			'<span class="sig-qlane__nm">' + sigProviderLogo(x) + '<span>' + esc(x.name) + '</span></span>' +
			'<span class="sig-qlane__tr"><i style="width:' + w + '%"></i>' + (nowAt != null ? '<em style="left:' + esc(String(nowAt)) + '%"></em>' : '') + '</span>' +
			'<span class="sig-qlane__v">' + money(x.annual) + diff + '</span></button>';
	}).join('');

	var kind = sigKind(r);
	var meta = [kind, r.price_updated ? 'τιμή ' + r.price_updated.split('-').reverse().join('/') : '', r.stale ? 'παλιά τιμή, έλεγξέ τη' : ''].filter(Boolean).join(' · ');
	var pick = '<div class="sig-qpick"><p>Διάλεξες <b>' + esc(withProvider(r.provider, r.name)) + '</b>' + (meta ? ' · ' + esc(meta) : '') + '</p>' +
		'<div><button type="button" class="ecrm-btn ecrm-btn--ghost" data-bill-pdf="' + esc(String(i)) + '">' + ICON_DOC + ' PDF προσφοράς</button>' +
		'<button type="button" class="ecrm-btn ecrm-btn--primary" data-bill-apply="' + esc(String(i)) + '">' + ICON_NEW + ' Νέα αίτηση με αυτό</button></div>' +
		fitBlock(r, d.annual_kwh) + '</div>';

	return '<div class="sig-qres">' + verdict + strip +
		'<div class="sig-qrace"><h4>Όλα τα προγράμματα <small>' + esc(Math.round(d.annual_kwh).toLocaleString('el-GR')) + ' kWh/έτος</small></h4>' + race + '</div>' +
		pick + '</div>';
}

function wireSigQuote(view, d) {
	view.querySelectorAll('[data-q-pick]').forEach(function (b) {
		b.addEventListener('click', function () { advisor.pick = +b.getAttribute('data-q-pick'); renderRank(view, d); });
	});
}
