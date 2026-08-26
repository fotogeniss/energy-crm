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
    public const CONTRACTS          = 'contracts';
    public const CUSTOMERS          = 'customers';
    public const EVENTS             = 'events';
    public const FILES              = 'files';
    public const KB_ENTRIES         = 'kb_entries';
    public const LEADS              = 'leads';
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
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::ASSISTANT_MESSAGES,
            self::PROVIDERS,
            self::PROGRAMS,
            self::CUSTOMERS,
            self::CONTRACTS,
            self::FILES,
            self::EVENTS,
            self::SIGNATURES,
            self::COMMISSION_RULES,
            self::PAYOUTS,
            self::TASKS,
            self::LEADS,
            self::KB_ENTRIES,
            self::NOTIFICATIONS,
        ];
    }

    public static function name(string $table): string
    {
        global $wpdb;

        return $wpdb->prefix . 'ecrm_' . $table;
    }
}
