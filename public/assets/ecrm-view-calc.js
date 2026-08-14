/* Energy CRM — quote calculator: current cost against the offer. */

import { api, esc, fetch, H, toast, viewEl } from '@energy-crm/util';

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

	view.innerHTML =
		'<header class="ecrm-head"><div class="ecrm-eyebrow">Εργαλείο πώλησης</div><h2 class="ecrm-title">Υπολογισμός Προσφοράς</h2>' +
		'<p class="ecrm-sub">Εκτίμηση ετήσιου κόστους & οφέλους έναντι του τρέχοντος παρόχου</p></header>' +
		'<div class="ecrm-card"><div class="ecrm-calc">' +
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
			'<div class="ecrm-calc__actions"><button type="button" class="ecrm-btn ecrm-btn--primary" data-calc-pdf>📄 PDF Προσφοράς</button></div>' +
			'<p class="ecrm-hint">Η εκτίμηση αφορά χρέωση ενέργειας + πάγιο. Ρυθμιζόμενες χρεώσεις, φόροι και δημοτικά τέλη είναι κοινά μεταξύ παρόχων και δεν υπολογίζονται.</p>' +
		'</div></div>';

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
