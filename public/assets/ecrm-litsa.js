import { api, esc } from '@energy-crm/util';

/* Energy CRM — ο βοηθός «AIde» (πρώην «Λίτσα», μετά «AlfrAId»· (320) 17/10).
 * Floating chat that relays to /wp-json/ecrm/v1/assistant. Το ιστορικό ζει
 * πλέον server-side (build queue 14, GET/POST /assistant/history), ένα
 * αρχείο ανά χρήστη -- ΟΧΙ πια localStorage, που κρατούσε τη συνομιλία σε
 * καθαρό κείμενο στη συσκευή επ' αόριστον. Depends on the ECRM global.
 *
 * Το όνομα του αρχείου, οι κλάσεις .ecrm-litsa* και τα data-litsa-* ΔΕΝ
 * άλλαξαν με τη μετονομασία: τα χρησιμοποιεί και η καρτέλα αίτησης
 * («Ρώτα τον AIde»), και δεν αξίζει ένα rename σε όλο το repo για ένα όνομα
 * που ο χρήστης δεν βλέπει. Μακέτα: docs/UI-ALFRAID.html. */
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

	// Οι έτοιμες ερωτήσεις της άδειας οθόνης. Μόνο πράγματα που ο βοηθός
	// ΜΠΟΡΕΙ να απαντήσει με όσα του δίνονται (αιτήσεις, οδηγίες εφαρμογής,
	// καταστάσεις) -- όχι χαρτιά ανά πάροχο, που θέλουν τη Βάση Γνώσης.
	var CHIP_Q = [
		'Ποιες αιτήσεις μου έχουν κολλήσει;',
		'Πώς καταχωρώ νέα αίτηση;',
		'Τι σημαίνει «Οριστικοποίηση»;'
	];
	// Σταθερά σχήματα (ίδια με τα icon() του μενού: Εκκρεμότητες, Νέα αίτηση, pulse).
	var CHIP_ICO = [
		'<path d="M12 8v5"/><path d="M12 16.5v.01"/><path d="M10.3 3.8 2.6 17.5A2 2 0 0 0 4.3 20.5h15.4a2 2 0 0 0 1.7-3L13.7 3.8a2 2 0 0 0-3.4 0z"/>',
		'<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M12 12v5"/><path d="M9.5 14.5h5"/>',
		'<path d="M3 12h4l2 6 4-14 2 8h6"/>'
	];
	var SPARK = '<svg class="ecrm-litsa__spark" viewBox="17.1 1.6 5 5" aria-hidden="true"><path d="M19.6 1.6l.7 1.8 1.8.7-1.8.7-.7 1.8-.7-1.8-1.8-.7 1.8-.7z"/></svg>';

	function headers() { return { 'Content-Type': 'application/json', 'X-WP-Nonce': ECRM.nonce }; }

	/*
	 * Μορφοποίηση απάντησης, ΜΕΤΑ το escape (τίποτα από το μοντέλο δεν φτάνει
	 * ως HTML). Μέχρι το (320) αποδίδονταν μόνο τα **έντονα**, οπότε οι
	 * απαντήσεις έδειχναν σκέτα «## Τέλος», «1.» και «-» (έλεγχος στο live,
	 * 17/10). Καλύπτει όσα γράφει στην πράξη το μοντέλο: επικεφαλίδες,
	 * λίστες με κουκκίδα ή αριθμό, έντονα, `κώδικα`, παραγράφους.
	 */
	function inline(s) {
		return s
			.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>')
			.replace(/`([^`\n]+)`/g, '<code>$1</code>');
	}

	function md(text) {
		var out = [];
		var para = [];
		var list = '';
		// Κενή γραμμή ανάμεσα σε «1.» και «2.» δεν κόβει τη λίστα (αλλιώς η
		// αρίθμηση ξεκινούσε πάλι από το 1). Την κλείνει ό,τι άλλο έρθει.
		var gap = false;

		function flushPara() {
			if (para.length) { out.push('<p>' + para.join('<br>') + '</p>'); para = []; }
		}
		function closeList() {
			if (list) { out.push('</' + list + '>'); list = ''; }
			gap = false;
		}
		function item(kind, html) {
			flushPara();
			if (list !== kind) { closeList(); out.push('<' + kind + '>'); list = kind; }
			gap = false;
			out.push('<li>' + html + '</li>');
		}

		esc(text).split(/\r?\n/).forEach(function (raw) {
			var line = raw.trim();
			var m;

			if (line === '') { flushPara(); if (list) { gap = true; } return; }

			if ((m = line.match(/^#{1,6}\s+(.+)$/))) { flushPara(); closeList(); out.push('<h4>' + inline(m[1]) + '</h4>'); return; }
			if ((m = line.match(/^[-*•]\s+(.+)$/))) { item('ul', inline(m[1])); return; }
			if ((m = line.match(/^\d+[.)]\s+(.+)$/))) { item('ol', inline(m[1])); return; }

			if (list) { closeList(); }
			para.push(inline(line));
		});

		flushPara();
		closeList();

		return out.join('');
	}

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

	function emptyState() {
		return '<div class="ecrm-litsa__empty">' +
			'<h3>Πώς να βοηθήσω;</h3>' +
			'<p>Ρώτα με για τις αιτήσεις σου ή για το πώς γίνεται κάτι στο CRM.</p>' +
			'<div class="ecrm-litsa__chips">' +
			CHIP_Q.map(function (q, i) {
				return '<button type="button" class="ecrm-litsa__chip" data-litsa-ask="' + i + '">' +
					'<svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true">' + CHIP_ICO[i] + '</svg>' + esc(q) + '</button>';
			}).join('') +
			'</div></div>';
	}

	function render() {
		// Το CSS παλμεύει τη σπίθα του σήματος όσο περιμένουμε απάντηση.
		root.setAttribute('data-busy', busy ? '1' : '0');
		if (!history.length) {
			body.innerHTML = emptyState();
			return;
		}
		body.innerHTML = history.map(function (m) {
			if (m.role === 'user') {
				return '<div class="ecrm-msg ecrm-msg--me">' + esc(m.content).replace(/\n/g, '<br>') + '</div>';
			}
			return '<div class="ecrm-msg ecrm-msg--bot">' + md(m.content) + '</div>';
		}).join('') + (busy ? '<div class="ecrm-litsa__think">' + SPARK + 'Ψάχνει…</div>' : '');
		body.scrollTop = body.scrollHeight;
	}

	function toggle() {
		var open = root.getAttribute('data-open') === '1';
		root.setAttribute('data-open', open ? '0' : '1');
		if (!open) {
			loadHistory(function () {
				render();
				// Στο κινητό ΟΧΙ αυτόματο focus: άνοιγε αμέσως το πληκτρολόγιο και
				// έκρυβε τη μισή συνομιλία (ιδιοκτήτης, 14/10). Εκεί το πληκτρολόγιο
				// ανοίγει μόνο όταν πατηθεί το πεδίο. Στον υπολογιστή μένει όπως ήταν.
				if (!window.matchMedia('(max-width: 767px)').matches) {
					setTimeout(function () { input.focus(); }, 50);
				}
			});
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

	// (320) «Νέα συνομιλία» αντί για «Διαγραφή συνομιλίας», χωρίς confirm():
	// η συνομιλία δεν είναι επιχειρηματικό δεδομένο, ο χρήστης θέλει απλώς
	// καθαρή οθόνη, και το παράθυρο του browser ήταν το πιο «πρόχειρο»
	// κομμάτι του πάνελ (μακέτα docs/UI-ALFRAID.html, εγκρίθηκε 17/10).
	function clearHistory() {
		if (!history.length || busy) return;
		fetch(api('/assistant/history/clear'), { method: 'POST', headers: headers() })
			// Η τοπική συνομιλία καθαρίζει έτσι κι αλλιώς (.finally παρακάτω) --
			// αν αποτύχει το server-side clear, δεν υπάρχει λόγος να το δει ο
			// χρήστης· θα ξαναδοκιμαστεί φυσικά στην επόμενη clearHistory().
			.catch(function () {})
			.finally(function () { history = []; render(); });
	}

	var clearBtn = root.querySelector('[data-litsa-clear]');

	root.querySelectorAll('[data-litsa-toggle]').forEach(function (b) { b.addEventListener('click', toggle); });
	// (312) Το κουμπί της πάνω μπάρας (κινητό) ζει έξω από το #ecrm-litsa.
	document.querySelectorAll('[data-litsa-open]').forEach(function (b) { b.addEventListener('click', toggle); });
	// Στο κινητό το ανοιχτό πάνελ έχει σκούρο φόντο γύρω του: πάτημα εκεί το κλείνει.
	root.addEventListener('click', function (e) {
		if (e.target === root && root.getAttribute('data-open') === '1') { toggle(); }
	});
	// Οι έτοιμες ερωτήσεις: πάτημα = στέλνεται αυτούσια.
	body.addEventListener('click', function (e) {
		var chip = e.target.closest('[data-litsa-ask]');
		if (!chip) return;
		var q = CHIP_Q[Number(chip.getAttribute('data-litsa-ask'))];
		if (!q) return;
		input.value = q;
		send();
	});
	sendBtn.addEventListener('click', send);
	if (clearBtn) { clearBtn.addEventListener('click', clearHistory); }
	input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); send(); } });
})();
