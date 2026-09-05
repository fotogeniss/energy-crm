/* Energy CRM — the shell's navigation, offered to the views without a cycle.
 *
 * Five views need to send the user somewhere: renewals opens a contract for
 * editing, pending and tasks open a contract's detail, leads does both, and
 * the notification bell opens whatever it just announced. All three functions
 * live in the app shell — and the shell already imports every view, so a view
 * importing them straight back would close a loop. ES modules tolerate cycles;
 * people reading them do not.
 *
 * So the direction of every import stays one way. This module imports nothing
 * at all. The shell hands its functions in once at startup with wire(); the
 * views ask for them by name and never learn where they came from.
 *
 * The indirection buys one more thing worth having: a view can now be opened
 * in a test, or on a page where the shell was never booted, and calling
 * openDetail() does nothing instead of throwing.
 */

/**
 * @type {{ go?: Function, openDetail?: Function, openEdit?: Function, openPartner?: Function, openCustomerCard?: Function }}
 */
var handlers = {};

/**
 * Called once by the app shell, after its own functions exist.
 *
 * Not merged with what is already there: a second call replaces the lot, so a
 * shell booted twice on one page cannot leave half the previous one wired in.
 */
export function wire(next) {
	handlers = next || {};
}

/** Switch to a top-level view by its data-view name. */
export function go(view) {
	if (typeof handlers.go === 'function') {
		handlers.go(view);
	}
}

/** Open one contract's detail screen. */
export function openDetail(contractId) {
	if (typeof handlers.openDetail === 'function') {
		handlers.openDetail(contractId);
	}
}

/**
 * Open one team member's card.
 *
 * Περνά από εδώ και όχι με απευθείας import από την «Η ομάδα μου» για τον ίδιο
 * λόγο που περνά και το openDetail: το κέλυφος εισάγει ήδη κάθε όψη, οπότε μια
 * όψη που εισάγει άλλη αρχίζει να πλέκει γράφο που κανείς δεν κρατά στο μυαλό
 * του. Η κατεύθυνση κάθε import μένει μονόδρομη.
 */
export function openPartner(memberId) {
	if (typeof handlers.openPartner === 'function') {
		handlers.openPartner(memberId);
	}
}

/**
 * Open one customer's card (247, Στάδιο 1).
 *
 * Ιδιος λόγος με το openPartner: η λίστα «Πελάτες» δεν εισάγει απευθείας
 * την όψη της καρτέλας, περνά από εδώ, ώστε το import να μείνει μονόδρομο.
 */
export function openCustomerCard(customerId) {
	if (typeof handlers.openCustomerCard === 'function') {
		handlers.openCustomerCard(customerId);
	}
}

/**
 * Open the contracts list, filtered down to one ΑΦΜ.
 *
 * Build queue 08, 25/08. There is no dedicated customer detail screen, so
 * "open the existing record" for the new-contract form's match card means:
 * the contracts list, searched to exactly the rows for this ΑΦΜ. Takes the
 * query rather than a customer id because /contracts search already knows
 * how to match ΑΦΜ text — no new endpoint needed for this button.
 */
export function openCustomerContracts(query) {
	if (typeof handlers.openCustomerContracts === 'function') {
		handlers.openCustomerContracts(query);
	}
}

/**
 * Open the new-contract form prefilled from an existing contract.
 *
 * Takes the whole row rather than an id, because that is what the form's
 * edit() wants and the caller already has it — fetching it again to hand back
 * the same fields would be a round trip for nothing.
 */
export function openEdit(contract) {
	if (typeof handlers.openEdit === 'function') {
		handlers.openEdit(contract);
	}
}
