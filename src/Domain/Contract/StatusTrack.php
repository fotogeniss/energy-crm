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

    /**
     * (403) Η κατάσταση ισχύει για αυτό το είδος αίτησης; Αλλιώς το μήνυμα.
     *
     * Πριν, η άρνηση ζούσε μόνο μέσα στο `ContractLifecycle::moveTo()`, που
     * γύριζε απλώς false· οι καλούντες δεν το κοίταζαν και απαντούσαν «ΟΚ» με
     * τη νέα κατάσταση ενώ η βάση έμενε στην παλιά.
     */
    public static function refusal(ContractStatus $status, string $track): ?string
    {
        if ($status->appliesTo($track)) {
            return null;
        }

        return sprintf('Η κατάσταση «%s» δεν ισχύει για αιτήσεις %s.', $status->label(), self::label($track));
    }

    /** Το είδος σε γενική, για μηνύματα: «αιτήσεις ρεύματος». */
    public static function label(string $track): string
    {
        return match ($track) {
            self::MOBILE => 'κινητής τηλεφωνίας',
            self::GAS    => 'φυσικού αερίου',
            default      => 'ρεύματος',
        };
    }
}
