import test from 'node:test';
import assert from 'node:assert/strict';
import { analyse, checkPhoto, LIMITS } from '../../public/assets/ecrm-photo-check.js';

/* Συνθετική «σελίδα»: μαύρες γραμμές κειμένου σε γκρι-λευκό χαρτί. */
function page(w, h, { blur = 0, ink = 20, paper = 200 } = {}) {
	var g = new Float32Array(w * h).fill(paper);
	for (var y = 10; y < h - 10; y += 8) {
		for (var x = 10; x < w - 10; x++) {
			if ((x % 13) < 9) { g[y * w + x] = ink; g[(y + 1) * w + x] = ink; }
		}
	}
	for (var pass = 0; pass < blur; pass++) {
		var o = new Float32Array(g);
		for (var yy = 1; yy < h - 1; yy++) {
			for (var xx = 1; xx < w - 1; xx++) {
				var k = yy * w + xx;
				o[k] = (g[k] * 4 + g[k - 1] + g[k + 1] + g[k - w] + g[k + w] + g[k - w - 1] + g[k - w + 1] + g[k + w - 1] + g[k + w + 1]) / 12;
			}
		}
		g = o;
	}
	var rgba = new Uint8ClampedArray(w * h * 4);
	for (var i = 0; i < w * h; i++) { rgba[i * 4] = rgba[i * 4 + 1] = rgba[i * 4 + 2] = g[i]; rgba[i * 4 + 3] = 255; }
	return rgba;
}

function codes(rgba, w, h, ow, oh) {
	return analyse(rgba, w, h, ow || 3000, oh || 4000).problems.map(function (p) { return p.code; });
}

test('a sharp page passes', () => {
	assert.deepEqual(codes(page(300, 400), 300, 400), []);
});

test('a blurred page is "blur"', () => {
	assert.deepEqual(codes(page(300, 400, { blur: 40 }), 300, 400), ['blur']);
});

test('dim but sharp is not blur: the metric does not depend on light', () => {
	assert.deepEqual(codes(page(300, 400, { ink: 10, paper: 100 }), 300, 400), []);
});

test('a dark photo is "dark", and only that', () => {
	assert.deepEqual(codes(page(300, 400, { ink: 5, paper: 50 }), 300, 400), ['dark']);
});

test('a small original is "small", whatever the analysed size', () => {
	assert.deepEqual(codes(page(300, 400), 300, 400, 600, LIMITS.small - 1), ['small']);
});

test('an empty frame (no contrast) counts as blur', () => {
	assert.deepEqual(codes(new Uint8ClampedArray(100 * 100 * 4).fill(180), 100, 100), ['blur']);
});

test('every problem carries a Greek message', () => {
	analyse(page(300, 400, { blur: 40 }), 300, 400, 500, 500).problems.forEach(function (p) {
		assert.ok(p.text.length > 10, p.code);
	});
});

test('checkPhoto() never blocks a PDF or a missing file', async () => {
	assert.deepEqual(await checkPhoto(null), { checked: false, problems: [] });
	assert.deepEqual(await checkPhoto({ type: 'application/pdf' }), { checked: false, problems: [] });
});
