import test from 'node:test';
import assert from 'node:assert/strict';

// (318) Η αρχική προβολή διαβάζεται από το window.ECRM τη στιγμή που φορτώνει
// το module. Κάθε περίπτωση φορτώνει ΔΙΚΟ της αντίγραφο (διαφορετικό ?query
// = διαφορετικό module στο ESM), αφού πρώτα στήσει το window που θέλει.
async function freshScope(config, tag) {
	globalThis.window = { ECRM: config };
	try {
		return (await import('../../public/assets/ecrm-scope.js?' + tag)).scope();
	} finally {
		delete globalThis.window;
	}
}

test('Back office (ecrm_view_all, όχι admin) ξεκινά από «Ομάδας»', async () => {
	assert.equal(await freshScope({ caps: { ecrm_view_all: true }, isAdmin: false }, 'bo'), 'team');
});

test('ο διαχειριστής κρατά την προεπιλογή «Δικά μου»', async () => {
	assert.equal(await freshScope({ caps: { ecrm_view_all: true }, isAdmin: true }, 'admin'), 'own');
});

test('συνεργάτης χωρίς ecrm_view_all ξεκινά από «Δικά μου»', async () => {
	assert.equal(await freshScope({ caps: { ecrm_manage_team: true }, isAdmin: false }, 'partner'), 'own');
});

test('χωρίς window.ECRM (σελίδα χωρίς config) ξεκινά από «Δικά μου»', async () => {
	assert.equal(await freshScope(undefined, 'none'), 'own');
});
