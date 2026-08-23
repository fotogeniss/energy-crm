import test from 'node:test';
import assert from 'node:assert/strict';
import { scope, setScope } from '../../public/assets/ecrm-scope.js';

// ecrm-scope.js keeps its state in a module-level variable. node --test runs
// each *file* in its own process, so this state does not leak from
// navigate.test.js or format.test.js — but it DOES persist between the
// test() calls below, since they share this one module instance. Every test
// here calls setScope() first so it does not depend on execution order.

test('scope() defaults to "own" before setScope() is ever called', () => {
	// Only valid as the very first assertion in this file/process — see note
	// above. If this test is ever reordered below another that calls
	// setScope(), it will fail for a reason unrelated to a real bug; that is
	// the tradeoff of testing a module with singleton state instead of a
	// factory, and is documented here rather than hidden.
	assert.equal(scope(), 'own');
});

test('setScope("team") switches to team', () => {
	setScope('team');
	assert.equal(scope(), 'team');
});

test('setScope() treats anything other than the literal "team" as "own"', () => {
	setScope('team');
	setScope('bogus');
	assert.equal(scope(), 'own');

	setScope('team');
	setScope(undefined);
	assert.equal(scope(), 'own');

	setScope('team');
	setScope('');
	assert.equal(scope(), 'own');

	setScope('team');
	setScope('OWN'); // wrong case is not "team" either
	assert.equal(scope(), 'own');
});
