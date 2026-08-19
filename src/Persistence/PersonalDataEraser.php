<?php

/**
 * Everything we hold about one customer, removed in one pass.
 *
 * Erasure used to live inline in the GDPR admin screen and cleared the columns
 * that were obvious: the customer row, contract notes, the extraction payload.
 * It missed the places personal data had spread to since — the IBAN and the
 * legal representative's ΑΔΤ inside the extras bag, the signature image and the
 * signer's IP, the names written into the event log, the lead the contract came
 * from. A record could pass through "ανωνυμοποιήθηκε" with the customer's bank
 * account still in the database.
 *
 * So the knowledge of *where personal data lives* belongs in one class, not
 * scattered across whichever screen happens to call it. Add a table that holds
 * personal data, add it here — and to PersonalDataTables, which is the list
 * PersonalDataExporter answers to. Anything erased must also be disclosable;
 * the two obligations are the same list read from opposite ends.
 *
 * The columns stay written out per table rather than driven from that list:
 * which fields of a row identify someone is a judgement, and a loop would hide
 * it. The list guarantees no table is forgotten; these methods decide what
 * inside each one is personal.
 *
 * What survives on purpose: the contract rows themselves, minus their personal
 * columns. Erasure is anonymisation, not deletion — the company still needs to
 * count how many contracts it signed last year, and a count is not personal
 * data once no one can be identified from the row.
 *
 * See ContractRepository for the note on the phpcs exemptions.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

use EnergyCRM\Domain\Forms\ProviderFormFields;

final class PersonalDataEraser
{
    /** Stands in for an erased name where the column cannot be NULL. */
    private const REDACTED = '—';

    private FileRepository $files;

    public function __construct(FileRepository $files)
    {
        $this->files = $files;
    }

    /**
     * Strip every personal field belonging to this customer.
     *
     * Documents go first: they are the only part stored outside the database,
     * so if anything fails afterwards we have at least removed the bytes that
     * sit on disk in the clear.
     *
     * @return array<string, int> Rows cleared per area, for the audit trail.
     */
    public function erase(int $customerId): array
    {
        if ($customerId <= 0) {
            return [];
        }

        $contractIds = $this->contractIdsFor($customerId);

        $report = [
            'files'         => 0,
            'contracts'     => 0,
            'signatures'    => 0,
            'events'        => 0,
            'notifications' => 0,
            'leads'         => 0,
            'tasks'         => 0,
            'customer'      => 0,
        ];

        if ($contractIds !== []) {
            $report['files']         = $this->files->purgeForContracts($contractIds);
            $report['contracts']     = $this->eraseContracts($contractIds);
            $report['signatures']    = $this->eraseSignatures($contractIds);
            $report['events']        = $this->eraseEvents($contractIds);
            $report['notifications'] = $this->eraseNotifications($contractIds);
            $report['leads']         = $this->eraseLeads($contractIds);
        }

        $report['tasks']    = $this->eraseTasks($customerId, $contractIds);
        $report['customer'] = $this->eraseCustomer($customerId);

        return $report;
    }

    /** @return list<int> */
    private function contractIdsFor(int $customerId): array
    {
        global $wpdb;

        /** @var list<string> $ids */
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT id FROM %i WHERE customer_id = %d',
                Tables::name(Tables::CONTRACTS),
                $customerId
            )
        );

        return array_values(array_filter(array_map('intval', $ids)));
    }

    /**
     * Contract columns that quote the person: free-text notes, the raw AI
     * extraction payload, the IP the consent came from, and the extras bag —
     * which is filtered rather than dropped, because it also carries the meter
     * and pricing figures the contract is still counted by.
     *
     * @param list<int> $contractIds
     */
    private function eraseContracts(array $contractIds): int
    {
        $rowsChanged = $this->redactRows(
            Tables::CONTRACTS,
            // Το track_key μαζί: ο σύνδεσμος παρακολούθησης είναι δρόμος προς
            // τα στοιχεία αυτού του ανθρώπου, και μια διαγραφή που τον αφήνει
            // ζωντανό δεν είναι διαγραφή.
            'notes = NULL, extracted_json = NULL, consent_ip = NULL, track_key = NULL',
            'id',
            $contractIds
        );

        $this->filterExtras($contractIds);

        return $rowsChanged;
    }

    /**
     * Rewrite each extras bag keeping only the fields that say nothing about
     * the person. The bag is free-form — any key the form sends is stored — so
     * an unknown key is treated as personal and dropped. A field added to a
     * provider form next year is then erased correctly without anyone
     * remembering to come back here.
     *
     * @param list<int> $contractIds
     */
    private function filterExtras(array $contractIds): void
    {
        global $wpdb;

        $table          = Tables::name(Tables::CONTRACTS);
        $idPlaceholders = $this->idPlaceholders($contractIds);

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, extra_json FROM %i
                 WHERE id IN ({$idPlaceholders}) AND extra_json IS NOT NULL",
                $table,
                ...$contractIds
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        foreach ($rows as $row) {
            $extras = json_decode((string) $row['extra_json'], true);

            if (! is_array($extras)) {
                $extras = [];
            }

            // json_decode turns a numeric key back into an int, so cast before
            // asking — the lookup is by name.
            $retained = array_filter(
                $extras,
                static fn ($inputName): bool => ! ProviderFormFields::isPersonalInput((string) $inputName),
                ARRAY_FILTER_USE_KEY
            );

            if (count($retained) === count($extras)) {
                continue;
            }

            $wpdb->update(
                $table,
                ['extra_json' => $retained !== [] ? wp_json_encode($retained) : null],
                ['id' => (int) $row['id']]
            );
        }
    }

    /**
     * The signature image is a picture of the person's hand. Nothing about it
     * is worth keeping once the contract is anonymised, so the row keeps only
     * the fact that a signature happened and when.
     *
     * @param list<int> $contractIds
     */
    private function eraseSignatures(array $contractIds): int
    {
        return $this->redactRows(
            Tables::SIGNATURES,
            'signer_name = NULL, image = NULL, ip = NULL',
            'contract_id',
            $contractIds
        );
    }

    /**
     * Status history stays — who moved a contract to "signed" and when is an
     * audit record the company must be able to produce. The free-text message
     * does not: it quotes names and IPs.
     *
     * @param list<int> $contractIds
     */
    private function eraseEvents(array $contractIds): int
    {
        return $this->redactRows(Tables::EVENTS, 'message = NULL', 'contract_id', $contractIds);
    }

    /** @param list<int> $contractIds */
    private function eraseNotifications(array $contractIds): int
    {
        return $this->redactRows(
            Tables::NOTIFICATIONS,
            'title = %s, body = NULL',
            'contract_id',
            $contractIds,
            [self::REDACTED]
        );
    }

    /**
     * The lead the contract grew out of holds the first contact details ever
     * taken — usually a name and a mobile typed during a phone call.
     *
     * @param list<int> $contractIds
     */
    private function eraseLeads(array $contractIds): int
    {
        return $this->redactRows(
            Tables::LEADS,
            'name = %s, phone = NULL, email = NULL, notes = NULL, interest = NULL, lost_reason = NULL',
            'contract_id',
            $contractIds,
            [self::REDACTED]
        );
    }

    /**
     * Tasks reach the customer two ways: directly, and through a contract.
     *
     * Both go through redactRows rather than one of them writing its own
     * UPDATE: a single id is an IN list of one, and reusing the method keeps
     * every statement in this class built the same way, with the same binding.
     *
     * A task carrying both keys is redacted by the first pass and reports no
     * change in the second, so the total does not double-count it.
     *
     * @param list<int> $contractIds
     */
    private function eraseTasks(int $customerId, array $contractIds): int
    {
        $assignments = 'title = %s, note = NULL';

        return $this->redactRows(
            Tables::TASKS,
            $assignments,
            'customer_id',
            [$customerId],
            [self::REDACTED]
        ) + $this->redactRows(
            Tables::TASKS,
            $assignments,
            'contract_id',
            $contractIds,
            [self::REDACTED]
        );
    }

    /**
     * The customer row keeps its id and region: the id so contracts still join
     * to something, the region because a county is not a person.
     */
    private function eraseCustomer(int $customerId): int
    {
        global $wpdb;

        return (int) $wpdb->update(
            Tables::name(Tables::CUSTOMERS),
            [
                'first_name'   => self::REDACTED,
                'last_name'    => 'ΔΙΑΓΡΑΦΗ',
                'father_name'  => null,
                'company_name' => null,
                'afm'          => null,
                // The blind index outlives the value it indexes unless it is
                // cleared too: anyone holding a ΑΦΜ could hash it and confirm
                // the person was once a customer here.
                CustomerFields::INDEX_COLUMN => null,
                'doy'          => null,
                'adt'          => null,
                'birth_date'   => null,
                'email'        => null,
                'phone'        => null,
                'mobile'       => null,
                'street'       => null,
                'street_no'    => null,
                'postal_code'  => null,
                'city'         => null,
            ],
            ['id' => $customerId]
        );
    }

    /**
     * Blank out columns on every row whose key column matches one of the ids.
     *
     * The table, the assignments and the key column are written by this class
     * and never by a request. Everything else is bound, in the order the
     * placeholders appear: the values the assignments need first, then the ids.
     *
     * @param string           $unprefixedTable  A Tables::* constant.
     * @param string           $assignments      SQL after SET, e.g. 'name = %s, phone = NULL'.
     * @param string           $keyColumn        Column the ids are matched against.
     * @param list<int>        $ids
     * @param list<string|int> $assignmentValues Values for the placeholders in $assignments.
     *
     * @return int Number of rows changed.
     */
    private function redactRows(
        string $unprefixedTable,
        string $assignments,
        string $keyColumn,
        array $ids,
        array $assignmentValues = [],
    ): int {
        global $wpdb;

        if ($ids === []) {
            return 0;
        }

        $idPlaceholders = $this->idPlaceholders($ids);

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $rowsChanged = $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i SET {$assignments} WHERE {$keyColumn} IN ({$idPlaceholders})",
                Tables::name($unprefixedTable),
                ...$assignmentValues,
                ...$ids
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $rowsChanged === false ? 0 : (int) $rowsChanged;
    }

    /**
     * A bound `%d` per id, for an IN list.
     *
     * @param list<int> $ids
     */
    private function idPlaceholders(array $ids): string
    {
        return implode(',', array_fill(0, count($ids), '%d'));
    }
}
