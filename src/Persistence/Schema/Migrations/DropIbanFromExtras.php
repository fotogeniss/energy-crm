<?php

/**
 * Removes the bank account numbers the CRM had no use for.
 *
 * The form asked every customer for an IBAN, stored it encrypted inside
 * extra_json, and printed it nowhere: none of the twenty provider forms has an
 * IBAN field, so the value was written and never read again. That was recorded
 * as an open question — do the providers want it? — and answered on 2026-08-11:
 * they do not, and we stop asking.
 *
 * Deleting what is already stored is the other half of that decision. Holding a
 * bank account number for a purpose that has been ruled out is not a tidiness
 * problem, it is collecting personal data with no lawful basis: the GDPR's
 * minimisation principle, and the one place where doing nothing is worse than
 * acting. The encryption does not change that. It protects the value from a
 * stolen backup; it does not give us a reason to have it.
 *
 * ## What this does not touch
 *
 * The `iban` document kind stays. That is a file the customer uploads because
 * the provider asked for it — a bank statement travelling with a direct-debit
 * mandate — not a field the CRM demands. Rows in the files table already carry
 * that kind, and removing the label would leave them describing nothing.
 *
 * ## Why the key and not the value
 *
 * The stored value may be plaintext or `ecrm1:…` ciphertext depending on when
 * the row was written and whether ECRM_ENCRYPT_PII was on. Removing the key
 * makes both cases the same operation, and needs no key material to run — which
 * matters, because a migration that could only clean the rows it can decrypt
 * would silently leave the rest behind.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class DropIbanFromExtras implements Migration
{
    private const KEY = 'iban';

    public function id(): string
    {
        return '0015_drop_iban_from_extras';
    }

    public function description(): string
    {
        return 'Διαγραφή των αποθηκευμένων IBAN από το extra_json των συμβολαίων';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $contracts = Tables::name(Tables::CONTRACTS);

        if (! $schema->hasTable($contracts) || ! $schema->hasColumn($contracts, 'extra_json')) {
            return;
        }

        // Only rows whose bag mentions the key. On a table of open cases this
        // is the difference between a handful of rows and every one of them.
        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, extra_json FROM %i WHERE extra_json LIKE %s',
                [$contracts, '%"' . self::KEY . '"%']
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        foreach ((array) $rows as $row) {
            $cleaned = $this->withoutIban((string) ($row['extra_json'] ?? ''));

            if ($cleaned === null) {
                continue;
            }

            $wpdb->update($contracts, ['extra_json' => $cleaned], ['id' => (int) $row['id']]);
        }
    }

    /**
     * The bag without its IBAN, or null when there was nothing to do.
     *
     * Null rather than the unchanged string so the caller can skip the write
     * entirely: a LIKE on `"iban"` also matches a bag that merely contains the
     * word, and rewriting a row to the value it already holds is a needless
     * bump to updated_at — which is what the contracts list sorts by.
     */
    private function withoutIban(string $json): ?string
    {
        $bag = json_decode($json, true);

        if (! is_array($bag) || ! array_key_exists(self::KEY, $bag)) {
            return null;
        }

        unset($bag[self::KEY]);

        return (string) wp_json_encode($bag);
    }
}
