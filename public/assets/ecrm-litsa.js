import { api, esc } from '@energy-crm/util';

/* Energy CRM — "Λίτσα" assistant.
 * Floating chat that relays to /wp-json/ecrm/v1/assistant. Το ιστορικό ζει
 * πλέον server-side (build queue 14, GET/POST /assistant/history), ένα
 * αρχείο ανά χρήστη -- ΟΧΙ πια localStorage, που κρατούσε τη συνομιλία σε
 * καθαρό κείμενο στη συσκευή επ' αόριστον. Depends on the ECRM global. */
(function () {
	'use strict';

	var root = document.getElementById('ecrm-litsa');
	if (!root || typeof ECRM === 'undefined') return;

	var body = root.querySelector('[data-litsa-body]');
	var input = root.querySelector('[data-litsa-input]');
	var sendBtn = root.querySelector('[data-litsa-send]');
	var history = [];
	// Φορτώνεται μία φορά, στο πρώτο άνοιγμα -- όχι στο page load, ίδιο
	// timing με πριν (το render() ούτως ή άλλως δεν καλούνταν παρά μόνο στο
	// toggle()/send(), οπότε το σώμα του chat έμενε κενό μέχρι τότε).
	var historyLoaded = false;
	var busy = false;

	function headers() { return { 'Content-Type': 'application/json', 'X-WP-Nonce': ECRM.nonce }; }

	function loadHistory(done) {
		if (historyLoaded) { done(); return; }
		fetch(api('/assistant/history'), { headers: headers() })
			.then(function (r) { return r.json(); })
			.then(function (d) {
				history = (d && d.ok && Array.isArray(d.messages))
					? d.messages.map(function (m) { return { role: m.role, content: m.content }; })
					: [];
			})
			.catch(function () { history = []; })
			.finally(function () { historyLoaded = true; done(); });
	}

	function render() {
		if (!history.length) {
			body.innerHTML = '<div class="ecrm-litsa__greet">Γεια! Είμαι η <strong>Λίτσα</strong> <svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21a9 9 0 100-18 9 9 0 000 18z"/><path d="M8.5 14a4 4 0 007 0M9 9.5v.01M15 9.5v.01"/></svg><br>Ρώτησέ με πώς να καταχωρίσεις σύμβαση, πώς δουλεύει η AI εξαγωγή, ή πόσες εκκρεμότητες έχεις.</div>';
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
		if (!open) {
			loadHistory(function () { render(); setTimeout(function () { input.focus(); }, 50); });
		}
	}

	function send() {
		var text = input.value.trim();
		if (!text || busy) return;
		history.push({ role: 'user', content: text });
		input.value = '';
		busy = true; render();

		fetch(api('/assistant'), {
			method: 'POST',
			headers: headers(),
			body: JSON.stringify({ messages: history.slice(-20) })
		})
			.then(function (r) { return r.json(); })
			.then(function (d) {
				busy = false;
				// Ο διάλογος αποθηκεύει και τις δύο πλευρές server-side μόνος
				// του (ECRM_Assistant::chat()) -- δεν χρειάζεται δεύτερο write
				// από εδώ, μόνο η τοπική προβολή ενημερώνεται.
				if (d && d.ok) { history.push({ role: 'assistant', content: d.reply }); }
				else { history.push({ role: 'assistant', content: (d && d.error) || 'Κάτι πήγε στραβά. Δοκίμασε ξανά.' }); }
				render();
			})
			.catch(function () { busy = false; history.push({ role: 'assistant', content: 'Σφάλμα δικτύου. Δοκίμασε ξανά.' }); render(); });
	}

	// Απλό confirm(), όχι ο διάλογος με πληκτρολόγηση: η συνομιλία δεν είναι
	// επιχειρηματικό δεδομένο που παίρνει μαζί του κάτι μη αναδημιουργήσιμο —
	// άρα δεν εμπίπτει στην κατηγορία «οριστική ενέργεια» του docs/UI-DIALOGS.html.
	// Η ίδια η αποθήκευση άλλαξε θέση (server-side, build queue 14) αλλά αυτή
	// η στάθμιση δεν άλλαξε.
	function clearHistory() {
		if (!history.length) return;
		if (!confirm('Διαγραφή της συνομιλίας με τη Λίτσα;')) return;
		fetch(api('/assistant/history/clear'), { method: 'POST', headers: headers() })
			// Η τοπική συνομιλία καθαρίζει έτσι κι αλλιώς (.finally παρακάτω) --
			// αν αποτύχει το server-side clear, δεν υπάρχει λόγος να το δει ο
			// χρήστης· θα ξαναδοκιμαστεί φυσικά στην επόμενη clearHistory().
			.catch(function () {})
			.finally(function () { history = []; render(); });
	}

	var clearBtn = root.querySelector('[data-litsa-clear]');

	root.querySelectorAll('[data-litsa-toggle]').forEach(function (b) { b.addEventListener('click', toggle); });
	sendBtn.addEventListener('click', send);
	if (clearBtn) { clearBtn.addEventListener('click', clearHistory); }
	input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); send(); } });
})();
