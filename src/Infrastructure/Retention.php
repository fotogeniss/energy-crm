<?php

/**
 * Deletes personal data the CRM no longer has a reason to keep.
 *
 * `contracts.extracted_json` holds the raw payload the model returned when
 * reading a customer's ID card and utility bill: name, father's name, ID
 * number, date of birth, full address. Every field of it is already stored,
 * validated, in the customers table. The blob exists so an agent can check
 * what the extraction actually said — useful for days, not for years.
 *
 * GDPR calls this storage limitation: keeping a second copy of identity
 * documents indefinitely, for a purpose that expired, is the kind of thing
 * that turns an ordinary breach into a reportable one.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use EnergyCRM\Persistence\ContractRepository;

final class Retention
{
    public const HOOK = 'ecrm_retention_sweep';

    /** Days an extraction payload is kept before being cleared. */
    private const DEFAULT_DAYS = 90;

    public function __construct(private readonly ContractRepository $contracts)
    {
    }

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'onScheduledSweep']);

        if (! wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::HOOK);
        }
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    /**
     * How long extraction payloads live.
     *
     * Filterable so a company whose retention policy says otherwise can set it
     * without patching the plugin. Zero disables the sweep entirely.
     */
    public function days(): int
    {
        $days = (int) get_option('ecrm_extraction_retention_days', self::DEFAULT_DAYS);

        return max(0, (int) apply_filters('ecrm_extraction_retention_days', $days));
    }

    /**
     * @return int Number of contracts cleared.
     */
    /**
     * Cron entry point. WordPress discards return values, so the count is
     * logged here rather than handed back into a void.
     */
    public function onScheduledSweep(): void
    {
        $cleared = $this->sweep();

        if ($cleared > 0) {
            error_log(sprintf('[Energy CRM] Καθαρίστηκαν δεδομένα εξαγωγής από %d συμβάσεις.', $cleared));
        }
    }

    /**
     * @return int Number of contracts cleared.
     */
    public function sweep(): int
    {
        return $this->contracts->clearExtractionPayloads($this->days());
    }
}
