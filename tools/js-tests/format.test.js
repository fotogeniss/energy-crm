import test from 'node:test';
import assert from 'node:assert/strict';
import { up, energyLabel, fmtDate, timeAgo, minutesAgo, initials, tint, svgIcon, contractStages, dueBucket, dueClock } from '../../public/assets/ecrm-format.js';

test('up() strips Greek accents and uppercases', () => {
	assert.equal(up('Γεώργιος'), 'ΓΕΩΡΓΙΟΣ');
	assert.equal(up('αθήνα'), 'ΑΘΗΝΑ');
});

test('up() falls back to plain toUpperCase on non-string-ish input without throwing', () => {
	// String(null) === 'null' — toLocaleUpperCase('el') on that should not throw,
	// but the point of the try/catch is that up() never throws regardless.
	assert.doesNotThrow(() => up(null));
	assert.equal(up(null), 'NULL');
});

test('energyLabel() maps known types and defaults to electricity', () => {
	assert.equal(energyLabel('gas'), 'Φυσικό Αέριο');
	assert.equal(energyLabel('mobile'), 'Κινητή Τηλεφωνία');
	assert.equal(energyLabel('electricity'), 'Ηλεκτρισμός');
	assert.equal(energyLabel('anything-else'), 'Ηλεκτρισμός');
	assert.equal(energyLabel(undefined), 'Ηλεκτρισμός');
});

test('fmtDate() returns empty string for falsy input', () => {
	assert.equal(fmtDate(''), '');
	assert.equal(fmtDate(null), '');
	assert.equal(fmtDate(undefined), '');
});

test('fmtDate() reads "YYYY-MM-DD HH:MM:SS" as LOCAL time (no Z appended), zero-padded', () => {
	// This is the exact behaviour the 22/08 fix depends on: no '+ Z'. If a future
	// edit reintroduces '+ Z' this test does not change value (fmtDate() only
	// reads the date part), so it does NOT catch a regression there — see
	// timeAgo() below, which is TZ-sensitive and does catch it indirectly via
	// its non-negativity property.
	assert.equal(fmtDate('2026-01-05 09:00:00'), '05/01/2026');
	assert.equal(fmtDate('2026-12-31 23:59:59'), '31/12/2026');
});

test('timeAgo() returns empty string for falsy input', () => {
	assert.equal(timeAgo(''), '');
	assert.equal(timeAgo(null), '');
});

test('timeAgo() treats "now" (formatted with no Z, local wall-clock) as non-negative', () => {
	// Regression guard for the exact bug fixed in (84)/(89): appending 'Z' to a
	// value that is already local time makes "now" look like it happened hours
	// in the future relative to Date.now(), so (Date.now() - d) goes negative
	// and floors to 'μόλις τώρα' regardless of how long ago it really was. This
	// test freezes a timestamp formatted the SAME way fmtDate()/timeAgo() expect
	// (no 'Z', space separator) using the CURRENT local wall-clock, and asserts
	// the function reports something in the "just now" bucket — not a bucket
	// that would only make sense if the value were secretly hours off.
	var now = new Date();
	var pad = function (n) { return (n < 10 ? '0' : '') + n; };
	var localNoZ = now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate())
		+ ' ' + pad(now.getHours()) + ':' + pad(now.getMinutes()) + ':' + pad(now.getSeconds());

	assert.equal(timeAgo(localNoZ), 'μόλις τώρα');
});

test('initials() takes first letter of first two words, uppercased', () => {
	assert.equal(initials('Γιώργος Παπαδόπουλος'), 'ΓΠ');
	assert.equal(initials('  Νίκος   Δ.  '), 'ΝΔ');
});

test('initials() falls back to a single letter, then "?", for sparse input', () => {
	assert.equal(initials('Μαρία'), 'Μ');
	assert.equal(initials(''), '?');
	assert.equal(initials(undefined), '?');
});

test('tint() is a deterministic rolling hash mod 360', () => {
	// h = (h*31 + charCode) % 360, starting from 0.
	assert.equal(tint('A'), 65 % 360);
	assert.equal(tint(''), 0);
	assert.equal(tint(undefined), 0);
	// Same input, same output — called twice.
	assert.equal(tint('Παπαδόπουλος'), tint('Παπαδόπουλος'));
});

test('tint() stays within [0, 360)', () => {
	var samples = ['a', 'Ζ', 'ολόκληρο ένα όνομα με πολλά γράμματα', '0123456789'];
	for (var s of samples) {
		var h = tint(s);
		assert.ok(h >= 0 && h < 360, `tint(${JSON.stringify(s)}) = ${h} out of range`);
	}
});

test('svgIcon() returns markup only for known names, empty inner content otherwise', () => {
	assert.match(svgIcon('phone'), /<svg[^>]*class="ecrm-bico"/);
	assert.match(svgIcon('phone'), /<path/);
	assert.equal(svgIcon('does-not-exist'), '<svg class="ecrm-bico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"></svg>');
});

test('minutesAgo() reads local time like timeAgo, and null for nothing or junk', () => {
	const p = (n) => (n < 10 ? '0' : '') + n;
	const d = new Date(Date.now() - 5 * 60000);
	const local = d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
	assert.equal(minutesAgo(local), 5);
	assert.equal(minutesAgo(''), null);
	assert.equal(minutesAgo('όχι ημερομηνία'), null);
});

test('contractStages() reads facts, not only the status name', () => {
	const states = (c) => contractStages(c).map((s) => s.state).join(',');
	assert.equal(states({ status: 'draft' }), 'now,next,next,next,next');
	assert.equal(states({ status: 'awaiting_signature', doc_checklist: { items: [{ ok: true }], complete: true } }), 'ok,ok,now,next,next');
	assert.equal(states({ status: 'awaiting_signature', signed_at: '2026-11-11 10:00:00', doc_checklist: { items: [{ ok: false }], complete: false } }), 'ok,now,ok,next,next');
	assert.equal(states({ status: 'to_finalisation', files: [{}] }), 'ok,ok,ok,now,next');
	assert.equal(states({ status: 'pending_issue', files: [{}] }), 'ok,ok,ok,warn,next');
	assert.equal(states({ status: 'active' }), 'ok,ok,ok,ok,ok');
	assert.equal(states({ status: 'cancelled', files: [{}] }), 'ok,ok,stop,next,next');
	assert.equal(states({ status: 'registration', files: [{}], signatures: { required: ['mobile', 'energy'], collected: ['mobile'] } }), 'ok,ok,now,next,next');
	assert.deepEqual(contractStages({}).map((s) => s.label), ['Πρόχειρο', 'Χαρτιά', 'Υπογραφή', 'Πάροχος', 'Ενεργή']);
});

test('dueBucket() groups by local day; overdue and done come from the row', () => {
	const now = new Date(2026, 10, 11, 9, 0, 0); // Τετ 11/11/2026 09:00
	assert.equal(dueBucket({ status: 'done', due_at: '2026-11-11 10:00:00' }, now), 'done');
	assert.equal(dueBucket({ overdue: true, due_at: '2026-11-09 10:00:00' }, now), 'overdue');
	assert.equal(dueBucket({ due_at: '2026-11-11 23:00:00' }, now), 'today');
	assert.equal(dueBucket({ due_at: '2026-11-11 08:00:00' }, now), 'today');
	assert.equal(dueBucket({ due_at: '2026-11-12 08:00:00' }, now), 'tomorrow');
	assert.equal(dueBucket({ due_at: '2026-11-17 08:00:00' }, now), 'week');
	assert.equal(dueBucket({ due_at: '2026-11-18 08:00:00' }, now), 'later');
	assert.equal(dueBucket({ due_at: '' }, now), 'none');
	assert.equal(dueBucket({}, now), 'none');
});

test('dueClock() cuts the local time without a time zone', () => {
	assert.equal(dueClock('2026-11-12 14:30:00'), '14:30');
	assert.equal(dueClock('2026-11-12 14:30:00', true), '12/11 14:30');
	assert.equal(dueClock(''), '');
	assert.equal(dueClock(null, true), '');
});
