<?php

/**
 * (339) Τα τεχνικά που στέλνει μόνος του ο browser με κάθε αναφορά.
 *
 * Ό,τι έρχεται από τον browser είναι πρόταση, όχι δεδομένο: εδώ κρατιούνται
 * ΜΟΝΟ τα γνωστά κλειδιά, κομμένα σε λογικό μήκος, και τα μηνύματα σφάλματος
 * περνούν από το ίδιο κρύψιμο με το κείμενο (TicketText). Η αίτηση
 * αποθηκεύεται ως αριθμός μέσα στο πλαίσιο και όχι ως στήλη `contract_id`:
 * η αναφορά αφορά την εφαρμογή, όχι τον πελάτη, και δεν ανήκει στην εξαγωγή
 * ή στη διαγραφή προσωπικών δεδομένων του (PersonalDataTables). Ζει 12 μήνες.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Ticket;

final class TicketContext
{
    public const MAX_ERRORS = 10;

    public const ERROR_LENGTH = 300;

    /** κλειδί => μέγιστο μήκος. */
    private const TEXT = [
        'view'          => 40,
        'view_label'    => 60,
        'step'          => 60,
        'contract_code' => 32,
        'device'        => 120,
        'viewport'      => 20,
        'version'       => 20,
        'role'          => 40,
    ];

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public static function clean(array $raw): array
    {
        $out = [];

        foreach (self::TEXT as $key => $max) {
            $value = $raw[$key] ?? null;

            if (! is_scalar($value)) {
                continue;
            }

            $value = trim((string) preg_replace('/[\p{C}]+/u', ' ', (string) $value));

            if ($value !== '') {
                $out[$key] = mb_substr($value, 0, $max, 'UTF-8');
            }
        }

        $contract = $raw['contract_id'] ?? null;

        if (is_numeric($contract) && (int) $contract > 0) {
            $out['contract_id'] = (int) $contract;
        }

        $errors = [];

        foreach (is_array($raw['errors'] ?? null) ? $raw['errors'] : [] as $error) {
            if (! is_scalar($error) || count($errors) >= self::MAX_ERRORS) {
                continue;
            }

            $error = TicketText::clean((string) $error, self::ERROR_LENGTH);

            if ($error !== '') {
                $errors[] = $error;
            }
        }

        if ($errors !== []) {
            $out['errors'] = $errors;
        }

        return $out;
    }
}
