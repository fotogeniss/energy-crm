<?php

/**
 * One Greek postal address.
 *
 * A contract involves three of these and they are genuinely different places:
 * where the customer lives, where the meter is, and where the bill should
 * arrive. The CRM used to hold one and print it in all three boxes, which reads
 * correctly right up until the meter is in a rented shop and the provider
 * rejects the application.
 *
 * Pure value object: no WordPress, no database, tested.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Customer;

final class PostalAddress
{
    public function __construct(
        public readonly string $street = '',
        public readonly string $streetNo = '',
        public readonly string $city = '',
        public readonly string $postalCode = '',
        public readonly string $region = '',
    ) {
    }

    /**
     * Read an address out of a row, with an optional column prefix.
     *
     * The customers table names them street/city/…; the contracts table adds
     * supply_ and billing_ in front of the same names. One reader, three uses.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row, string $prefix = ''): self
    {
        $get = static fn (string $column): string
            => trim((string) ($row[$prefix . $column] ?? ''));

        return new self(
            $get('street'),
            $get('street_no'),
            $get('city'),
            $get('postal_code'),
            $get('region'),
        );
    }

    /** True when not one part was filled in. */
    public function isEmpty(): bool
    {
        return $this->street === ''
            && $this->streetNo === ''
            && $this->city === ''
            && $this->postalCode === ''
            && $this->region === '';
    }

    /**
     * "ΣΟΛΩΜΟΥ 15, 42100 ΤΡΙΚΑΛΑ" — for the forms with a single address line.
     *
     * Parts that are missing take their separator with them, so an address with
     * no number never prints a stray comma.
     */
    public function oneLine(): string
    {
        $street = trim($this->street . ' ' . $this->streetNo);
        $town   = trim($this->postalCode . ' ' . $this->city);

        return implode(', ', array_filter([$street, $town], static fn (string $p): bool => $p !== ''));
    }

    /**
     * The columns as they are written back to a contract row.
     *
     * @return array<string, string>
     */
    public function toColumns(string $prefix): array
    {
        return [
            $prefix . 'street'      => $this->street,
            $prefix . 'street_no'   => $this->streetNo,
            $prefix . 'city'        => $this->city,
            $prefix . 'postal_code' => $this->postalCode,
            $prefix . 'region'      => $this->region,
        ];
    }
}
