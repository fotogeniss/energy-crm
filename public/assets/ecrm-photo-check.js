/* Energy CRM — έλεγχος φωτογραφίας εγγράφου τη στιγμή της λήψης (340).
 * Mockup docs/UI-SCAN-QUALITY.html (εγκρίθηκε 30/10).
 *
 * Ο πάροχος απορρίπτει θολές/σκοτεινές ταυτότητες μέρες μετά, όταν ο πελάτης
 * έχει φύγει. Εδώ ο έλεγχος γίνεται στο κινητό, χωρίς ανέβασμα και χωρίς AI:
 *   - θολή:     ευκρίνεια ακμών (διασπορά του Laplacian) ΔΙΑ την αντίθεση της
 *               εικόνας, σε αντίγραφο 1200 px. Η διαίρεση κάνει τον αριθμό
 *               ανεξάρτητο από το φως: μια αμυδρή αλλά καθαρή φωτογραφία δεν
 *               βγαίνει «θολή»·
 *   - σκοτεινή: μέση φωτεινότητα·
 *   - μικρή:    μικρή πλευρά της ΑΡΧΙΚΗΣ εικόνας.
 *
 * Τα όρια μετρήθηκαν 30/10 σε 27 δοκιμαστικές φωτογραφίες (λογαριασμός, έντυπο,
 * ταυτότητα· καθαρές, θολές, σκοτεινές, μικρές -- CHANGELOG 340). Είναι επίτηδες
 * ανεκτικά: ελαφρύ θόλωμα περνά. Ένα ψεύτικο «θολή» μαθαίνει στον πωλητή να
 * πατά «Κράτα την» χωρίς να κοιτά. Η «αντανάκλαση» του mockup ΔΕΝ μπήκε: το
 * άσπρο χαρτί ενός λογαριασμού έδινε τα ίδια νούμερα με το φλας.
 * Ποτέ δεν μπλοκάρει: αποφασίζει ο πωλητής.
 *
 * Το `analyse()` είναι καθαρή συνάρτηση (pixels μέσα, προβλήματα έξω), ώστε να
 * ελέγχεται χωρίς browser (tools/js-tests/photo-check.test.js).
 */

export var LIMITS = {
	edge: 1200,     // px μεγαλύτερης πλευράς για την ανάλυση
	sharp: 25,      // ευκρίνεια ×1000 / αντίθεση κάτω από αυτό → θολή
	flat: 100,      // αντίθεση (διασπορά γκρι) κάτω από αυτό → δεν φαίνεται τίποτα
	dark: 60,       // μέση φωτεινότητα (0-255) κάτω από αυτό → σκοτεινή
	small: 800,     // μικρή πλευρά της αρχικής κάτω από αυτό → μικρή
};

var TEXT = {
	blur: 'Η φωτογραφία είναι θολή. Ο πάροχος μάλλον θα την απορρίψει. Κράτα το κινητό σταθερό και πάτα στην οθόνη για εστίαση.',
	dark: 'Πολύ σκοτεινή. Πήγαινε κοντά σε φως.',
	small: 'Πολύ μικρή. Βγάλ\' την πιο κοντά, να γεμίζει την οθόνη.',
};

/**
 * @param {Uint8ClampedArray|Array<number>} rgba Pixels RGBA, w × h.
 * @param {number} w
 * @param {number} h
 * @param {number} origW Πλάτος της αρχικής εικόνας.
 * @param {number} origH Ύψος της αρχικής εικόνας.
 */
export function analyse(rgba, w, h, origW, origH) {
	var n = w * h;
	var gray = new Float32Array(n);
	var sum = 0;

	for (var i = 0, p = 0; i < n; i++, p += 4) {
		var g = 0.299 * rgba[p] + 0.587 * rgba[p + 1] + 0.114 * rgba[p + 2];
		gray[i] = g;
		sum += g;
	}

	var brightness = n ? sum / n : 0;
	var contrast = 0;
	for (var j = 0; j < n; j++) { var dg = gray[j] - brightness; contrast += dg * dg; }
	contrast = n ? contrast / n : 0;

	var lsum = 0, lsq = 0, count = 0;
	for (var y = 1; y < h - 1; y++) {
		for (var x = 1; x < w - 1; x++) {
			var k = y * w + x;
			var lap = 4 * gray[k] - gray[k - 1] - gray[k + 1] - gray[k - w] - gray[k + w];
			lsum += lap; lsq += lap * lap; count++;
		}
	}

	var lmean = count ? lsum / count : 0;
	var edges = count ? lsq / count - lmean * lmean : 0;
	var metrics = {
		sharpness: contrast > 0 ? 1000 * edges / contrast : 0,
		contrast: contrast,
		brightness: brightness,
	};

	var problems = [];
	if (Math.min(origW, origH) < LIMITS.small) problems.push('small');
	if (brightness < LIMITS.dark) problems.push('dark');
	// Η θολότητα κρίνεται μόνο σε εικόνα με αρκετό φως: δύο μηνύματα για το
	// ίδιο πρόβλημα μπερδεύουν.
	else if (contrast < LIMITS.flat || metrics.sharpness < LIMITS.sharp) problems.push('blur');

	return {
		metrics: metrics,
		problems: problems.map(function (code) { return { code: code, text: TEXT[code] }; }),
	};
}

/**
 * Ελέγχει ένα αρχείο. Ό,τι δεν είναι εικόνα (PDF) ή δεν διαβάζεται → χωρίς
 * προβλήματα: ο έλεγχος βοηθά, δεν εμποδίζει.
 *
 * @param {File} file
 * @return {Promise<{checked: boolean, problems: Array<{code: string, text: string}>}>}
 */
export function checkPhoto(file) {
	if (!file || !/^image\//.test(file.type || '') || typeof document === 'undefined') {
		return Promise.resolve({ checked: false, problems: [] });
	}

	return new Promise(function (resolve) {
		var url = URL.createObjectURL(file);
		var img = new Image();

		img.onerror = function () { URL.revokeObjectURL(url); resolve({ checked: false, problems: [] }); };
		img.onload = function () {
			URL.revokeObjectURL(url);
			try {
				var scale = Math.min(1, LIMITS.edge / Math.max(img.width, img.height));
				var w = Math.max(1, Math.round(img.width * scale));
				var h = Math.max(1, Math.round(img.height * scale));
				var canvas = document.createElement('canvas');
				canvas.width = w; canvas.height = h;
				var ctx = canvas.getContext('2d', { willReadFrequently: true });
				ctx.drawImage(img, 0, 0, w, h);
				var res = analyse(ctx.getImageData(0, 0, w, h).data, w, h, img.width, img.height);
				resolve({ checked: true, problems: res.problems, metrics: res.metrics });
			} catch (e) {
				resolve({ checked: false, problems: [] });
			}
		};
		img.src = url;
	});
}
