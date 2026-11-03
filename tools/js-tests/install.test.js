/* (347) Ποιο κινητό είναι. UA από πραγματικούς browsers. */
import test from 'node:test';
import assert from 'node:assert/strict';
import { installEnv } from '../../public/assets/ecrm-install-env.js';

var IPHONE_SAFARI = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
var IPHONE_CHROME = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/126.0.6478.153 Mobile/15E148 Safari/604.1';
var IPHONE_FIREFOX = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) FxiOS/127.0 Mobile/15E148 Safari/605.1.15';
var IPHONE_VIBER = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Viber/23.0';
var IPHONE_GOOGLE_APP = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) GSA/320.0 Mobile/15E148 Safari/604.1';
var ANDROID_CHROME = 'Mozilla/5.0 (Linux; Android 14; SM-S911B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36';
var DESKTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
var IPADOS = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15';

test('iPhone στο Safari: τα βήματα με το κουμπί κοινοποίησης', () => {
	assert.deepEqual(installEnv({ ua: IPHONE_SAFARI }), { kind: 'ios-safari', os: 'ios' });
});

test('iPhone αλλά όχι Safari: Chrome, Firefox, Google, Viber → ανοίγει στο Safari', () => {
	for (var ua of [IPHONE_CHROME, IPHONE_FIREFOX, IPHONE_GOOGLE_APP, IPHONE_VIBER]) {
		assert.equal(installEnv({ ua: ua }).kind, 'ios-other', ua);
	}
});

test('Android: οδηγία για Android', () => {
	assert.deepEqual(installEnv({ ua: ANDROID_CHROME }), { kind: 'android', os: 'android' });
});

test('υπολογιστής: δείχνει και τα δύο σετ', () => {
	assert.deepEqual(installEnv({ ua: DESKTOP, platform: 'Win32', touchPoints: 0 }), { kind: 'other', os: 'other' });
});

test('iPad που δηλώνεται ως Mac, με αφή, είναι iOS· Mac χωρίς αφή όχι', () => {
	assert.equal(installEnv({ ua: IPADOS, platform: 'MacIntel', touchPoints: 5 }).kind, 'ios-safari');
	assert.equal(installEnv({ ua: IPADOS, platform: 'MacIntel', touchPoints: 0 }).kind, 'other');
});

test('όταν τρέχει ήδη ως εφαρμογή, είναι installed σε κάθε κινητό', () => {
	assert.equal(installEnv({ ua: IPHONE_SAFARI, standalone: true }).kind, 'installed');
	assert.equal(installEnv({ ua: ANDROID_CHROME, standalone: true }).kind, 'installed');
});

test('χωρίς καθόλου δεδομένα δεν σκάει', () => {
	assert.deepEqual(installEnv(), { kind: 'other', os: 'other' });
	assert.deepEqual(installEnv({}), { kind: 'other', os: 'other' });
});
