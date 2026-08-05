<?php

/**
 * Splits a Greek address written as one string into its parts.
 *
 * The VAT register returns something like "ΣΟΛΩΜΟΥ 15\n42100 ΤΡΙΚΑΛΑ", and the
 * form wants street, number, postcode and town separately. Best effort by
 * nature — the register's formatting is not a contract — so anything it cannot
 * place goes to the street rather than being dropped.
 *
 * Pure string handling, no WordPress, tested.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Customer;

final class GreekAddress
{
    private function __construct()
    {
    }

    /**
     * @return array{street: string, street_no: string, postal_code: string, city: string}
     */
    public static function parse(string $address): array
    {
        $parsed = ['street' => '', 'street_no' => '', 'postal_code' => '', 'city' => ''];
        $flat   = trim((string) preg_replace('/\s+/u', ' ', str_replace(["\n", "\r"], ' ', $address)));

        if ($flat === '') {
            return $parsed;
        }

        // A five-digit group followed by words is the postcode and the town.
        if (preg_match('/(\d{5})\s+(.+)$/u', $flat, $tail) === 1) {
            $parsed['postal_code'] = $tail[1];
            $parsed['city']        = trim($tail[2]);
            $flat                  = trim(str_replace($tail[0], '', $flat));
        }

        // What is left ends with the street number, optionally with a letter
        // suffix — "ΣΟΛΩΜΟΥ 15Α" is a real address.
        if (preg_match('/^(.*?)\s+(\d+[Α-Ω]?)\s*$/u', $flat, $street) === 1) {
            $parsed['street']    = trim($street[1]);
            $parsed['street_no'] = trim($street[2]);

            return $parsed;
        }

        $parsed['street'] = $flat;

        return $parsed;
    }
}
