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
    public const CONTRACTS = 'contracts';
    public const CUSTOMERS = 'customers';
    public const EVENTS    = 'events';
    public const FILES     = 'files';

    private function __construct()
    {
    }

    public static function name(string $table): string
    {
        global $wpdb;

        return $wpdb->prefix . 'ecrm_' . $table;
    }
}
