<?php

/**
 * ΤΚ → Νομός/Γεωγραφικό Διαμέρισμα και ΤΚ → πόλη/περιοχή. Καθαρό PHP, καμία
 * εξάρτηση σε WordPress -- ίδιο πρότυπο με `Domain\Forms\ProviderFormFields`
 * (στατικά JSON κάτω από assets/, `is_readable` + `json_decode`, defensive
 * `is_array`).
 *
 * Δύο ξεχωριστές πηγές, σκόπιμα χωριστές μέθοδοι -- CHANGELOG (216):
 *
 *  - `nomos()` διαβάζει το assets/data/postal-nomos.json: το επίσημο πρόθεμα
 *    ΕΛ.ΤΑ. (2 πρώτα ψηφία → Νομός/Γεωγραφικό Διαμέρισμα), αντιγραμμένο από
 *    το «Κατάλογος ταχυδρομικών κωδικών της Ελλάδας» στη Βικιπαίδεια
 *    (CC BY-SA, παραπομπή σε ΕΛ.ΤΑ./Χρυσό Οδηγό). Ντετερμινιστικό, πλήρης
 *    κάλυψη 10-85 (70 προθέματα -- τα κενά 39 και 75-79 δεν αντιστοιχούν σε
 *    καμία ταχυδρομική περιοχή, όχι λάθος).
 *
 *  - `city()` διαβάζει το assets/data/postal-territory.json: το
 *    MentatInnovations/grpostcodes στο GitHub (Apache-2.0). ΔΕΝ είναι
 *    επίσημη πηγή -- lookups μέσω Google Maps, ~1.250 ΤΚ, δεν καλύπτει όλη
 *    την Ελλάδα. Best-effort -- `null` όταν δεν βρεθεί, ποτέ μάντεμα.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Address;

final class PostalLookup
{
    private function __construct()
    {
    }

    /** @var array<string, array{nomos: string, diamerisma: string}>|null */
    private static ?array $nomosCache = null;

    /** @var array<string, string>|null */
    private static ?array $territoryCache = null;

    /**
     * Νομός + γεωγραφικό διαμέρισμα από τα 2 πρώτα ψηφία του ΤΚ.
     *
     * @return array{nomos: string, diamerisma: string}|null
     */
    public static function nomos(string $postalCode, string $dataDir): ?array
    {
        $prefix = self::prefix($postalCode);

        if ($prefix === null) {
            return null;
        }

        $entry = self::loadNomos($dataDir)[$prefix] ?? null;

        return is_array($entry) ? $entry : null;
    }

    /**
     * Πόλη/περιοχή από το πλήρες 5ψήφιο ΤΚ. `null` όταν το ΤΚ δεν υπάρχει
     * στο (μερικό) grpostcodes -- ΠΟΤΕ εικασία από το πρόθεμα.
     */
    public static function city(string $postalCode, string $dataDir): ?string
    {
        $digits = self::digits($postalCode);

        if ($digits === null) {
            return null;
        }

        $city = self::loadTerritory($dataDir)[$digits] ?? null;

        return is_string($city) && $city !== '' ? $city : null;
    }

    /** Καθαρίζει το in-memory cache -- χρήσιμο μόνο σε τεστ. */
    public static function forget(): void
    {
        self::$nomosCache = null;
        self::$territoryCache = null;
    }

    private static function digits(string $postalCode): ?string
    {
        $digits = preg_replace('/\D+/', '', $postalCode) ?? '';

        return strlen($digits) === 5 ? $digits : null;
    }

    private static function prefix(string $postalCode): ?string
    {
        $digits = self::digits($postalCode);

        return $digits === null ? null : substr($digits, 0, 2);
    }

    /** @return array<string, array{nomos: string, diamerisma: string}> */
    private static function loadNomos(string $dataDir): array
    {
        if (self::$nomosCache !== null) {
            return self::$nomosCache;
        }

        $data = self::readJson($dataDir, 'postal-nomos.json');
        self::$nomosCache = $data ?? [];

        return self::$nomosCache;
    }

    /** @return array<string, string> */
    private static function loadTerritory(string $dataDir): array
    {
        if (self::$territoryCache !== null) {
            return self::$territoryCache;
        }

        $data = self::readJson($dataDir, 'postal-territory.json');
        self::$territoryCache = $data ?? [];

        return self::$territoryCache;
    }

    /** @return array<string, mixed>|null */
    private static function readJson(string $dataDir, string $file): ?array
    {
        $path = rtrim($dataDir, '/\\') . '/' . $file;

        if (! is_readable($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }
}
