/* Energy CRM — QR code, γραμμένο εδώ (344).
 *
 * Για την αφίσα του «Ο σύνδεσμός μου». Ο σύνδεσμος είναι προσωπικός και
 * ανακλήσιμος, οπότε δεν περνά από καμία υπηρεσία τρίτων: το QR βγαίνει στον
 * browser. Βιβλιοθήκη δεν μπήκε γιατί ο κανόνας είναι μικρός και σταθερός
 * (ISO/IEC 18004): byte mode, διόρθωση σφαλμάτων επιπέδου M, εκδόσεις 1–40.
 * Ο σχεδιασμός ακολουθεί τη γνωστή δομή του «QR Code generator» του Project
 * Nayuki. Ελέγχθηκε με αποκωδικοποιητή (OpenCV) σε διάφορα μήκη -- βλ.
 * tools/js-tests/qr.test.js για τους σταθερούς ελέγχους.
 *
 * Καθαρό module: καμία εξάρτηση, κανένα DOM.
 */

/* Επίπεδο M: κωδικολέξεις διόρθωσης ανά μπλοκ και πλήθος μπλοκ, ανά έκδοση. */
var ECC_PER_BLOCK = [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26,
	26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28];
var BLOCKS = [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16,
	17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49];

/** Η τιμή του επιπέδου M στα bits μορφής. */
var FORMAT_M = 0;

function bit(x, i) {
	return ((x >>> i) & 1) !== 0;
}

function rawDataModules(ver) {
	var result = (16 * ver + 128) * ver + 64;
	if (ver >= 2) {
		var n = Math.floor(ver / 7) + 2;
		result -= (25 * n - 10) * n - 55;
		if (ver >= 7) result -= 36;
	}
	return result;
}

function dataCodewords(ver) {
	return Math.floor(rawDataModules(ver) / 8) - ECC_PER_BLOCK[ver] * BLOCKS[ver];
}

/* --- Reed–Solomon πάνω στο GF(2^8), πολυώνυμο 0x11D. --- */
function gfMul(x, y) {
	var z = 0;
	for (var i = 7; i >= 0; i--) {
		z = (z << 1) ^ ((z >>> 7) * 0x11D);
		z ^= ((y >>> i) & 1) * x;
	}
	return z & 0xFF;
}

function rsDivisor(degree) {
	var result = [];
	for (var i = 0; i < degree - 1; i++) result.push(0);
	result.push(1);
	var root = 1;
	for (i = 0; i < degree; i++) {
		for (var j = 0; j < result.length; j++) {
			result[j] = gfMul(result[j], root);
			if (j + 1 < result.length) result[j] ^= result[j + 1];
		}
		root = gfMul(root, 0x02);
	}
	return result;
}

function rsRemainder(data, divisor) {
	var result = divisor.map(function () { return 0; });
	data.forEach(function (b) {
		var factor = b ^ result.shift();
		result.push(0);
		divisor.forEach(function (coef, i) { result[i] ^= gfMul(coef, factor); });
	});
	return result;
}

/* --- Τα δεδομένα: byte mode, τερματισμός, γέμισμα. --- */
function utf8(text) {
	if (typeof TextEncoder !== 'undefined') return Array.prototype.slice.call(new TextEncoder().encode(text));
	var s = unescape(encodeURIComponent(text));
	var out = [];
	for (var i = 0; i < s.length; i++) out.push(s.charCodeAt(i));
	return out;
}

function pickVersion(len) {
	for (var ver = 1; ver <= 40; ver++) {
		var countBits = ver <= 9 ? 8 : 16;
		if (4 + countBits + 8 * len <= dataCodewords(ver) * 8) return ver;
	}
	return -1;
}

function dataBytes(bytes, ver) {
	var bits = [];
	function push(val, len) { for (var i = len - 1; i >= 0; i--) bits.push((val >>> i) & 1); }

	push(0x4, 4);
	push(bytes.length, ver <= 9 ? 8 : 16);
	bytes.forEach(function (b) { push(b, 8); });

	var capacity = dataCodewords(ver) * 8;
	push(0, Math.min(4, capacity - bits.length));
	push(0, (8 - bits.length % 8) % 8);
	for (var pad = 0xEC; bits.length < capacity; pad ^= 0xEC ^ 0x11) push(pad, 8);

	var out = [];
	for (var i = 0; i < bits.length; i += 8) {
		var v = 0;
		for (var j = 0; j < 8; j++) v = (v << 1) | bits[i + j];
		out.push(v);
	}
	return out;
}

function withEcc(data, ver) {
	var numBlocks = BLOCKS[ver];
	var eccLen = ECC_PER_BLOCK[ver];
	var raw = Math.floor(rawDataModules(ver) / 8);
	var numShort = numBlocks - raw % numBlocks;
	var shortLen = Math.floor(raw / numBlocks);
	var divisor = rsDivisor(eccLen);
	var blocks = [];

	for (var i = 0, k = 0; i < numBlocks; i++) {
		var dat = data.slice(k, k + shortLen - eccLen + (i < numShort ? 0 : 1));
		k += dat.length;
		var ecc = rsRemainder(dat, divisor);
		if (i < numShort) dat.push(0);
		blocks.push(dat.concat(ecc));
	}

	var result = [];
	for (i = 0; i < blocks[0].length; i++) {
		for (var j = 0; j < blocks.length; j++) {
			if (i !== shortLen - eccLen || j >= numShort) result.push(blocks[j][i]);
		}
	}
	return result;
}

/* --- Το πλέγμα. --- */
function Grid(ver) {
	this.size = ver * 4 + 17;
	this.dark = [];
	this.fixed = [];
	for (var y = 0; y < this.size; y++) {
		this.dark.push(new Array(this.size).fill(false));
		this.fixed.push(new Array(this.size).fill(false));
	}
}

Grid.prototype.setFixed = function (x, y, on) {
	this.dark[y][x] = on;
	this.fixed[y][x] = true;
};

function alignmentPositions(ver, size) {
	if (ver === 1) return [];
	var n = Math.floor(ver / 7) + 2;
	var step = ver === 32 ? 26 : Math.ceil((ver * 4 + 4) / (n * 2 - 2)) * 2;
	var result = [6];
	for (var pos = size - 7; result.length < n; pos -= step) result.splice(1, 0, pos);
	return result;
}

function drawFinder(g, x, y) {
	for (var dy = -4; dy <= 4; dy++) {
		for (var dx = -4; dx <= 4; dx++) {
			var d = Math.max(Math.abs(dx), Math.abs(dy));
			var xx = x + dx, yy = y + dy;
			if (xx >= 0 && xx < g.size && yy >= 0 && yy < g.size) g.setFixed(xx, yy, d !== 2 && d !== 4);
		}
	}
}

function drawFormat(g, mask) {
	var data = (FORMAT_M << 3) | mask;
	var rem = data;
	for (var i = 0; i < 10; i++) rem = (rem << 1) ^ ((rem >>> 9) * 0x537);
	var bits = ((data << 10) | rem) ^ 0x5412;
	var s = g.size;

	for (i = 0; i <= 5; i++) g.setFixed(8, i, bit(bits, i));
	g.setFixed(8, 7, bit(bits, 6));
	g.setFixed(8, 8, bit(bits, 7));
	g.setFixed(7, 8, bit(bits, 8));
	for (i = 9; i < 15; i++) g.setFixed(14 - i, 8, bit(bits, i));

	for (i = 0; i < 8; i++) g.setFixed(s - 1 - i, 8, bit(bits, i));
	for (i = 8; i < 15; i++) g.setFixed(8, s - 15 + i, bit(bits, i));
	g.setFixed(8, s - 8, true);
}

function drawVersion(g, ver) {
	if (ver < 7) return;
	var rem = ver;
	for (var i = 0; i < 12; i++) rem = (rem << 1) ^ ((rem >>> 11) * 0x1F25);
	var bits = (ver << 12) | rem;
	for (i = 0; i < 18; i++) {
		var on = bit(bits, i);
		var a = g.size - 11 + i % 3;
		var b = Math.floor(i / 3);
		g.setFixed(a, b, on);
		g.setFixed(b, a, on);
	}
}

function drawFunctionPatterns(g, ver) {
	var s = g.size;
	for (var i = 0; i < s; i++) {
		g.setFixed(6, i, i % 2 === 0);
		g.setFixed(i, 6, i % 2 === 0);
	}
	drawFinder(g, 3, 3);
	drawFinder(g, s - 4, 3);
	drawFinder(g, 3, s - 4);

	var pos = alignmentPositions(ver, s);
	var last = pos.length - 1;
	for (i = 0; i < pos.length; i++) {
		for (var j = 0; j < pos.length; j++) {
			if ((i === 0 && j === 0) || (i === 0 && j === last) || (i === last && j === 0)) continue;
			for (var dy = -2; dy <= 2; dy++) {
				for (var dx = -2; dx <= 2; dx++) {
					g.setFixed(pos[i] + dx, pos[j] + dy, Math.max(Math.abs(dx), Math.abs(dy)) !== 1);
				}
			}
		}
	}
	drawFormat(g, 0);
	drawVersion(g, ver);
}

function drawCodewords(g, codewords) {
	var i = 0, s = g.size, total = codewords.length * 8;
	for (var right = s - 1; right >= 1; right -= 2) {
		if (right === 6) right = 5;
		for (var vert = 0; vert < s; vert++) {
			for (var j = 0; j < 2; j++) {
				var x = right - j;
				var upward = ((right + 1) & 2) === 0;
				var y = upward ? s - 1 - vert : vert;
				if (!g.fixed[y][x] && i < total) {
					g.dark[y][x] = bit(codewords[i >>> 3], 7 - (i & 7));
					i++;
				}
			}
		}
	}
}

function maskHit(mask, x, y) {
	switch (mask) {
		case 0: return (x + y) % 2 === 0;
		case 1: return y % 2 === 0;
		case 2: return x % 3 === 0;
		case 3: return (x + y) % 3 === 0;
		case 4: return (Math.floor(x / 3) + Math.floor(y / 2)) % 2 === 0;
		case 5: return (x * y) % 2 + (x * y) % 3 === 0;
		case 6: return ((x * y) % 2 + (x * y) % 3) % 2 === 0;
		default: return ((x + y) % 2 + (x * y) % 3) % 2 === 0;
	}
}

function applyMask(g, mask) {
	for (var y = 0; y < g.size; y++) {
		for (var x = 0; x < g.size; x++) {
			if (!g.fixed[y][x] && maskHit(mask, x, y)) g.dark[y][x] = !g.dark[y][x];
		}
	}
}

/* Ποινή για την επιλογή μάσκας: σειρές ίδιου χρώματος, τετράγωνα 2×2,
 * ισορροπία μαύρου/λευκού. Οποιαδήποτε μάσκα διαβάζεται· αυτή απλώς διαλέγει
 * την πιο «ευανάγνωστη» για την κάμερα. */
function penalty(g) {
	var s = g.size, score = 0, darkCount = 0, x, y;

	function runs(get) {
		for (var a = 0; a < s; a++) {
			var run = 1;
			for (var b = 1; b < s; b++) {
				if (get(a, b) === get(a, b - 1)) {
					run++;
					if (run === 5) score += 3; else if (run > 5) score++;
				} else {
					run = 1;
				}
			}
		}
	}
	runs(function (a, b) { return g.dark[a][b]; });
	runs(function (a, b) { return g.dark[b][a]; });

	for (y = 0; y < s - 1; y++) {
		for (x = 0; x < s - 1; x++) {
			var c = g.dark[y][x];
			if (c === g.dark[y][x + 1] && c === g.dark[y + 1][x] && c === g.dark[y + 1][x + 1]) score += 3;
		}
	}

	for (y = 0; y < s; y++) for (x = 0; x < s; x++) if (g.dark[y][x]) darkCount++;
	var total = s * s;
	score += Math.ceil(Math.abs(darkCount * 20 - total * 10) / total - 1) * 10;
	return score;
}

/**
 * Ο πίνακας του QR: true = σκούρο. null όταν το κείμενο δεν χωρά (≈2.300 bytes).
 *
 * @param {string} text
 * @returns {boolean[][]|null}
 */
export function qrMatrix(text) {
	var bytes = utf8(String(text));
	var ver = pickVersion(bytes.length);
	if (ver < 0) return null;

	var codewords = withEcc(dataBytes(bytes, ver), ver);
	var best = null, bestScore = Infinity;

	for (var mask = 0; mask < 8; mask++) {
		var g = new Grid(ver);
		drawFunctionPatterns(g, ver);
		drawCodewords(g, codewords);
		applyMask(g, mask);
		drawFormat(g, mask);
		var p = penalty(g);
		if (p < bestScore) { bestScore = p; best = g; }
	}
	return best.dark;
}

/**
 * Το QR ως SVG, με το λευκό περιθώριο των 4 κουτιών που θέλει η κάμερα.
 *
 * @param {string} text
 * @returns {string} '' όταν δεν χωρά.
 */
export function qrSvg(text) {
	var m = qrMatrix(text);
	if (!m) return '';
	var border = 4, size = m.length + border * 2, d = '';
	for (var y = 0; y < m.length; y++) {
		for (var x = 0; x < m.length; x++) {
			if (m[y][x]) d += 'M' + (x + border) + ',' + (y + border) + 'h1v1h-1z';
		}
	}
	return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + size + ' ' + size + '" shape-rendering="crispEdges">' +
		'<rect width="100%" height="100%" fill="#fff"/><path d="' + d + '" fill="#000"/></svg>';
}
