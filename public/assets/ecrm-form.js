import { api, esc, rejectedNote, toast } from '@energy-crm/util';

/* Energy CRM — New Contract form behaviour.
 * Exposes window.ECRMForm.init(rootEl) so it can run standalone OR inside the
 * app shell. Scoped to a root element via data-* hooks (no global ids). */
(function () {
	'use strict';

	var _editFn = null, _resetFn = null;

	// Force fresh data: never serve our API calls from cache.
	var _origFetch = window.fetch.bind(window);
	function fetch(url, opts) {
		opts = opts || {};
		try {
			var base = ECRM.rest.replace(/\/$/, '');
			if (typeof url === 'string' && url.indexOf(base) === 0) {
				opts.cache = 'no-store';
				opts.headers = Object.assign({ 'Cache-Control': 'no-cache', 'Pragma': 'no-cache' }, opts.headers || {});
			}
		} catch (e) {}
		return _origFetch(url, opts);
	}
	function headers(json) {
		var h = { 'X-WP-Nonce': ECRM.nonce };
		if (json) h['Content-Type'] = 'application/json';
		return h;
	}

	function initForm(root) {
		if (!root || root.__ecrmInit) return;
		root.__ecrmInit = true;

		var state = {
			provider_id: null, program_id: null, energy_type: 'power', category: 'home',
			price_type: 'fixed', customer_type: 'individual', activation_type: null, invoice_code: null,
			contract_id: 0, customer_id: 0, extracted_json: null, files: []
		};
		var programsCache = [];
		var mobilePricing = {};
		var providersLoaded = false;
		var pendingProvider = null;
		var q = function (sel) { return root.querySelector(sel); };
		var qa = function (sel) { return root.querySelectorAll(sel); };

		function applyCustomerType() {
			var t = state.customer_type || 'individual';
			qa('[data-when]').forEach(function (el) {
				var ok = el.getAttribute('data-when').split(',').indexOf(t) !== -1;
				el.style.display = ok ? '' : 'none';
			});
			applyEnergyType();
			applyMobileOffer();
		}

		// The electricity half of a COMBO offer: asked for only when that offer
		// is the one chosen. Hidden rather than always-present because a supply
		// number has no business on an ordinary mobile application, and a value
		// left behind in a hidden field is a value that reaches the printed form.
		function applyMobileOffer() {
			var sel = root.querySelector('[name="mobile_offer"]');
			var offer = sel ? sel.value : '';

			qa('[data-when-offer]').forEach(function (el) {
				var ok = el.getAttribute('data-when-offer').split(',').indexOf(offer) !== -1;
				el.hidden = !ok;
				if (!ok) {
					el.querySelectorAll('input, select, textarea').forEach(function (f) { f.value = ''; });
				}
			});

			updateMobilePricing();
		}

		// Στοιχεία Κινητής shows the plan's price as read-only text, not an
		// editable box: MobilePlans::fillValues() always wins over whatever an
		// agent typed there, so an editable field just invited a number that
		// would never print. Runs off the same code the print pipeline reads
		// (programsCache[].code -> mobilePricing), so the screen cannot drift
		// from the paper.
		function updateMobilePricing() {
			var base = root.querySelector('[name="base_price"]');
			var offer = root.querySelector('[name="offer_price"]');
			var after = root.querySelector('[name="price_after"]');
			if (!base || !offer || !after) return;

			var program = programsCache.filter(function (p) { return parseInt(p.id, 10) === state.program_id; })[0];
			var pricing = program && program.code ? mobilePricing[program.code] : null;

			if (!pricing) { base.value = ''; offer.value = ''; after.value = ''; return; }

			var offerSel = root.querySelector('[name="mobile_offer"]');
			var combined = offerSel && (offerSel.value === 'family' || offerSel.value === 'combo');

			base.value = pricing.list;
			offer.value = combined ? pricing.offerCombined : pricing.offer;
			after.value = combined ? pricing.afterCombined : pricing.after;
		}

		root.addEventListener('change', function (ev) {
			if (ev.target && ev.target.name === 'mobile_offer') applyMobileOffer();
		});

		// Anything that only makes sense for one kind of supply: whole sections
		// (the mobile block asks about a line and a SIM), whole rows (Γ-tariff
		// codes are electricity), and single options (Κοινόχρηστο is a meter in
		// a stairwell; Φορητότητα only exists on a phone number).
		function applyEnergyType() {
			var e = state.energy_type || 'power';
			var groups = [];

			qa('[data-when-energy]').forEach(function (el) {
				var ok = el.getAttribute('data-when-energy').split(',').indexOf(e) !== -1;
				el.style.display = ok ? '' : 'none';

				// A hidden option that stays selected is the dangerous case: it
				// is invisible and still decides which form gets printed.
				if (el.classList.contains('ecrm-chip')) {
					el.disabled = !ok;
					var group = el.closest('.ecrm-chips');
					if (group && groups.indexOf(group) === -1) groups.push(group);
				}
			});

			groups.forEach(function (group) {
				var on = group.querySelector('.ecrm-chip.is-on');
				if (on && on.style.display !== 'none') return;

				var first = Array.prototype.filter.call(
					group.querySelectorAll('.ecrm-chip'),
					function (c) { return c.style.display !== 'none'; }
				)[0];

				group.querySelectorAll('.ecrm-chip').forEach(function (c) { c.classList.remove('is-on'); });

				var field = group.getAttribute('data-field');
				if (first) {
					first.classList.add('is-on');
					state[field] = first.getAttribute('data-val');
				} else if (on) {
					// Nothing on offer at all — better empty than a leftover
					// answer nobody can see.
					state[field] = null;
				}
			});
		}

		// keepExisting is set when the extraction started on its own. The agent
		// is typing while it runs, and an answer that arrives late must not
		// overwrite what they entered in the meantime. Pressing the button is
		// an explicit "use what you found", so that path still overwrites.
		function setField(name, val, keepExisting) {
			var input = root.querySelector('.ecrm-input[name="' + name + '"]');
			if (!input || val == null || val === '') return;
			if (keepExisting && input.value.trim() !== '') return;
			input.value = val;
			var field = input.closest('.ecrm-field');
			if (field) { field.classList.add('is-ai'); setTimeout(function () { field.classList.remove('is-ai'); }, 1800); }

			// Το φίλτρο του εντύπου έτρεξε όταν διαλέχτηκε ο πάροχος, δηλαδή
			// ΠΡΙΝ γεμίσει το AI. Ένα πεδίο που μόλις απέκτησε τιμή δεν
			// επιτρέπεται να μείνει κρυμμένο: θα αποθηκευόταν κάτι που ο
			// συνεργάτης δεν βλέπει πουθενά, και δεν θα μπορούσε να το
			// διορθώσει χωρίς να ψάξει πίσω από το «Περισσότερα».
			if (field && field.classList.contains('is-offform')) {
				field.classList.remove('is-offform');
				paintMoreToggles();
			}
		}

		// chips
		qa('.ecrm-chips').forEach(function (group) {
			var field = group.getAttribute('data-field');
			group.addEventListener('click', function (e) {
				var btn = e.target.closest('.ecrm-chip');
				if (!btn) return;
				group.querySelectorAll('.ecrm-chip').forEach(function (b) { b.classList.remove('is-on'); });
				btn.classList.add('is-on');
				state[field] = btn.getAttribute('data-val');
				if (field === 'energy_type') { renderPrograms(); applyEnergyType(); applyMobileOffer(); }
				if (field === 'energy_type' || field === 'category') refreshKbDocs();
				refreshProviderFields();
			});
		});

		// ---- provider-specific fields -----------------------------------
		// Each application asks for a different subset under its own wording.
		// The section below is filled from the provider's own PDF, so what the
		// agent reads on screen matches what is printed on the paper.
		var provFieldsCache = {};

		function providerName() {
			var b = root.querySelector('.ecrm-provider.is-on');
			return b ? (b.getAttribute('data-pname') || '').trim() : '';
		}

		// The programme's code, not the name shown in the dropdown: the server
		// picks which provider sheet to print from it, and a name is free text
		// somebody can rename in the admin without meaning to change the form.
		function programCode() {
			var pr = programsCache.filter(function (p) { return parseInt(p.id, 10) === state.program_id; })[0];
			return pr && pr.code ? String(pr.code) : '';
		}

		function refreshProviderFields() {
			var card = root.querySelector('[data-provider-fields]');
			if (!card) return;

			var name = providerName();
			if (!name) { card.hidden = true; applyTemplateFilter([]); return; }

			var qs = '?provider=' + encodeURIComponent(name) +
				'&energy=' + encodeURIComponent(state.energy_type || 'power') +
				'&customer_type=' + encodeURIComponent(state.customer_type || 'individual') +
				'&program=' + encodeURIComponent(programCode()) +
				'&activation_type=' + encodeURIComponent(state.activation_type || '');

			if (provFieldsCache[qs]) {
				paintProviderFields(card, provFieldsCache[qs]);
				applyTemplateFilter(provFieldsCache[qs].main_inputs);
				return;
			}

			fetch(api('/forms/fields') + qs, { headers: { 'X-WP-Nonce': ECRM.nonce } })
				.then(function (r) { return r.json(); })
				.then(function (d) {
					if (!d || !d.ok) return;
					provFieldsCache[qs] = d;
					paintProviderFields(card, d);
					applyTemplateFilter(d.main_inputs);
				})
				.catch(function () { card.hidden = true; });
		}

		// Fields borrowed by the provider card, so they can be put back exactly
		// where they came from when the provider changes. Without this, switching
		// provider leaves the previous set stranded under the wrong captions.
		var borrowed = [];

		function returnBorrowedFields() {
			borrowed.forEach(function (b) {
				var label = b.el.querySelector('.ecrm-field__label');
				if (label) label.textContent = b.label;
				b.el.removeAttribute('title');
				if (b.next && b.next.parentNode === b.parent) b.parent.insertBefore(b.el, b.next);
				else b.parent.appendChild(b.el);
			});
			borrowed = [];
		}

		// ── Το φίλτρο του εντύπου ────────────────────────────────────────
		// Η φόρμα ρωτάει 85 πεδία. Το χειρότερο έντυπο τυπώνει 23, το μικρότερο
		// 5. Ό,τι δεν τυπώνεται πάει πίσω από «Περισσότερα» ανά κάρτα — ΠΟΤΕ
		// δεν φεύγει από το DOM: το collect() διαβάζει κάθε .ecrm-input με τιμή,
		// κρυμμένο ή όχι, οπότε τίποτα δεν χάνεται στην αποθήκευση.
		//
		// Τέσσερις κανόνες, και οι τρεις τελευταίοι είναι εκεί για να μη
		// κρυφτεί ποτέ κάτι που έχει σημασία:
		//
		//   1. Κενή λίστα σημαίνει «δεν ξέρω ποιο έντυπο» → δείξε τα πάντα.
		//   2. Πεδίο ΜΕ ΤΙΜΗ δεν κρύβεται ποτέ. Αν το γέμισε το AI ή ο χρήστης,
		//      το κρύψιμο θα έκρυβε δεδομένα που θα αποθηκευτούν.
		//   3. Δανεισμένο πεδίο (είναι στην ★ ενότητα) δεν κρύβεται ποτέ.
		//   4. Το φίλτρο δεν ΔΕΙΧΝΕΙ τίποτα — μόνο κρύβει. Ό,τι έχει ήδη κρύψει
		//      το data-when μένει κρυφό.
		var offFormActive = false;

		function fieldName(el) { return el.getAttribute('data-for') || ''; }

		function applyTemplateFilter(mainInputs) {
			var wanted = {};
			var have = mainInputs && mainInputs.length;
			(mainInputs || []).forEach(function (n) { wanted[n] = true; });

			offFormActive = !!have;

			qa('.ecrm-field[data-for]').forEach(function (f) {
				var input = f.querySelector('.ecrm-input');
				var filled = !!(input && String(input.value || '').trim());
				var borrowedNow = !!f.closest('[data-provider-fields]');
				var off = have && !wanted[fieldName(f)] && !filled && !borrowedNow;
				f.classList.toggle('is-offform', off);
			});

			paintMoreToggles();
		}

		// Ορατότητα ΜΕΣΑ στην κάρτα, όχι στη σελίδα.
		//
		// Η πρώτη γραφή χρησιμοποιούσε offsetParent !== null, που είναι λάθος με
		// τον χειρότερο τρόπο: όταν το φίλτρο τρέξει ενώ ο χρήστης βρίσκεται σε
		// άλλη οθόνη, η .ecrm-view της φόρμας είναι display:none και ΚΑΘΕ πεδίο
		// μετριέται αόρατο — οπότε κάθε κάρτα έπαιρνε is-emptyfortemplate και η
		// φόρμα εξαφανιζόταν ολόκληρη μόλις ο χρήστης γύριζε σε αυτήν. Το
		// refreshProviderFields() τρέχει σε κάθε κλικ chip και στη φόρτωση.
		//
		// Η ερώτηση που πρέπει να απαντηθεί είναι στενότερη: «το έκρυψε το
		// data-when;» — δηλαδή υπάρχει inline display:none ΑΝΑΜΕΣΑ στο πεδίο
		// και στην κάρτα του. Ό,τι είναι πιο πάνω αφορά την πλοήγηση, όχι εμάς.
		function hiddenByWhen(field, card) {
			for (var el = field; el && el !== card; el = el.parentElement) {
				if (el.style && el.style.display === 'none') return true;
			}
			return false;
		}

		// Ένα «Περισσότερα (N)» ανά κάρτα, και η κάρτα φεύγει ολόκληρη όταν δεν
		// της μένει τίποτα ορατό. Χωρίς αυτό ο συνεργάτης βλέπει τίτλους
		// ενοτήτων πάνω από το κενό — δες CHANGELOG 2026-08-17 (7), το ίδιο
		// λάθος με την τερματική κατάσταση.
		function paintMoreToggles() {
			root.querySelectorAll('.ecrm-card').forEach(function (card) {
				var fields = card.querySelectorAll('.ecrm-field[data-for]');
				if (!fields.length) return;

				var hidden = card.querySelectorAll('.ecrm-field.is-offform').length;
				var visible = 0;
				fields.forEach(function (f) {
					if (!f.classList.contains('is-offform') && !hiddenByWhen(f, card)) visible++;
				});

				var btn = card.querySelector('[data-more]');
				if (!btn) {
					btn = document.createElement('button');
					btn.type = 'button';
					btn.className = 'ecrm-more';
					btn.setAttribute('data-more', '1');
					btn.addEventListener('click', function () {
						card.classList.toggle('is-showall');
						btn.textContent = card.classList.contains('is-showall')
							? 'Λιγότερα' : 'Περισσότερα (' + hidden + ')';
					});
					card.appendChild(btn);
				}

				btn.hidden = hidden === 0;
				if (!card.classList.contains('is-showall')) {
					btn.textContent = 'Περισσότερα (' + hidden + ')';
				}

				// Καμία ορατή γραμμή και τίποτα κρυμμένο να αποκαλυφθεί → η
				// κάρτα δεν έχει λόγο ύπαρξης σε αυτό το έντυπο.
				card.classList.toggle('is-emptyfortemplate', offFormActive && visible === 0 && hidden === 0);
			});
		}

		function paintProviderFields(card, d) {
			var grid = card.querySelector('[data-provform-grid]');
			var names = Object.keys(d.fields || {});

			returnBorrowedFields();

			if (!d.template || !names.length) { card.hidden = true; return; }

			// The inputs already exist further down the form; borrow them rather
			// than creating copies, or two inputs would share a name and the save
			// would keep whichever the browser serialised last.
			grid.innerHTML = '';
			names.forEach(function (n) {
				var field = root.querySelector('[data-for="' + n + '"]');
				if (!field) return;

				var label = field.querySelector('.ecrm-field__label');
				borrowed.push({
					el: field,
					parent: field.parentNode,
					next: field.nextElementSibling,
					label: label ? label.textContent : ''
				});

				if (label) label.textContent = d.fields[n].label;
				// The provider's own wording, for when ours and theirs differ.
				if (d.fields[n].onForm) field.title = 'Στο έντυπο: ' + d.fields[n].onForm;

				grid.appendChild(field);
			});

			card.hidden = false;
		}

		// duplicate check on ΑΦΜ / supply number
		['afm', 'supply_number'].forEach(function (nm) {
			var el = root.querySelector('[name="' + nm + '"]');
			if (el) { el.addEventListener('input', checkDup); el.addEventListener('change', checkDup); }
		});

		// providers + programs
		fetch(api('/providers'), { headers: headers() })
			.then(function (r) { return r.json(); })
			.then(function (d) { programsCache = d.programs || []; mobilePricing = d.mobile_pricing || {}; renderProviders(d.providers || []); providersLoaded = true; if (pendingProvider) { selectProvider(pendingProvider); pendingProvider = null; } renderPrograms(); })
			.catch(function () { q('[data-providers]').innerHTML = '<div class="ecrm-empty">Δεν φόρτωσαν οι πάροχοι.</div>'; });

		function renderProviders(list) {
			var wrap = q('[data-providers]');
			if (!list.length) { wrap.innerHTML = '<div class="ecrm-empty">Δεν υπάρχουν πάροχοι.</div>'; return; }
			wrap.innerHTML = '';
			list.forEach(function (p) {
				var b = document.createElement('button');
				b.type = 'button'; b.className = 'ecrm-provider'; b.setAttribute('data-pid', p.id);
				b.setAttribute('data-pname', p.name);
				b.setAttribute('data-energy', p.energy_types || '');
				// Providers are admin-only (manage_options) and logo_url already
				// passes esc_url_raw() on save, so this is defence in depth rather
				// than a hole being closed. It also keeps the file's own rule
				// intact: nothing reaches innerHTML without esc().
				b.innerHTML = p.logo_url ? '<img src="' + esc(p.logo_url) + '" alt="' + esc(p.name) + '">' : '<span>' + esc(p.name) + '</span>';
				b.addEventListener('click', function () {
					wrap.querySelectorAll('.ecrm-provider').forEach(function (x) { x.classList.remove('is-on'); });
					b.classList.add('is-on'); state.provider_id = parseInt(p.id, 10);
					var lab = q('[data-selprov]'); if (lab) lab.textContent = 'Επιλεγμένος πάροχος: ' + p.name;
					limitEnergyToProvider();
					renderPrograms();
					refreshKbDocs();
					refreshProviderFields();
				});
				wrap.appendChild(b);
			});
		}

		function renderPrograms() {
			var sel = q('[data-program]');
			var opts = programsCache.filter(function (pr) {
				if (state.provider_id && parseInt(pr.provider_id, 10) !== state.provider_id) return false;
				if (state.energy_type && pr.energy_type !== state.energy_type) return false;
				return true;
			});
			sel.innerHTML = '<option value="">—</option>';
			opts.forEach(function (pr) { var o = document.createElement('option'); o.value = pr.id; o.textContent = pr.name; sel.appendChild(o); });
			sel.onchange = function () {
				state.program_id = this.value ? parseInt(this.value, 10) : null;
				// Orizon splits its forms by programme, so the field list can
				// change without the provider changing.
				refreshProviderFields();
				updateMobilePricing();
			};
		}

		function selectProvider(pid) {
			pid = parseInt(pid, 10);
			if (!providersLoaded) { pendingProvider = pid; return; }
			var btn = root.querySelector('.ecrm-provider[data-pid="' + pid + '"]');
			qa('.ecrm-provider').forEach(function (x) { x.classList.remove('is-on'); });
			if (btn) {
				btn.classList.add('is-on'); state.provider_id = pid;
				var lab = q('[data-selprov]'); if (lab) lab.textContent = 'Επιλεγμένος πάροχος: ' + (btn.getAttribute('data-pname') || '');
			}
			limitEnergyToProvider();
			refreshKbDocs();
		}

		// Only offer what the provider actually sells. Orizon is a mobile
		// operator; the energy suppliers do not do mobile. Leaving all three
		// chips available meant an agent could pick Orizon with the default
		// "Ηλεκτρισμός" still selected and get a mobile application filled in
		// from a meter — or no form at all, with no explanation.
		function limitEnergyToProvider() {
			var chips = root.querySelectorAll('.ecrm-chips[data-field="energy_type"] .ecrm-chip');
			var btn = root.querySelector('.ecrm-provider.is-on');
			var offered = btn ? (btn.getAttribute('data-energy') || '').split(',').filter(Boolean) : [];

			// No provider chosen yet, or one with nothing declared: show all
			// rather than an empty row the agent cannot get past.
			var all = offered.length === 0;
			var allowed = [];

			// style.display rather than the hidden attribute, to match how every
			// other conditional part of this form is shown and hidden.
			chips.forEach(function (c) {
				var v = c.getAttribute('data-val');
				var ok = all || offered.indexOf(v) !== -1;
				c.style.display = ok ? '' : 'none';
				c.disabled = !ok;
				if (ok) allowed.push(c);
			});

			// If what was selected is no longer on offer, move to the first
			// thing that is. Silently leaving an impossible choice selected is
			// how the wrong form gets printed.
			var current = root.querySelector('.ecrm-chips[data-field="energy_type"] .ecrm-chip.is-on');
			if (allowed.length && (!current || current.style.display === 'none')) {
				chips.forEach(function (c) { c.classList.remove('is-on'); });
				allowed[0].classList.add('is-on');
				state.energy_type = allowed[0].getAttribute('data-val');
				renderPrograms();
			}

			// Always, not only when the choice moved: the rows and options that
			// depend on the energy have to match whatever is selected now.
			applyEnergyType();
		}

		function setChip(field, val) {
			if (val == null || val === '') return;
			var group = root.querySelector('.ecrm-chips[data-field="' + field + '"]');
			if (!group) { state[field] = val; return; }
			group.querySelectorAll('.ecrm-chip').forEach(function (b) {
				var on = b.getAttribute('data-val') === String(val);
				b.classList.toggle('is-on', on);
			});
			state[field] = val;
		}

		// ---- Knowledge Base: required documents for the chosen provider/case ----
		var kbDocsT;
		function refreshKbDocs() {
			var panel = q('[data-kbdocs]'), body = q('[data-kbdocs-body]');
			if (!panel || !body) return;
			if (!state.provider_id || state.energy_type === 'mobile') { panel.hidden = true; return; }
			clearTimeout(kbDocsT);
			kbDocsT = setTimeout(function () {
				var type = (state.category === 'home' || state.category === 'business') ? state.category : '';
				var url = api('/kb') + '?section=docs&provider=' + state.provider_id +
					'&energy=' + encodeURIComponent(state.energy_type) + (type ? '&type=' + type : '');
				fetch(url, { headers: headers() }).then(function (r) { return r.json(); }).then(function (d) {
					var entries = [];
					((d && d.groups) || []).forEach(function (g) { (g.entries || []).forEach(function (e) { entries.push(e); }); });
					if (!entries.length) { panel.hidden = true; body.innerHTML = ''; return; }
					body.innerHTML = entries.map(function (e) {
						return '<details class="ecrm-kbdoc" open><summary>' + esc(e.title) + '</summary>' +
							'<div class="ecrm-kbdoc__b">' + (e.body || '') + '</div></details>';
					}).join('');
					panel.hidden = false;
				}).catch(function () { panel.hidden = true; });
			}, 200);
		}

		// ---- Duplicate check on ΑΦΜ / supply number ----
		var dupT;
		function checkDup() {
			var warn = q('[data-dupwarn]');
			if (!warn) return;
			var afmEl = root.querySelector('[name="afm"]'), supEl = root.querySelector('[name="supply_number"]');
			var afm = (afmEl ? afmEl.value : '').replace(/\D+/g, '');
			var supply = (supEl ? supEl.value : '').trim();
			if (afm.length < 9 && !supply) { warn.hidden = true; warn.innerHTML = ''; return; }
			clearTimeout(dupT);
			dupT = setTimeout(function () {
				var url = api('/contracts/duplicate') + '?afm=' + encodeURIComponent(afm) +
					'&supply=' + encodeURIComponent(supply) + (state.contract_id ? '&exclude=' + state.contract_id : '');
				fetch(url, { headers: headers() }).then(function (r) { return r.json(); }).then(function (d) {
					var m = (d && d.matches) || [];
					if (!m.length) { warn.hidden = true; warn.innerHTML = ''; return; }
					var rows = m.map(function (x) {
						var who = x.in_scope ? (x.owner ? ' · ' + esc(x.owner) : '') : ' · άλλος συνεργάτης δικτύου';
						return '<li><strong>' + esc(x.code) + '</strong> — ' + esc(x.customer) +
							' <span class="ecrm-pillstat">' + esc(x.status_label) + '</span>' + who +
							' <span class="ecrm-muted">(' + (x.match_on === 'afm' ? 'ίδιο ΑΦΜ' : 'ίδια παροχή') + ')</span></li>';
					}).join('');
					warn.innerHTML = '<div class="ecrm-dupwarn__head">⚠️ Πιθανή διπλοεγγραφή — βρέθηκαν ' + m.length +
						' υπάρχουσες συμβάσεις:</div><ul class="ecrm-dupwarn__list">' + rows + '</ul>' +
						'<div class="ecrm-dupwarn__note">Έλεγξέ το πριν συνεχίσεις — μπορεί να είναι ήδη καταχωρημένη.</div>';
					warn.hidden = false;
				}).catch(function () {});
			}, 400);
		}

		var ADDR_PARTS = ['supply', 'billing'];
		var ADDR_FIELDS = ['street', 'street_no', 'postal_code', 'city', 'region'];

		var CUST_FIELDS = ['afm','doy','postal_code','first_name','last_name','father_name','company_name','adt','birth_date','region','city','street','street_no','phone','mobile','email','supply_number','meter_number','start_date','term_months','end_date'];

		function applyEdit(c) {
			if (!c) return;
			state.contract_id = parseInt(c.id, 10) || 0;
			state.customer_id = parseInt(c.customer_id, 10) || 0;
			setStage(c.status);
			setChip('energy_type', c.energy_type); state.energy_type = c.energy_type || 'power';
			setChip('category', c.category); state.category = c.category || 'home';
			setChip('price_type', c.price_type); if (c.price_type) state.price_type = c.price_type;
			setChip('customer_type', c.customer_type); state.customer_type = c.customer_type || 'individual';
			setChip('activation_type', c.activation_type); if (c.activation_type) state.activation_type = c.activation_type;
			setChip('invoice_code', c.invoice_code); if (c.invoice_code) state.invoice_code = c.invoice_code;
			applyCustomerType();
			selectProvider(c.provider_id);
			state.program_id = c.program_id ? parseInt(c.program_id, 10) : null;
			renderPrograms();
			var sel = q('[data-program]'); if (sel && c.program_id) sel.value = c.program_id;
			CUST_FIELDS.forEach(function (k) { setField(k, c[k]); });
			ADDR_PARTS.forEach(function (which) {
				var cb = root.querySelector('[data-addr-same="' + which + '"]');
				if (!cb) return;
				// Contracts saved before these columns existed have no value;
				// they meant "the same", which is what the column defaults to.
				cb.checked = c[which + '_addr_same'] == null || !!Number(c[which + '_addr_same']);
				ADDR_FIELDS.forEach(function (p) { setField(which + '_' + p, c[which + '_' + p]); });
				toggleAddr(cb);
			});
			// Editing shows what was actually recorded, not the default. A
			// contract saved before consent was captured must not look as
			// though it had been.
			var consentEl = q('[data-consent]');
			if (consentEl) consentEl.checked = !!c.consent_at;
			if (c.extra) { Object.keys(c.extra).forEach(function (k) { setField(k, c.extra[k]); }); }
			// mobile_offer lives in the extras bag and is only restored above —
			// applyCustomerType() ran earlier against whatever the select's
			// default was, so a saved Family/COMBO contract would reopen with
			// its own combo fields hidden. Re-run now that the real value is in
			// place; this also recomputes the read-only price boxes.
			applyMobileOffer();
			var modeEl = q('.ecrm-foot__mode strong'); if (modeEl) modeEl.textContent = 'Επεξεργασία #' + (c.code || c.id);
			var titleEl = q('[data-form-title]'); if (titleEl) titleEl.textContent = 'Επεξεργασία Αίτησης';
			root.scrollIntoView ? root.scrollIntoView({ behavior: 'smooth', block: 'start' }) : window.scrollTo(0, 0);
		}

		function resetForm() {
			state.contract_id = 0; state.customer_id = 0; state.extracted_json = null;
			state.provider_id = null; state.program_id = null; state.invoice_code = null; state.activation_type = null;
			state.energy_type = 'power'; state.category = 'home'; state.price_type = 'fixed'; state.customer_type = 'individual';
			state.files = []; state.filesUploaded = false;
			// A new application must read its own documents, even if they
			// happen to be named like the previous customer's.
			extractedFor = ''; clearTimeout(autoTimer);
			qa('.ecrm-input').forEach(function (i) { i.value = ''; });
			// A new application starts with both addresses assumed identical,
			// which is the common case and matches the column defaults.
			root.querySelectorAll('[data-addr-same]').forEach(function (cb) {
				cb.checked = true; toggleAddr(cb);
			});
			// Back to ticked for the next application, matching the markup —
			// otherwise an agent who unticked it once carries that across every
			// contract they enter for the rest of the session.
			var consentReset = q('[data-consent]'); if (consentReset) consentReset.checked = true;
			qa('.ecrm-provider').forEach(function (x) { x.classList.remove('is-on'); });
			var lab = q('[data-selprov]'); if (lab) lab.textContent = '';
			// reset chips to defaults (first chip of each group except where default known)
			var defs = { energy_type: 'power', category: 'home', price_type: 'fixed', customer_type: 'individual' };
			qa('.ecrm-chips').forEach(function (group) {
				var f = group.getAttribute('data-field');
				group.querySelectorAll('.ecrm-chip').forEach(function (b) {
					b.classList.toggle('is-on', defs[f] != null && b.getAttribute('data-val') === defs[f]);
				});
			});
			applyCustomerType(); renderPrograms();
			var modeEl = q('.ecrm-foot__mode strong'); if (modeEl) modeEl.textContent = 'Νέα αίτηση';
			var titleEl = q('[data-form-title]'); if (titleEl) titleEl.textContent = 'Δημιουργία Αίτησης';
			var notes = q('[data-notes]'); if (notes) notes.value = '';
			var fl = q('[data-filelist]'); if (fl) fl.innerHTML = '';
			// Last, because it reads state.contract_id, which the first line
			// of this function has just cleared.
			setStage('');
		}

		_editFn = applyEdit; _resetFn = resetForm;

		// dropzone
		var drop = q('[data-drop]'), input = q('[data-files]'), pick = q('[data-pick]'), extractBtn = q('[data-extract]');

		function guessKind(name) {
			var n = name.toLowerCase();
			if (/(λογαριασμ|bill|invoice|παροχ)/.test(n)) return 'provider_bill';
			if (/(ταυτοτητ|id|adt|passport|διαβατ)/.test(n)) return 'id_card';
			return 'other';
		}
		// A photo of an ID card off a phone is 4000×3000 and several megabytes.
		// All of it is uploaded, base64-encoded, and sent — and then scaled
		// down anyway, because the model caps images at 1568px on its longest
		// edge. Everything above that costs upload time and nothing else,
		// which on a phone in someone's living room is most of the wait.
		//
		// 1600 leaves the cap intact while making a five-megabyte photo about
		// three hundred kilobytes. PDFs are left alone: a canvas cannot read
		// them, and they are already small.
		var MAX_EDGE = 1600;

		// How many files are still being scaled. The automatic reading waits
		// for zero: firing at 1.2s while a large photo is still shrinking
		// would upload the original and undo the whole point.
		var shrinking = 0;

		function shrink(file) {
			if (!/^image\/(jpeg|png)$/.test(file.type) || file.size < 400 * 1024) {
				return Promise.resolve(file);
			}

			return new Promise(function (resolve) {
				var url = URL.createObjectURL(file);
				var img = new Image();

				// Any failure hands back the original: a document that uploads
				// slowly beats a document that never arrives.
				img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
				img.onload = function () {
					URL.revokeObjectURL(url);
					var scale = Math.min(1, MAX_EDGE / Math.max(img.width, img.height));
					if (scale === 1) { resolve(file); return; }

					var canvas = document.createElement('canvas');
					canvas.width = Math.round(img.width * scale);
					canvas.height = Math.round(img.height * scale);
					canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);

					canvas.toBlob(function (blob) {
						if (!blob || blob.size >= file.size) { resolve(file); return; }
						resolve(new File([blob], file.name, { type: 'image/jpeg' }));
					}, 'image/jpeg', 0.85);
				};
				img.src = url;
			});
		}

		function addFiles(fileList) {
			var accepted = Array.prototype.filter.call(fileList, function (f) {
				return /\.(pdf|jpe?g|png)$/i.test(f.name)
					|| ['application/pdf', 'image/jpeg', 'image/png'].indexOf(f.type) > -1;
			}).slice(0, Math.max(0, 10 - state.files.length));

			if (!accepted.length) return;

			// Shrinking is asynchronous, so the list is drawn twice: once with
			// the originals so the agent sees them land, once when they are
			// ready. Without the first pass the drop zone looks broken.
			accepted.forEach(function (f) { state.files.push({ file: f, kind: guessKind(f.name) }); });
			shrinking += accepted.length;
			renderFiles();

			Promise.all(accepted.map(function (f) {
				return shrink(f).then(function (small) {
					var entry = state.files.filter(function (i) { return i.file === f; })[0];
					if (entry) { entry.file = small; }
					shrinking--;
				});
			})).then(renderFiles);
		}
		function renderFiles() {
			var ul = q('[data-filelist]'); ul.innerHTML = '';
			state.files.forEach(function (item, i) {
				var li = document.createElement('li'); li.className = 'ecrm-fileitem';
				var kindOpts = [
					['id_card', 'Ταυτότητα/Διαβατήριο'],
					['provider_bill', 'Λογαριασμός παρόχου'],
					['authorization', 'Εξουσιοδότηση'],
					['residence', 'Αποδεικτικό κατοικίας'],
					['e9', 'Ε9 / ακινήτου'],
					['death_cert', 'Πιστοποιητικό θανάτου'],
					['heir_cert', 'Εγγυτέρων συγγενών'],
					['gemi', 'ΓΕΜΗ / Καταστατικό'],
					['iban', 'IBAN'],
					['other', 'Άλλο']
				];
				var kindSel = '<select class="ecrm-kind" data-i="' + i + '">' +
					kindOpts.map(function (o) { return '<option value="' + o[0] + '"' + (item.kind === o[0] ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') +
					'</select>';
				// The name of a file the user just picked themselves, so this was
				// only ever self-inflicted — but an unescaped name is an unescaped
				// name, and the guard test does not grant exceptions for intent.
				li.innerHTML = '<span class="ecrm-fileitem__name">' + esc(item.file.name) + '</span>' + kindSel +
					'<button type="button" class="ecrm-fileitem__rm" data-i="' + i + '">✕</button>';
				ul.appendChild(li);
			});
			// Changing what a document *is* changes how it should be read, so
			// it re-arms the automatic pass exactly like adding a file does.
			ul.querySelectorAll('.ecrm-kind').forEach(function (s) { s.addEventListener('change', function () { state.files[this.getAttribute('data-i')].kind = this.value; scheduleExtraction(); }); });
			ul.querySelectorAll('.ecrm-fileitem__rm').forEach(function (b) { b.addEventListener('click', function () { state.files.splice(parseInt(this.getAttribute('data-i'), 10), 1); renderFiles(); }); });
			extractBtn.disabled = state.files.length === 0;
			scheduleExtraction();
		}

		pick.addEventListener('click', function () { input.click(); });
		drop.addEventListener('click', function (e) { if (e.target === drop || e.target.classList.contains('ecrm-drop__title') || e.target.classList.contains('ecrm-drop__icon')) input.click(); });
		drop.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); } });
		input.addEventListener('change', function () { addFiles(this.files); this.value = ''; });
		['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-drag'); }); });
		['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('is-drag'); }); });
		drop.addEventListener('drop', function (e) { if (e.dataTransfer && e.dataTransfer.files) addFiles(e.dataTransfer.files); });

		// extraction
		//
		// It starts by itself as soon as the documents settle, instead of
		// waiting for the button. The reading takes seconds no matter what we
		// do; what we can decide is whether the agent spends them watching a
		// spinner or choosing the provider and typing the customer's name. By
		// the time they reach the fields, these are usually already filled.
		//
		// The button stays: it is how you re-read after swapping a document,
		// and how you insist when the automatic pass left something out.
		var extractionRunning = false;
		var extractedFor = '';
		var autoTimer = null;

		// Identity of the current document set, so an automatic pass never
		// repeats over files it has already read.
		function filesSignature() {
			return state.files.map(function (i) {
				return i.file.name + ':' + i.file.size + ':' + i.kind;
			}).join('|');
		}

		function scheduleExtraction() {
			clearTimeout(autoTimer);
			if (!state.files.length || shrinking > 0) return;
			if (filesSignature() === extractedFor) return;
			// Long enough for someone dropping three files in a row to end up
			// with one reading rather than three.
			autoTimer = setTimeout(function () { runExtraction(true); }, 1200);
		}

		function runExtraction(auto) {
			if (!state.files.length || extractionRunning) return;
			var signature = filesSignature();
			if (auto && signature === extractedFor) return;
			extractionRunning = true;
			extractedFor = signature;

			var statusEl = q('[data-ai-status]');
			extractBtn.disabled = true; extractBtn.classList.add('is-loading');
			statusEl.textContent = auto ? 'Διαβάζονται τα έγγραφα…' : 'Ανάλυση εγγράφων με AI…';
			// The queue lives here rather than on the server: the browser is
			// already holding the files, so waiting for a free slot costs
			// nothing and keeps identity documents off the server's disk.
			// A 503 with retry_after means "not now", not "failed".
			var waited = 0;
			function send() {
				var fd = new FormData();
				state.files.forEach(function (item) { fd.append('files[]', item.file); fd.append('kinds[]', item.kind); });
				return fetch(api('/extract'), { method: 'POST', headers: headers(false), body: fd })
					.then(function (r) { return r.json(); })
					.then(function (d) {
						if (d && d.queued) {
							var wait = Math.max(2, parseInt(d.retry_after, 10) || 8);
							waited += wait;
							// Give up rather than retry forever; four minutes is
							// past the point where something is actually wrong.
							if (waited > 240) { return { ok: false, error: 'Η εξαγωγή αργεί υπερβολικά. Δοκίμασε ξανά σε λίγο.' }; }
							statusEl.textContent = 'Στη σειρά… (' + waited + 's)';
							return new Promise(function (resolve) {
								setTimeout(function () { resolve(send()); }, wait * 1000);
							});
						}
						return d;
					});
			}

			send()
				.then(function (d) {
					if (!d || !d.ok) {
						statusEl.textContent = '';
						// A failed automatic pass may be retried by the button.
						if (auto) { extractedFor = ''; }
						toast((d && d.error) || 'Η εξαγωγή απέτυχε.', false);
						return;
					}
					var filled = 0;
					Object.keys(d.data || {}).forEach(function (k) {
						if (d.data[k]) { setField(k, d.data[k], auto); filled++; }
						if (k === 'invoice_code' && d.data[k]) {
							var gi = root.querySelector('.ecrm-chips[data-field="invoice_code"]');
							if (gi) gi.querySelectorAll('.ecrm-chip').forEach(function (b) {
								var on = b.getAttribute('data-val') === d.data[k];
								b.classList.toggle('is-on', on); if (on) state.invoice_code = d.data[k];
							});
						}
						if (k === 'customer_type' && d.data[k]) {
							var g = root.querySelector('.ecrm-chips[data-field="customer_type"]');
							if (g) g.querySelectorAll('.ecrm-chip').forEach(function (b) {
								var on = b.getAttribute('data-val') === d.data[k];
								b.classList.toggle('is-on', on); if (on) state.customer_type = d.data[k];
							});
						}
					});
					state.extracted_json = JSON.stringify(d.data);
					statusEl.textContent = '';
					toast('Συμπληρώθηκαν ' + filled + ' πεδία. Έλεγξέ τα πριν την οριστικοποίηση.');
					checkDup();
					refreshKbDocs();
				})
				.catch(function () {
					statusEl.textContent = '';
					if (auto) { extractedFor = ''; }
					toast('Σφάλμα δικτύου στην εξαγωγή.', false);
				})
				.finally(function () {
					extractionRunning = false;
					extractBtn.disabled = state.files.length === 0;
					extractBtn.classList.remove('is-loading');
				});
		}

		// The button re-reads what is there, overwriting: it is a deliberate
		// "use what you find", unlike the automatic pass.
		extractBtn.addEventListener('click', function () { runExtraction(false); });

		// save
		function uploadFiles() {
			if (!state.contract_id || !state.files.length || state.filesUploaded) return;
			var fd = new FormData();
			state.files.forEach(function (item) { fd.append('files[]', item.file); fd.append('kinds[]', item.kind); });
			fetch(api('/contracts/' + state.contract_id + '/files'), { method: 'POST', headers: headers(false), body: fd })
				.then(function (r) { return r.json(); })
				.then(function (d) {
					if (!d || !d.ok) { return; }
					var note = rejectedNote(d.rejected);
					if (d.saved) {
						state.filesUploaded = true;
						toast('Αποθηκεύτηκαν ' + d.saved + ' έγγραφα στη σύμβαση.' + (note ? ' ' + note : ''), !note);
					} else if (note) {
						toast(note, false);
					}
				})
				.catch(function () { toast('Τα έγγραφα δεν ανέβηκαν — σφάλμα δικτύου.', false); });
		}

		/*
		 * Which save actions this stage allows, and the status the form is on.
		 *
		 * ContractStatus lets nothing move TO draft, and only draft move to
		 * new. So "Προσωρινή Αποθήκευση" and "Οριστικοποίηση" are legal in
		 * exactly one case — unsaved, or still a draft — and past that the only
		 * honest action is saving fields without touching the stage. The server
		 * refuses the rest with 409 since 2026-08-16; this is so the agent is
		 * never offered a button that will be refused.
		 */
		function setStage(status) {
			state.status = status || '';
			var stageable = !state.contract_id || state.status === 'draft';
			var draftBtn = q('[data-save-draft]');
			var finalBtn = q('[data-finalize]');
			var saveBtn = q('[data-save-changes]');
			if (draftBtn) draftBtn.hidden = !stageable;
			if (finalBtn) finalBtn.hidden = !stageable;
			if (saveBtn) saveBtn.hidden = stageable;
		}

		function collect(status) {
			var payload = {
				status: status, provider_id: state.provider_id, program_id: state.program_id,
				energy_type: state.energy_type, category: state.category, price_type: state.price_type,
				customer_type: state.customer_type, activation_type: state.activation_type, invoice_code: state.invoice_code || undefined,
				contract_id: state.contract_id || undefined, customer_id: state.customer_id || undefined,
				extracted_json: state.extracted_json || undefined, notes: q('[data-notes]').value || ''
			};
			payload.extra = {};
			qa('.ecrm-input').forEach(function (i) {
				if (!i.value) return;
				if (i.getAttribute('data-extra')) payload.extra[i.name] = i.value;
				else payload[i.name] = i.value;
			});
			// Checkboxes carry no .ecrm-input class, and an unticked one must be
			// sent as 0 rather than omitted: "not the same" is a real answer.
			root.querySelectorAll('[data-addr-same]').forEach(function (cb) {
				payload[cb.name] = cb.checked ? 1 : 0;
			});
			var consentEl = q('[data-consent]');
			payload.consent = consentEl && consentEl.checked ? 1 : 0;
			return payload;
		}
		function readField(name) { var el = root.querySelector('[name="' + name + '"]'); return el ? el.value.trim() : ''; }

		// React to customer-type chip changes for conditional sections.
		root.querySelectorAll('.ecrm-chips[data-field="customer_type"] .ecrm-chip').forEach(function (c) {
			c.addEventListener('click', function () { setTimeout(applyCustomerType, 0); });
		});
		applyCustomerType();

		// sync checkbox: copy customer/rep data into the contact person
		function setVal(name, val) { var el = root.querySelector('[name="' + name + '"]'); if (el && val != null) el.value = val; }
		function getVal(name) { var el = root.querySelector('[name="' + name + '"]'); return el ? el.value : ''; }
		root.querySelectorAll('[data-sync]').forEach(function (cb) {
			cb.addEventListener('change', function () {
				if (!this.checked) return;
				var what = this.getAttribute('data-sync');
				if (what === 'contact') {
					setVal('contact_first_name', getVal('rep_first_name') || getVal('first_name'));
					setVal('contact_last_name', getVal('rep_last_name') || getVal('last_name'));
					setVal('contact_father_name', getVal('rep_father_name') || getVal('father_name'));
					setVal('contact_mobile', getVal('rep_mobile') || getVal('mobile'));
					setVal('contact_email', getVal('rep_email') || getVal('email'));
					setVal('contact_phone', getVal('rep_phone') || getVal('phone'));
					setVal('contact_afm', getVal('rep_afm') || getVal('afm'));
				}
			});
		});

		// Supply / billing address: ticked means "same as the customer's", and
		// the fields collapse. The flag is what gets saved — an empty address
		// the agent deliberately marked as identical must stay distinguishable
		// from one they simply never filled in.
		function toggleAddr(cb) {
			var which = cb.getAttribute('data-addr-same');
			var box = root.querySelector('[data-addr-fields="' + which + '"]');
			if (box) box.hidden = cb.checked;
		}
		root.querySelectorAll('[data-addr-same]').forEach(function (cb) {
			cb.addEventListener('change', function () { toggleAddr(this); });
			toggleAddr(cb);
		});

		// auto-compute Ημ. Λήξης from Έναρξη + Διάρκεια (μήνες)
		function recalcEnd() {
			var sEl = root.querySelector('[name="start_date"]'), tEl = root.querySelector('[name="term_months"]'), eEl = root.querySelector('[name="end_date"]');
			if (!sEl || !tEl || !eEl) return;
			var s = sEl.value, t = parseInt(tEl.value, 10);
			if (s && t > 0) {
				var d = new Date(s); d.setMonth(d.getMonth() + t);
				if (!isNaN(d.getTime())) eEl.value = d.toISOString().slice(0, 10);
			} else if (tEl.value === '0') {
				eEl.value = '';
			}
		}
		['start_date','term_months'].forEach(function (n) { var el = root.querySelector('[name="' + n + '"]'); if (el) el.addEventListener('change', recalcEnd); });

		// ΑΦΜ lookup via EU VIES (auto-fills επωνυμία + διεύθυνση).
		var afmBtn = root.querySelector('[data-afm-search]');
		if (afmBtn) afmBtn.addEventListener('click', function () {
			var afm = readField('afm');
			if (!afm || !validAfm(afm)) { toast('Δώσε έγκυρο ΑΦΜ πρώτα.', false); fieldNote(afmEl, 'Μη έγκυρο ΑΦΜ.', 'err'); return; }
			var b = this; b.disabled = true; var t = b.textContent; b.textContent = '...';
			fetch(api('/lookup/afm') + '?afm=' + encodeURIComponent(afm), { headers: headers(false) })
				.then(function (r) { return r.json(); })
				.then(function (d) {
					if (!d || !d.ok) { toast((d && d.error) || 'Αποτυχία αναζήτησης.', false); return; }
					if (!d.valid) { toast('Το ΑΦΜ δεν βρέθηκε ενεργό στο μητρώο VIES.', false); return; }
					function fill(name, val) { var el = root.querySelector('[name="' + name + '"]'); if (el && val && !el.value) el.value = val; }
					if (d.name) {
						// reveal company fields by triggering the existing chip handler
						var chip = root.querySelector('.ecrm-chips[data-field="customer_type"] .ecrm-chip[data-val="company"]');
						if (chip) chip.click();
						var ce = root.querySelector('[name="company_name"]');
						if (ce) ce.value = d.name;
					}
					if (d.parsed) {
						fill('street', d.parsed.street);
						fill('street_no', d.parsed.street_no);
						fill('postal_code', d.parsed.postal_code);
						fill('city', d.parsed.city);
					}
					toast('Βρέθηκε: ' + (d.name || 'έγκυρο ΑΦΜ') + (d.address ? ' — ' + d.address : ''));
				})
				.catch(function () { toast('Σφάλμα δικτύου.', false); })
				.finally(function () { b.disabled = false; b.textContent = t; });
		});

		// --- Live validation: ΑΦΜ check digit + soft supply check ---
		function validAfm(s) {
			var d = (s || '').replace(/\D+/g, '');
			if (d.length !== 9 || d === '000000000') return false;
			var sum = 0;
			for (var i = 0; i < 8; i++) { sum += parseInt(d[i], 10) * (1 << (8 - i)); }
			return ((sum % 11) % 10) === parseInt(d[8], 10);
		}
		function fieldNote(el, msg, kind) {
			if (!el) return;
			var note = el.parentNode.querySelector('.ecrm-fieldnote');
			if (!msg) { if (note) note.remove(); el.classList.remove('is-invalid', 'is-warn'); return; }
			if (!note) { note = document.createElement('small'); note.className = 'ecrm-fieldnote'; el.parentNode.appendChild(note); }
			note.textContent = msg;
			note.className = 'ecrm-fieldnote is-' + (kind || 'err');
			el.classList.toggle('is-invalid', kind === 'err');
			el.classList.toggle('is-warn', kind === 'warn');
		}
		var afmEl = root.querySelector('[name="afm"]');
		if (afmEl) afmEl.addEventListener('blur', function () {
			var v = this.value.trim();
			fieldNote(this, v && !validAfm(v) ? 'Μη έγκυρο ΑΦΜ (έλεγχος ψηφίου).' : '', 'err');
		});
		var supEl = root.querySelector('[name="supply_number"]');
		if (supEl) supEl.addEventListener('blur', function () {
			var d = this.value.replace(/\D+/g, '');
			var warn = d && (state.energy_type === 'power' ? (d.length < 9 || d.length > 13) : (d.length < 6 || d.length > 16));
			fieldNote(this, warn ? 'Ελέγξτε τον αριθμό παροχής.' : '', 'warn');
		});

		function save(status, btn) {
			if (status === 'new' && !state.provider_id) { toast('Διάλεξε πάροχο πρώτα.', false); return; }
			// GDPR: consent required to finalize.
			var consentEl = q('[data-consent]');
			if (status === 'new' && consentEl && !consentEl.checked) {
				toast('Απαιτείται η συναίνεση του πελάτη (GDPR) για οριστικοποίηση.', false);
				consentEl.focus();
				return;
			}
			// Hard-block finalize on a missing or invalid ΑΦΜ. Missing is the
			// newer of the two: duplicate detection and search by full ΑΦΜ both
			// run on afm_hash and nothing else, so a customer saved without one
			// can never be flagged as a duplicate. The server refuses this too
			// — DraftExitGate, from both endpoints — and this is here so the
			// agent is told before the round trip, next to the field.
			var afmv = readField('afm');
			if (status === 'new' && !afmv) {
				toast('Χρειάζεται ΑΦΜ πελάτη για την οριστικοποίηση.', false);
				fieldNote(afmEl, 'Συμπλήρωσε το ΑΦΜ του πελάτη.', 'err');
				if (afmEl) afmEl.focus();
				return;
			}
			if (status === 'new' && afmv && !validAfm(afmv)) {
				toast('Μη έγκυρο ΑΦΜ — διόρθωσέ το πριν την οριστικοποίηση.', false);
				fieldNote(afmEl, 'Μη έγκυρο ΑΦΜ (έλεγχος ψηφίου).', 'err');
				return;
			}
			// Duplicate check on finalize: warn if same ΑΦΜ or παροχή already exists.
			if (status === 'new') {
				var afm = readField('afm'), supply = readField('supply_number');
				if (afm || supply) {
					btn.disabled = true;
					fetch(api('/customers/check') + '?afm=' + encodeURIComponent(afm) + '&supply=' + encodeURIComponent(supply), { headers: headers(false) })
						.then(function (r) { return r.json(); })
						.then(function (d) {
							btn.disabled = false;
							if (d && d.ok && d.matches && d.matches.length) {
								var m = d.matches[0];
								var msg = 'Υπάρχει ήδη σύμβαση (' + (m.code || '') + ') για ' + (m.name || 'πελάτη') +
									(m.afm && m.afm === afm ? ' με ίδιο ΑΦΜ' : '') + (m.supply && m.supply === supply ? ' με ίδια παροχή' : '') +
									'.\\n\\nΘες να συνεχίσεις την καταχώρηση;';
								if (confirm(msg)) doSave(status, btn);
							} else { doSave(status, btn); }
						})
						.catch(function () { doSave(status, btn); });
					return;
				}
			}
			doSave(status, btn);
		}

		function doSave(status, btn) {
			btn.disabled = true;
			fetch(api('/contracts'), { method: 'POST', headers: headers(true), body: JSON.stringify(collect(status)) })
				.then(function (r) { return r.json(); })
				.then(function (d) {
					if (d && d.ok) {
						state.contract_id = d.contract_id; state.customer_id = d.customer_id;
						// An undefined status means "fields only, no transition".
						toast(!status
							? 'Οι αλλαγές αποθηκεύτηκαν.'
							: (status === 'draft' ? 'Αποθηκεύτηκε προσωρινά.' : 'Η αίτηση οριστικοποιήθηκε.'));
						// Finalising moves the form off draft, so the pair of
						// buttons on screen has to move with it.
						setStage(status || state.status);
						uploadFiles();
					} else { toast((d && d.error) || 'Η αποθήκευση απέτυχε.', false); }
				})
				.catch(function () { toast('Σφάλμα δικτύου.', false); })
				.finally(function () { btn.disabled = false; });
		}
		q('[data-save-draft]').addEventListener('click', function () { save('draft', this); });
		q('[data-finalize]').addEventListener('click', function () { save('new', this); });
		// No status in the payload: collect() puts `status: undefined`, which
		// JSON.stringify drops, and contractFrom() then omits the column
		// entirely — the same no-op path an ordinary field edit already takes.
		q('[data-save-changes]').addEventListener('click', function () { save(undefined, this); });

		// ---- action banners (PDF / email sign / live link) ----------------
		function ensureSaved(cb) {
			if (state.contract_id) { cb(); return; }
			// auto-save a draft first so the actions have a contract to work on
			toast('Αποθήκευση πρόχειρου…');
			fetch(api('/contracts'), { method: 'POST', headers: headers(true), body: JSON.stringify(collect('draft')) })
				.then(function (r) { return r.json(); })
				.then(function (d) {
					if (d && d.ok) { state.contract_id = d.contract_id; state.customer_id = d.customer_id; uploadFiles(); cb(); }
					else { toast('Αποτυχία αποθήκευσης.', false); }
				})
				.catch(function () { toast('Σφάλμα δικτύου.', false); });
		}

		function openPdf(btn, win) {
			var id = state.contract_id; btn.style.opacity = '.6';
			fetch(api('/contracts/' + id + '/provider-form'), { headers: headers(false) })
				.then(function (r) { return r.text(); })
				.then(function (x) {
					var d = JSON.parse(x);
					if (!d || !d.ok) throw new Error((d && d.error) || 'fail');
					var bin = atob(d.b64), len = bin.length, arr = new Uint8Array(len);
					for (var i = 0; i < len; i++) { arr[i] = bin.charCodeAt(i); }
					var url = URL.createObjectURL(new Blob([arr], { type: 'application/pdf' }));
					if (win) { win.location.href = url; } else { window.open(url, '_blank'); }
					btn.style.opacity = '';
				})
				.catch(function (e) { if (win) { try { win.close(); } catch (er) {} } toast((e && e.message) || 'Αποτυχία δημιουργίας εντύπου.', false); btn.style.opacity = ''; });
		}

		function sendSignEmail(btn) {
			var id = state.contract_id;
			btn.style.opacity = '.6';
			fetch(api('/contracts/' + id + '/sign-link'), { method: 'POST', headers: headers(true), body: JSON.stringify({ email: true }) })
				.then(function (r) { return r.json(); })
				.then(function (d) { btn.style.opacity = ''; if (d && d.ok && d.emailed) toast('Στάλθηκε email υπογραφής στον πελάτη.'); else if (d && d.ok) toast('Ο πελάτης δεν έχει καταχωρημένο email.', false); else toast((d && d.error) || 'Αποτυχία αποστολής.', false); })
				.catch(function () { btn.style.opacity = ''; toast('Σφάλμα δικτύου.', false); });
		}

		function makeLiveLink(btn) {
			var id = state.contract_id;
			btn.style.opacity = '.6';
			fetch(api('/contracts/' + id + '/sign-link'), { method: 'POST', headers: headers(true), body: JSON.stringify({ email: false }) })
				.then(function (r) { return r.json(); })
				.then(function (d) {
					btn.style.opacity = '';
					if (d && d.ok && d.url) {
						if (navigator.clipboard) { navigator.clipboard.writeText(d.url).then(function () { toast('Ο σύνδεσμος αντιγράφηκε.'); }, function () { prompt('Σύνδεσμος υπογραφής:', d.url); }); }
						else { prompt('Σύνδεσμος υπογραφής:', d.url); }
					} else { toast((d && d.error) || 'Αποτυχία.', false); }
				})
				.catch(function () { btn.style.opacity = ''; toast('Σφάλμα δικτύου.', false); });
		}

		qa('[data-act]').forEach(function (b) {
			b.addEventListener('click', function () {
				var act = this.getAttribute('data-act'); var btn = this;
				var win = (act === 'pdf') ? window.open('', '_blank') : null;
				ensureSaved(function () {
					if (act === 'pdf') openPdf(btn, win);
					else if (act === 'email') sendSignEmail(btn);
					else if (act === 'livelink') makeLiveLink(btn);
				});
			});
		});
	}

	window.ECRMForm = { init: initForm, edit: function (c) { if (_editFn) _editFn(c); }, reset: function () { if (_resetFn) _resetFn(); } };

	// Standalone auto-init
	document.addEventListener('DOMContentLoaded', function () {
		var standalone = document.querySelector('.ecrm[data-standalone] .ecrm-form');
		if (standalone) initForm(standalone);
	});
})();
