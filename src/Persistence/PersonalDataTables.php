<?php

/**
 * The checklist of where a customer's personal data lives.
 *
 * Two obligations read the same list from opposite ends: an access request
 * must disclose everything we hold, an erasure request must remove it. They
 * were written separately and drifted — the erase screen cleared tables the
 * export screen never showed, so a customer could be told less than we held
 * and, worse, data could survive a deletion nobody knew was incomplete.
 *
 * One list, answered by PersonalDataExporter and PersonalDataEraser. A table
 * missing from here is a table the CRM neither discloses nor deletes, and
 * nothing will point that out.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

final class PersonalDataTables
{
    private function __construct()
    {
    }

    /**
     * Tables that hang off a customer's contracts, and the column that ties a
     * row to one.
     *
     * `customers` and `contracts` themselves are deliberately absent: they are
     * the root of the export, not attachments to it, and each is handled by
     * name because their columns are not interchangeable.
     *
     * `tasks` reaches a customer both through a contract and directly through
     * `customer_id`; only the contract edge belongs in this map, because the
     * values here are keyed by table and a table cannot appear twice.
     *
     * **Η δεύτερη ακμή ΔΕΝ χάνεται** — και οι δύο καταναλωτές τη χειρίζονται
     * ρητά: `PersonalDataExporter::export()` συγχωνεύει τις εργασίες που
     * βρίσκονται με `customer_id`, και `PersonalDataEraser::eraseTasks()`
     * σβήνει με τα δύο κλειδιά. Το ότι δεν φαίνεται εδώ είναι ακριβώς που
     * παραπλάνησε τον έλεγχο της 18/08/2026: διαβάστηκε ως «η ακμή
     * απορρίφθηκε» ενώ λέει «η ακμή δεν χωράει σε αυτόν τον χάρτη».
     *
     * Ο `PersonalDataCoverageTest` σαρώνει πλέον το πραγματικό σχήμα και
     * απαιτεί κάθε ακμή να είναι είτε εδώ είτε δηλωμένη ως χειρωνακτική. Η
     * υπόσχεση από πάνω —«τίποτα δεν θα το επισημάνει»— έπαψε να ισχύει.
     *
     * @return array<string, string> Tables::* constant => key column
     */
    public static function linkedToContracts(): array
    {
        return [
            Tables::FILES         => 'contract_id',
            Tables::SIGNATURES    => 'contract_id',
            Tables::EVENTS        => 'contract_id',
            Tables::NOTIFICATIONS => 'contract_id',
            Tables::LEADS         => 'contract_id',
            Tables::TASKS         => 'contract_id',
            // Η δέσμευση idempotency της δημιουργίας (Επίπεδο Γ). Δεν κρατά
            // κανένα στοιχείο του πελάτη -- κρατά ένα uuid της συσκευής του
            // συνεργάτη -- αλλά η ακμή προς τη σύμβαση είναι ζωντανή, οπότε
            // μπαίνει εδώ αντί να δηλωθεί εξαίρεση: μια σβησμένη σύμβαση δεν
            // έχει λόγο να αφήνει πίσω τη δέσμευση που τη γέννησε.
            Tables::REQUEST_KEYS  => 'contract_id',
        ];
    }

    /**
     * Columns worth disclosing per table, where dumping the row would be wrong.
     *
     * Only `files` needs this: `path` is an absolute location on our server,
     * which tells the customer nothing about themselves and tells an attacker
     * something about us. The document itself is available through the app.
     *
     * @return array<string, string> Tables::* constant => SELECT column list
     */
    public static function disclosedColumns(): array
    {
        return [
            Tables::FILES => 'id, contract_id, doc_kind, filename, mime, created_at',
            // Το `request_key` είναι τυχαίο uuid που παρήγαγε η συσκευή του
            // συνεργάτη. Δεν λέει τίποτα στο υποκείμενο για τον εαυτό του και
            // δείχνει σε τρίτον πώς δουλεύει η ουρά μας -- ίδιο σκεπτικό με το
            // `path` των files ακριβώς από πάνω.
            Tables::REQUEST_KEYS => 'id, contract_id, created_at, completed_at',
        ];
    }
}
