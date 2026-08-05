<?php

/**
 * The three addresses a supply contract needs, resolved from one row.
 *
 * Every provider form asks for all three and says so explicitly — "εφόσον είναι
 * διαφορετική από τη διεύθυνση κατοικίας". They are usually the same, which is
 * why one address survived this long, and they are different exactly when it
 * matters: a rented shop, a holiday house, an accountant who receives the bills.
 *
 * The "same as home" flags are stored rather than inferred from emptiness. An
 * empty supply address because the agent deliberately ticked "same" and an empty
 * one because they never got to it are different facts, and only the first is
 * safe to print on an application.
 *
 * Pure resolution logic, no WordPress, tested.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Contract;

use EnergyCRM\Domain\Customer\PostalAddress;

final class ContractAddresses
{
    public const SUPPLY_PREFIX  = 'supply_';
    public const BILLING_PREFIX = 'billing_';

    private function __construct(
        /** Where the customer lives, or the company has its seat. */
        public readonly PostalAddress $home,
        /** Where the meter is. */
        public readonly PostalAddress $supply,
        /** Where the bill should arrive. */
        public readonly PostalAddress $billing,
    ) {
    }

    /**
     * @param array<string, mixed> $row A contract joined with its customer.
     */
    public static function from(array $row): self
    {
        $home = PostalAddress::fromRow($row);

        return new self(
            $home,
            self::resolve($row, self::SUPPLY_PREFIX, $home),
            self::resolve($row, self::BILLING_PREFIX, $home),
        );
    }

    /**
     * True when the bill goes to the customer's own address.
     *
     * Forms that ask this as two boxes — "Ίδια / Διαφορετική" — need the answer
     * rather than the address, and an address comparison would get it wrong the
     * moment someone re-typed the same street with a different abbreviation.
     */
    public function billingIsHome(): bool
    {
        return $this->billing === $this->home;
    }

    /**
     * Fall back to the home address when the flag says so — and also when the
     * flag says otherwise but nothing was actually entered.
     *
     * That second case is not the flag being wrong; it is a half-finished form.
     * Printing the home address there is what the agent would have done by hand,
     * and it beats leaving a mandatory box blank.
     *
     * @param array<string, mixed> $row
     */
    private static function resolve(array $row, string $prefix, PostalAddress $home): PostalAddress
    {
        if (self::isFlaggedSame($row, $prefix)) {
            return $home;
        }

        $address = PostalAddress::fromRow($row, $prefix);

        return $address->isEmpty() ? $home : $address;
    }

    /**
     * Absent column means "same": that is what every contract written before
     * these columns existed meant, and the migration defaults match it.
     *
     * @param array<string, mixed> $row
     */
    private static function isFlaggedSame(array $row, string $prefix): bool
    {
        $column = $prefix . 'addr_same';

        return ! array_key_exists($column, $row) || (bool) $row[$column];
    }
}
