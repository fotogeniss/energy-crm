<?php

/**
 * Converts the rows that predate encryption, a slice at a time.
 *
 * ## Why this is not a migration
 *
 * HANDOVER §6.0 asked for one, and a migration is the wrong shape. Migrations
 * are recorded the first time they run and never run again. This work is only
 * meaningful while ECRM_ENCRYPT_PII is on — which is not the default — so a
 * migration would run on a site with the flag off, do nothing, record itself as
 * complete, and never fire again after the owner switched encryption on. A
 * silent no-op is the failure mode this codebase has already paid for three
 * times: comments inside dbDelta, columns too narrow for ciphertext, and a
 * FieldCipher that stored plaintext when sodium was missing.
 *
 * So it lives where DocumentProtection lives, in the same shape: an hourly
 * sweep with a batch size, a pending count for the screen, and no memory of
 * having "finished" beyond what the data itself says.
 *
 * ## Interrupted is a normal state
 *
 * Nothing here needs a transaction. encrypt() refuses to encrypt twice and
 * decrypt() passes plaintext through untouched, so a table caught halfway is
 * fully readable and the next tick simply carries on. Anyone tempted to add a
 * lock would be adding risk to buy nothing.
 *
 * ## What it refuses to do
 *
 * Running with the flag off would advance the contracts cursor over rows that
 * were never encrypted and will never be looked at again — the walk assumes
 * every write below the cursor already stores ciphertext. Running without
 * sodium cannot encrypt at all. Both stop the sweep rather than half-perform
 * it.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use EnergyCRM\Persistence\CustomerFields;
use EnergyCRM\Persistence\PiiBackfillRepository;

final class PiiBackfill
{
    public const HOOK = 'ecrm_backfill_pii';

    /**
     * Rows per tick, per table.
     *
     * Each row is a read, a libsodium call per column and one UPDATE — cheaper
     * than the file copies DocumentProtection does at 25, so this can afford
     * more. A hundred an hour clears a few thousand customers in a couple of
     * days without ever being visible next to the twenty to forty concurrent
     * requests the site serves.
     */
    private const BATCH = 100;

    public function __construct(private readonly PiiBackfillRepository $rows)
    {
    }

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'onScheduledSweep']);

        if (! wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'hourly', self::HOOK);
        }
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    /**
     * Whether a sweep is allowed to touch anything at all.
     *
     * Reported rather than assumed, so the admin screen can say which of the
     * two is missing instead of showing a button that quietly does nothing.
     */
    public function blockedReason(): ?string
    {
        if (! FieldCipher::isAvailable()) {
            return 'Λείπει η επέκταση sodium — αυτή η PHP δεν μπορεί να κρυπτογραφήσει.';
        }

        if (! CustomerFields::isEnabled()) {
            return 'Το ECRM_ENCRYPT_PII είναι κλειστό. Χωρίς αυτό, οι νέες εγγραφές '
                . 'θα έμπαιναν σε καθαρό κείμενο πίσω από τον δείκτη και δεν θα ξαναδιαβάζονταν ποτέ.';
        }

        // The sweep refuses itself rather than trusting anyone to have read the
        // instruction not to run it. Under a rotated key it is the single most
        // destructive thing on the site: it walks every row on purpose, and
        // what it would write is the blanks the wrong key is already reading.
        if (! KeyFingerprint::default()->matches()) {
            return 'Το κλειδί κρυπτογράφησης δεν είναι αυτό που έγραψε τα δεδομένα. '
                . 'Το backfill ΔΕΝ πρέπει να τρέξει — θα έκανε μόνιμη την απώλεια. '
                . 'Επανάφερε το παλιό SECURE_AUTH_SALT· δες docs/BACKUP.md.';
        }

        return null;
    }

    /**
     * How much is left, as {customers, contracts}.
     *
     * The customers figure is exact. The contracts figure is how many rows the
     * walk has not reached yet, not how many still hold plaintext — nothing can
     * know the latter without opening every bag, and a count that costs a table
     * scan per page load is not worth the precision.
     *
     * @return array{customers:int, contracts:int}
     */
    public function pending(): array
    {
        return [
            'customers' => $this->rows->pendingCustomers(),
            'contracts' => max(0, $this->rows->highestContractId() - $this->rows->cursor()),
        ];
    }

    /**
     * One slice of each table.
     *
     * @return array{customers:int, contracts:int, blocked:?string}
     */
    public function sweep(int $limit = self::BATCH): array
    {
        $blocked = $this->blockedReason();

        if ($blocked !== null) {
            return ['customers' => 0, 'contracts' => 0, 'blocked' => $blocked];
        }

        return [
            'customers' => $this->sweepCustomers($limit),
            'contracts' => $this->sweepContracts($limit),
            'blocked'   => null,
        ];
    }

    private function sweepCustomers(int $limit): int
    {
        $done = 0;

        foreach ($this->rows->customersToDo($limit) as $row) {
            if ($this->rows->encryptCustomer($row)) {
                $done++;
            }
        }

        return $done;
    }

    /**
     * The contracts walk.
     *
     * The cursor advances past every row it reads, including the ones with
     * nothing to encrypt. Advancing only on change would stall the walk on the
     * first contract with an empty bag, and every tick afterwards would re-read
     * the same row forever.
     */
    private function sweepContracts(int $limit): int
    {
        $rows = $this->rows->contractsAfter($this->rows->cursor(), $limit);

        if ($rows === []) {
            return 0;
        }

        $done = 0;

        foreach ($rows as $row) {
            if ($this->rows->encryptContractExtras($row)) {
                $done++;
            }
        }

        $this->rows->moveCursor((int) end($rows)['id']);

        return $done;
    }

    /**
     * Cron entry point. WordPress discards return values, so anything worth
     * knowing is logged.
     *
     * The blocked case is logged once per tick on purpose: a site with the flag
     * off is the normal case and does not deserve an hourly complaint, but a
     * site where somebody switched it on and sodium is missing needs to say so
     * somewhere other than a screen nobody opens.
     */
    public function onScheduledSweep(): void
    {
        Heartbeat::mark(self::HOOK);

        $report = $this->sweep();

        if ($report['blocked'] !== null) {
            if (CustomerFields::isEnabled()) {
                error_log('[Energy CRM] Το backfill κρυπτογράφησης δεν έτρεξε: ' . $report['blocked']);
            }

            return;
        }

        if ($report['customers'] > 0 || $report['contracts'] > 0) {
            error_log(
                sprintf(
                    '[Energy CRM] Backfill κρυπτογράφησης: %d πελάτες, %d συμβόλαια.',
                    $report['customers'],
                    $report['contracts']
                )
            );
        }
    }
}
