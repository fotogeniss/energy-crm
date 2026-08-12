<?php

/**
 * Which contract columns a caller is allowed to write, and nothing else.
 *
 * The list and the check were private to ContractRepository until the status
 * transition moved out and needed the same guard. Copying them would have made
 * two lists that agree today: the next column added to one of them decides,
 * silently, which of the two write paths accepts it.
 *
 * `partner_user_id` is absent on purpose. Ownership changes are a distinct,
 * audited operation — ContractRepository::reassign() — never a side effect of a
 * save, and the omission here is what enforces that. ContractScopeTest pins it
 * from the outside: a caller who sends partner_user_id is refused.
 *
 * The list is deny-by-default the strict way round: an unrecognised column is
 * an exception, not a silent drop. A typo in a form field name is a bug worth
 * hearing about, and a caller inventing a column name is worth hearing about
 * twice.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

final class WritableColumns
{
    /** @var list<string> */
    private const ALLOWED = [
        'customer_id',
        'provider_id',
        'program_id',
        'energy_type',
        'category',
        'price_type',
        'customer_type',
        'activation_type',
        'supply_number',
        'meter_number',
        'invoice_code',
        'status',
        'notes',
        'extracted_json',
        'extra_json',
        'start_date',
        'term_months',
        'end_date',
        'supply_addr_same',
        'supply_street',
        'supply_street_no',
        'supply_city',
        'supply_postal_code',
        'supply_region',
        'billing_addr_same',
        'billing_street',
        'billing_street_no',
        'billing_city',
        'billing_postal_code',
        'billing_region',
        'consent_at',
        'consent_ip',
        'signed_at',
        'signed_ip',
        'payout_id',
        'code',
    ];

    private function __construct()
    {
    }

    /**
     * The data unchanged, or an exception naming what was not recognised.
     *
     * Returns rather than filters so the caller can pass it straight on: the
     * value of this check is that there is no version of the data that got
     * through half-inspected.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function filter(array $data): array
    {
        $unknown = array_values(array_diff(array_keys($data), self::ALLOWED));

        if ($unknown !== []) {
            throw UnknownColumns::forEntity('σύμβαση', $unknown);
        }

        return $data;
    }
}
