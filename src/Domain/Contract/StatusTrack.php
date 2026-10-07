<?php

/**
 * Ποια λίστα καταστάσεων ισχύει για μια αίτηση.
 *
 * Τρεις λίστες, όπως τις έδωσε ο ιδιοκτήτης 05/10/2026: ρεύμα, αέριο (ίδια
 * με το ρεύμα χωρίς τα ΘΑΛΗΣ) και κινητή. Αίτηση με γραμμή κινητής (και
 * COMBO με κινητή) ακολουθεί τη λίστα της κινητής, γιατί περνάει από SIM.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Contract;

final class StatusTrack
{
    public const POWER  = 'power';
    public const GAS    = 'gas';
    public const MOBILE = 'mobile';

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $contract Πρέπει να έχει `energy_type` και
     *                                       `extra_json` (raw στήλη).
     */
    public static function of(array $contract): string
    {
        if (MobileLine::isPresent($contract)) {
            return self::MOBILE;
        }

        return (string) ($contract['energy_type'] ?? '') === 'gas' ? self::GAS : self::POWER;
    }
}
