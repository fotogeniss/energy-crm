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
 * @type {{ go?: Function, openDetail?: Function, openEdit?: Function }}
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
