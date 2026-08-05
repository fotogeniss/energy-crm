<?php

/**
 * Rescues the meter address agents have been typing into nowhere.
 *
 * The form has had a "Διεύθυνση Μετρητή" section for a long time, complete with
 * a sync checkbox. Its five fields were marked as extras, so they were stored
 * as JSON in extra_json and never read again — no provider form printed them,
 * because ECRM_FormFill filled the supply address from the customer's home
 * address instead. Everything typed there was silently thrown away.
 *
 * This moves it into the real columns. Where the recovered address differs from
 * the customer's own, the "same as home" flag is cleared: the agent typed it
 * precisely because it was different, and that intent is the thing to preserve.
 *
 * The extras bag is left untouched. It costs a little storage and it is the
 * only copy of the original if this reading turns out wrong.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Domain\Customer\PostalAddress;
use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class MoveMeterAddressOutOfExtras implements Migration
{
    /** Extras key => the column part it becomes. */
    private const MAPPING = [
        'meter_street'    => 'street',
        'meter_street_no' => 'street_no',
        'meter_city'      => 'city',
        'meter_postal'    => 'postal_code',
        'meter_region'    => 'region',
    ];

    public function id(): string
    {
        return '0006_move_meter_address_out_of_extras';
    }

    public function description(): string
    {
        return 'Μεταφορά της διεύθυνσης μετρητή από το extra_json σε πραγματικές στήλες';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $contracts = Tables::name(Tables::CONTRACTS);
        $customers = Tables::name(Tables::CUSTOMERS);

        if (! $schema->hasTable($contracts) || ! $schema->hasColumn($contracts, 'supply_street')) {
            return;
        }

        // Only rows that actually mention a meter address; on a large table
        // this is the difference between a few rows and all of them.
        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT c.id, c.extra_json, cu.street, cu.street_no, cu.city,
                        cu.postal_code, cu.region
                 FROM %i c LEFT JOIN %i cu ON cu.id = c.customer_id
                 WHERE c.extra_json LIKE %s',
                [$contracts, $customers, '%meter_street%']
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        foreach ((array) $rows as $row) {
            $recovered = $this->recover((string) ($row['extra_json'] ?? ''));

            if ($recovered === null) {
                continue;
            }

            $home = PostalAddress::fromRow($row);

            $wpdb->update(
                $contracts,
                ['supply_addr_same' => $this->matches($recovered, $home) ? 1 : 0]
                    + $recovered->toColumns('supply_'),
                ['id' => (int) $row['id']]
            );
        }
    }

    /** The meter address held in an extras bag, or null when there is none. */
    private function recover(string $extras): ?PostalAddress
    {
        $bag = json_decode($extras, true);

        if (! is_array($bag)) {
            return null;
        }

        $parts = [];

        foreach (self::MAPPING as $key => $part) {
            $parts[$part] = is_scalar($bag[$key] ?? null) ? trim((string) $bag[$key]) : '';
        }

        $address = PostalAddress::fromRow($parts);

        return $address->isEmpty() ? null : $address;
    }

    /**
     * Same street and number, same town — enough to call it the home address.
     *
     * The region is left out of the comparison on purpose: it is the field
     * agents most often skip, and a missing νομός should not turn an identical
     * address into a different one.
     */
    private function matches(PostalAddress $a, PostalAddress $b): bool
    {
        $key = static fn (PostalAddress $x): string => mb_strtolower(implode('|', [
            preg_replace('/\s+/u', ' ', trim($x->street)),
            trim($x->streetNo),
            preg_replace('/\s+/u', ' ', trim($x->city)),
            trim($x->postalCode),
        ]));

        return $key($a) === $key($b);
    }
}
