<?php

/**
 * Table-name resolution in one place.
 *
 * Table names are interpolated into SQL, never bound as parameters, so they
 * must come from a closed set that no request can influence.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

final class Tables
{
    public const ASSISTANT_MESSAGES = 'assistant_messages';
    public const COMMISSION_RULES   = 'commission_rules';
    public const DELETION_LOG       = 'deletion_log';
    public const CONTRACTS          = 'contracts';
    public const CUSTOMERS          = 'customers';
    public const CUSTOMER_NOTES     = 'customer_notes';
    public const EVENTS             = 'events';
    public const FILES              = 'files';
    public const GUARANTEE_RULES    = 'guarantee_rules';
    public const KB_ENTRIES         = 'kb_entries';
    public const KB_READ            = 'kb_read';
    public const LEADS              = 'leads';
    public const METRICS            = 'metrics';
    public const NOTIFICATIONS      = 'notifications';
    public const PAYOUTS            = 'payouts';
    public const PROGRAMS           = 'programs';
    public const PROVIDERS          = 'providers';
    public const SIGNATURES         = 'signatures';
    public const TASKS              = 'tasks';

    private function __construct()
    {
    }

    /**
     * Every table the plugin owns, unprefixed.
     *
     * AUDIT 29/08: `kb_read` (dbDelta, class-ecrm-db.php) was missing from
     * this list -- the one place HealthChecks and EnsureInnoDb (0003) ask
     * "which tables exist?". It was invisible to both: the health screen
     * said "14 από 14" while a 15th table quietly existed, and a table lost
     * entirely would never have tripped the one check meant to notice.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::ASSISTANT_MESSAGES,
            self::PROVIDERS,
            self::PROGRAMS,
            self::CUSTOMERS,
            self::CUSTOMER_NOTES,
            self::CONTRACTS,
            self::FILES,
            self::EVENTS,
            self::SIGNATURES,
            self::COMMISSION_RULES,
            self::GUARANTEE_RULES,
            self::PAYOUTS,
            self::TASKS,
            self::LEADS,
            self::KB_ENTRIES,
            self::KB_READ,
            self::METRICS,
            self::NOTIFICATIONS,
            self::DELETION_LOG,
        ];
    }

    public static function name(string $table): string
    {
        global $wpdb;

        return $wpdb->prefix . 'ecrm_' . $table;
    }
}
