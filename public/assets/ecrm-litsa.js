import { api, esc } from '@energy-crm/util';

/* Energy CRM — "Λίτσα" assistant.
 * Floating chat that relays to /wp-json/ecrm/v1/assistant. History persists
 * in localStorage so it survives reloads. Depends on the ECRM global. */
(function () {
	'use strict';

	var root = document.getElementById('ecrm-litsa');
	if (!root || typeof ECRM === 'undefined') return;

	var KEY = 'ecrm_litsa_history_v1';
	var body = root.querySelector('[data-litsa-body]');
	var input = root.querySelector('[data-litsa-input]');
	var sendBtn = root.querySelector('[data-litsa-send]');
	var history = load();
	var busy = false;

	function load() { try { return JSON.parse(localStorage.getItem(KEY)) || []; } catch (e) { return []; } }
	function save() { try { localStorage.setItem(KEY, JSON.stringify(history.slice(-40))); } catch (e) {} }

	function render() {
		if (!history.length) {
			body.innerHTML = '<div class="ecrm-litsa__greet">Γεια! Είμαι η <strong>Λίτσα</strong> 👋<br>Ρώτησέ με πώς να καταχωρίσεις σύμβαση, πώς δουλεύει η AI εξαγωγή, ή πόσες εκκρεμότητες έχεις.</div>';
			return;
		}
		body.innerHTML = history.map(function (m) {
			return '<div class="ecrm-msg ecrm-msg--' + (m.role === 'user' ? 'me' : 'bot') + '">' + esc(m.content).replace(/\n/g, '<br>') + '</div>';
		}).join('') + (busy ? '<div class="ecrm-msg ecrm-msg--bot ecrm-msg--typing"><span></span><span></span><span></span></div>' : '');
		body.scrollTop = body.scrollHeight;
	}

	function toggle() {
		var open = root.getAttribute('data-open') === '1';
		root.setAttribute('data-open', open ? '0' : '1');
		if (!open) { render(); setTimeout(function () { input.focus(); }, 50); }
	}

	function send() {
		var text = input.value.trim();
		if (!text || busy) return;
		history.push({ role: 'user', content: text });
		input.value = '';
		busy = true; render(); save();

		fetch(api('/assistant'), {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ECRM.nonce },
			body: JSON.stringify({ messages: history.slice(-20) })
		})
			.then(function (r) { return r.json(); })
			.then(function (d) {
				busy = false;
				if (d && d.ok) { history.push({ role: 'assistant', content: d.reply }); }
				else { history.push({ role: 'assistant', content: (d && d.error) || 'Κάτι πήγε στραβά. Δοκίμασε ξανά.' }); }
				render(); save();
			})
			.catch(function () { busy = false; history.push({ role: 'assistant', content: 'Σφάλμα δικτύου. Δοκίμασε ξανά.' }); render(); save(); });
	}

	root.querySelectorAll('[data-litsa-toggle]').forEach(function (b) { b.addEventListener('click', toggle); });
	sendBtn.addEventListener('click', send);
	input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); send(); } });
})();
