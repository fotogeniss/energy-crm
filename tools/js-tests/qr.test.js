/* (344) Το QR της αφίσας.
 *
 * Οι δύο «αποτυπώματα» πάρθηκαν από πίνακες που αποκωδικοποιήθηκαν σωστά
 * με OpenCV (cv2.QRCodeDetector) στις 31/10/2026, μαζί με ελληνικό κείμενο,
 * 150, 400 και 1.000 χαρακτήρες (εκδόσεις 1 έως 26). Αν αλλάξει ο κώδικας
 * και το αποτύπωμα, ξαναελέγχεις με αποκωδικοποιητή πριν το αλλάξεις εδώ. */
import test from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { qrMatrix, qrSvg } from '../../public/assets/ecrm-qr.js';

function print(m) {
	return createHash('sha256').update(m.map((r) => r.map((v) => (v ? 1 : 0)).join('')).join('\n')).digest('hex');
}

test('ο σύνδεσμος της αφίσας βγαίνει ίδιος με αυτόν που διαβάστηκε', () => {
	var m = qrMatrix('https://crm.example.gr/?ecrm_intake=12-a8f3c91d0b7e4f2a91cd&via=qr');
	assert.equal(m.length, 37);
	assert.equal(print(m), 'cdc6550bb128a7b1c7cb780ca37824ac70ceb7685b5043870e3cc40415f40d2a');
});

test('μικρό κείμενο: έκδοση 1, 21×21', () => {
	var m = qrMatrix('HELLO');
	assert.equal(m.length, 21);
	assert.equal(print(m), '71bc9ab3ffcaa0d281e4873d98c839634768a360dd4020111d405a3c41752367');
});

test('τα τρία τετράγωνα εντοπισμού είναι στις γωνίες', () => {
	var m = qrMatrix('HELLO'), s = m.length;
	for (var [x, y] of [[0, 0], [s - 7, 0], [0, s - 7]]) {
		assert.equal(m[y][x], true);
		assert.equal(m[y + 1][x + 1], false);
		assert.equal(m[y + 3][x + 3], true);
	}
});

test('κείμενο που δεν χωρά: null και κενό SVG, όχι σφάλμα', () => {
	assert.equal(qrMatrix('x'.repeat(5000)), null);
	assert.equal(qrSvg('x'.repeat(5000)), '');
});

test('το SVG έχει λευκό περιθώριο 4 κουτιών', () => {
	assert.match(qrSvg('HELLO'), /viewBox="0 0 29 29"/);
});
