<?php

/**
 * Caps how many document extractions run at the same time.
 *
 * The call to the model is allowed sixty seconds, and it holds a PHP worker for
 * every one of them. With `pm.max_children` around thirty — a normal setting —
 * forty agents pressing "Εξαγωγή" together take every worker on the box, and
 * the whole site stops answering: the list, the dashboard, the login page.
 *
 * The obvious fix is a background queue, but it costs something this endpoint
 * deliberately has: the uploads never touch disk. They are read from the
 * temporary files PHP already holds and forgotten, which is why an abandoned
 * extraction leaves no identity documents behind. Queueing means writing them
 * somewhere and trusting a cleanup.
 *
 * So the queue stays in the browser, which is already holding the files. A
 * request that cannot get a slot is told to come back, and the agent's screen
 * says "Στη σειρά" instead of showing an error.
 *
 * Slots are MySQL named locks: taken without waiting, and released by the
 * server the moment the connection ends. A request that dies mid-extraction
 * therefore cannot leak a slot, which a counter in an option would.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

final class ExtractionGate
{
    /**
     * How many extractions may be in flight across the whole site.
     *
     * Four leaves room on a thirty-worker box for everything else, and four
     * agents waiting two minutes beats forty agents waiting for a site that
     * never answers. Raise it only alongside `pm.max_children`.
     */
    private const SLOTS = 4;

    private const PREFIX = 'ecrm_extract_slot_';

    private ?string $held = null;

    /**
     * Take a slot, or return false when all of them are busy.
     */
    public function enter(): bool
    {
        global $wpdb;

        for ($slot = 1; $slot <= self::SLOTS; $slot++) {
            $name = $this->name($slot);

            // Zero timeout: never queue inside the request. Either a slot is
            // free now or the caller is told to come back.
            //
            // Not a table read, so the sniff's advice does not apply: GET_LOCK
            // asks the server for exclusive use of a name, and a cached answer
            // would hand the same slot to two requests — the one thing this
            // class exists to prevent.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $taken = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name));

            if ((int) $taken === 1) {
                $this->held = $name;

                return true;
            }
        }

        return false;
    }

    /**
     * Give the slot back.
     *
     * Safe to call when nothing was taken, and safe not to call at all — the
     * lock dies with the connection. This just returns it sooner.
     */
    public function leave(): void
    {
        global $wpdb;

        if ($this->held === null) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $this->held));
        $this->held = null;
    }

    /**
     * Seconds the client should wait before trying again.
     *
     * Long enough not to spin, short enough that a freed slot is picked up
     * quickly. Constant rather than computed: the honest answer depends on how
     * far along the other extractions are, which nothing here can see.
     */
    public function retryAfter(): int
    {
        return 8;
    }

    /**
     * Lock names are per database, so two sites in one MySQL would otherwise
     * share the same four slots.
     */
    private function name(int $slot): string
    {
        global $wpdb;

        return self::PREFIX . $wpdb->dbname . '_' . $slot;
    }
}
