<?php

/**
 * Erasure, checked the only way that means anything: by looking afterwards.
 *
 * The bug this replaces was not a crash. `erase()` reported success while the
 * customer's IBAN, their signature and the lead their first phone call became
 * were all still in the database. Every assertion here is a table that was
 * missed once, and would be missed again the next time a table is added and
 * this class is not.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Persistence\CustomerFields;
use EnergyCRM\Persistence\CustomerRepository;
use EnergyCRM\Persistence\PersonalDataEraser;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\Tables;
use EnergyCRM\Services;

final class PersonalDataErasureTest extends IntegrationTestCase
{
    private const IBAN = 'GR1601101250000000012300695';

    private int $customerId;

    private int $contractId;

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;

        $partner          = $this->makePartner();
        $this->customerId = (new CustomerRepository())->create($this->customerData());

        $wpdb->insert(Tables::name(Tables::CONTRACTS), [
            'customer_id'     => $this->customerId,
            'partner_user_id' => $partner,
            'status'          => 'signed',
            'notes'           => 'Ο πελάτης ζήτησε να τον καλέσουμε το απόγευμα.',
            'consent_ip'      => '203.0.113.9',
            'extracted_json'  => '{"afm":"123456789"}',
            'extra_json'      => (string) wp_json_encode([
                'iban'           => self::IBAN,
                'rep_last_name'  => 'Παπαδόπουλος',
                'contact_adt'    => 'ΑΒ999999',
                'agreed_power'   => '8',
            ]),
        ]);

        $this->contractId = (int) $wpdb->insert_id;

        $wpdb->insert(Tables::name(Tables::SIGNATURES), [
            'contract_id' => $this->contractId,
            'token'       => 'tok-' . $this->contractId,
            'signer_name' => 'Γιώργος Παπαδόπουλος',
            'image'       => 'data:image/png;base64,iVBORw0KGgo=',
            'ip'          => '203.0.113.9',
        ]);

        $wpdb->insert(Tables::name(Tables::LEADS), [
            'partner_user_id' => $partner,
            'name'            => 'Γιώργος Παπαδόπουλος',
            'phone'           => '6900000000',
            'email'           => 'giorgos@example.test',
            'notes'           => 'Ζήτησε προσφορά για ρεύμα.',
            'contract_id'     => $this->contractId,
        ]);

        $wpdb->insert(Tables::name(Tables::EVENTS), [
            'contract_id' => $this->contractId,
            'type'        => 'note',
            'message'     => 'Υπογραφή από Γιώργος Παπαδόπουλος, IP 203.0.113.9',
        ]);

        $wpdb->insert(Tables::name(Tables::TASKS), [
            'contract_id' => $this->contractId,
            'customer_id' => $this->customerId,
            'assigned_to' => $partner,
            'title'       => 'Κλήση στον Γιώργο Παπαδόπουλο',
            'note'        => 'Τηλέφωνο 6900000000',
        ]);

        // 247, Στάδιο 2: μόνη ακμή που κρέμεται ΜΟΝΟ από πελάτη, ποτέ σύμβαση.
        $wpdb->insert(Tables::name(Tables::CUSTOMER_NOTES), [
            'customer_id'     => $this->customerId,
            'partner_user_id' => $partner,
            'body'            => 'Καλεί μετά τις 17:00, Γιώργος Παπαδόπουλος.',
        ]);

        (new CustomerRepository())->update(
            $this->customerId,
            UserScope::forSelf($partner),
            ['contact_phone' => '6944111222']
        );

        // 247, Στάδιο 3: δεύτερη ακμή-μόνο-από-πελάτη, ίδια κατηγορία με το
        // customer_notes -- ένα ιστορικό αλλαγών, με τιμή ΠΙΘΑΝΟΝ κρυπτογραφημένη.
        $wpdb->insert(Tables::name(Tables::CUSTOMER_EVENTS), [
            'customer_id'     => $this->customerId,
            'partner_user_id' => $partner,
            'field'           => 'mobile',
            'old_value'       => '6971000000',
            'new_value'       => '6971234567',
        ]);

        (new PersonalDataEraser(Services::files()))->erase($this->customerId);
    }

    public function testTheCustomerRowKeepsNothingThatIdentifiesThem(): void
    {
        $customer = $this->storedRow(Tables::CUSTOMERS, $this->customerId);

        foreach (['afm', 'adt', 'birth_date', 'email', 'phone', 'street', 'postal_code', 'contact_phone'] as $column) {
            self::assertNull($customer[$column], "{$column} survived erasure.");
        }

        self::assertSame('ΔΙΑΓΡΑΦΗ', $customer['last_name']);
    }

    /**
     * 247, Στάδιο 2: η σημείωση δεν ανωνυμοποιείται όπως ένα contract -- δεν
     * μένει καθόλου γραμμή, γιατί δεν υπάρχει τίποτα μέσα της που αξίζει να
     * επιβιώσει (ολόκληρη είναι ελεύθερο κείμενο για τρίτο πρόσωπο).
     */
    public function testCustomerNotesAreGoneEntirely(): void
    {
        global $wpdb;

        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE customer_id = %d',
                Tables::name(Tables::CUSTOMER_NOTES),
                $this->customerId
            )
        );

        self::assertSame(0, $count);
    }

    /** 247, Στάδιο 3: ίδιος λόγος με το customer_notes -- μηδέν γραμμές, όχι ανωνυμοποίηση. */
    public function testCustomerEventsAreGoneEntirely(): void
    {
        global $wpdb;

        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE customer_id = %d',
                Tables::name(Tables::CUSTOMER_EVENTS),
                $this->customerId
            )
        );

        self::assertSame(0, $count);
    }

    /**
     * The index outlives the value unless it is cleared too — and anyone
     * holding a ΑΦΜ could hash it and confirm the person was a customer here.
     */
    public function testTheBlindIndexIsClearedWithTheTaxNumber(): void
    {
        $customer = $this->storedRow(Tables::CUSTOMERS, $this->customerId);

        self::assertNull($customer[CustomerFields::INDEX_COLUMN]);
    }

    /** The one that was missed: a bank account inside a JSON column. */
    public function testTheExtrasBagLosesTheBankAccountAndKeepsTheMeterReading(): void
    {
        $contract = $this->storedRow(Tables::CONTRACTS, $this->contractId);
        $extras   = json_decode((string) $contract['extra_json'], true);

        self::assertIsArray($extras);
        self::assertArrayNotHasKey('iban', $extras);
        self::assertArrayNotHasKey('rep_last_name', $extras);
        self::assertArrayNotHasKey('contact_adt', $extras);
        self::assertSame('8', $extras['agreed_power'] ?? null);

        self::assertStringNotContainsString(self::IBAN, (string) $contract['extra_json']);
    }

    public function testTheContractKeepsItsShapeAndLosesItsProse(): void
    {
        $contract = $this->storedRow(Tables::CONTRACTS, $this->contractId);

        self::assertNull($contract['notes']);
        self::assertNull($contract['extracted_json']);
        self::assertNull($contract['consent_ip']);

        // Anonymised, not deleted: the company still counts what it signed.
        self::assertSame('signed', $contract['status']);
    }

    public function testTheSignatureImageAndTheAddressItCameFromAreGone(): void
    {
        global $wpdb;

        /** @var array<string, mixed>|null $signature */
        $signature = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE contract_id = %d',
                Tables::name(Tables::SIGNATURES),
                $this->contractId
            ),
            ARRAY_A
        );

        self::assertNotNull($signature);
        self::assertNull($signature['image']);
        self::assertNull($signature['signer_name']);
        self::assertNull($signature['ip']);
    }

    public function testTheLeadTheContractGrewOutOfIsAnonymised(): void
    {
        $lead = $this->rowByContract(Tables::LEADS);

        self::assertSame('—', $lead['name']);
        self::assertNull($lead['phone']);
        self::assertNull($lead['email']);
        self::assertNull($lead['notes']);
    }

    /** History stays, the prose in it does not — it quotes names and IPs. */
    public function testTheEventLogKeepsTheFactAndDropsTheWording(): void
    {
        $event = $this->rowByContract(Tables::EVENTS);

        self::assertNull($event['message']);
        self::assertSame('note', $event['type']);
    }

    public function testTasksLoseTheirTitleAndNote(): void
    {
        $task = $this->rowByContract(Tables::TASKS);

        self::assertSame('—', $task['title']);
        self::assertNull($task['note']);
    }

    /**
     * The catch-all. Every assertion above names a column somebody remembered;
     * this one fails if the name is anywhere at all.
     */
    public function testNothingAnywhereStillSpellsTheCustomersName(): void
    {
        global $wpdb;

        $tables = [
            Tables::CUSTOMERS  => 'id',
            Tables::CONTRACTS  => 'id',
            Tables::LEADS      => 'contract_id',
            Tables::EVENTS     => 'contract_id',
            Tables::TASKS      => 'contract_id',
            Tables::SIGNATURES => 'contract_id',
        ];

        foreach ($tables as $table => $keyColumn) {
            $id = $keyColumn === 'id' && $table === Tables::CUSTOMERS ? $this->customerId : $this->contractId;

            /** @var list<array<string, mixed>> $rows */
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE %i = %d',
                    Tables::name($table),
                    $keyColumn,
                    $id
                ),
                ARRAY_A
            );

            foreach ($rows as $row) {
                foreach ($row as $column => $value) {
                    self::assertStringNotContainsString(
                        'Παπαδόπουλ',
                        (string) $value,
                        "The customer's name survived in {$table}.{$column}."
                    );
                }
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function rowByContract(string $unprefixedTable): array
    {
        global $wpdb;

        /** @var array<string, mixed>|null $row */
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE contract_id = %d',
                Tables::name($unprefixedTable),
                $this->contractId
            ),
            ARRAY_A
        );

        self::assertNotNull($row, "No row for contract {$this->contractId} in {$unprefixedTable}.");

        return $row;
    }
}
