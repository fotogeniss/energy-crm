import test from 'node:test';
import assert from 'node:assert/strict';
import { wire, go, openDetail, openPartner, openEdit } from '../../public/assets/ecrm-navigate.js';

test('before wire() is called, every function is a safe no-op', () => {
	// Fresh process (node --test isolates files), so handlers = {} still holds
	// here. Must not throw.
	assert.doesNotThrow(() => go('renewals'));
	assert.doesNotThrow(() => openDetail(1));
	assert.doesNotThrow(() => openPartner(1));
	assert.doesNotThrow(() => openEdit({ id: 1 }));
});

test('wire() connects each named handler and passes its argument through', () => {
	var calls = {};
	wire({
		go: (view) => { calls.go = view; },
		openDetail: (id) => { calls.openDetail = id; },
		openPartner: (id) => { calls.openPartner = id; },
		openEdit: (contract) => { calls.openEdit = contract; }
	});

	go('pending');
	openDetail(42);
	openPartner(7);
	var row = { id: 9, energy_type: 'gas' };
	openEdit(row);

	assert.equal(calls.go, 'pending');
	assert.equal(calls.openDetail, 42);
	assert.equal(calls.openPartner, 7);
	assert.equal(calls.openEdit, row);
});

test('wire() REPLACES the handler set rather than merging into it', () => {
	// Regression test for the documented design decision: "a shell booted
	// twice on one page cannot leave half the previous one wired in." If wire()
	// ever changes to merge (Object.assign(handlers, next) instead of
	// handlers = next), this test is the one that catches it — a partial
	// second wire() call would otherwise leave the FIRST call's openDetail
	// still reachable, silently.
	var firstCalls = { openDetail: 0 };
	wire({
		go: () => {},
		openDetail: () => { firstCalls.openDetail++; }
	});

	// Second, partial wire() — only supplies "go".
	wire({ go: () => {} });

	// openDetail must now be a no-op again, NOT still the first wire()'s handler.
	assert.doesNotThrow(() => openDetail(1));
	assert.equal(firstCalls.openDetail, 0, 'openDetail from the first wire() call must not still be reachable after a second wire()');
});

test('a handler missing from the wired set stays a no-op even when others are wired', () => {
	wire({ go: () => { throw new Error('go should not be called by this test'); } });
	assert.doesNotThrow(() => openDetail(1));
	assert.doesNotThrow(() => openPartner(1));
	assert.doesNotThrow(() => openEdit({}));
});
