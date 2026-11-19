<?php

/**
 * (422) Η μετάπτωση 0065 ξαναϋπολογίζει το `phone_hash` από τα ψηφία, χωρίς
 * να αγγίξει το ίδιο το τηλέφωνο, και δεν γράφει τίποτα με λάθος κλειδί.
 *
 * @kanonas K10
 * @kanonas K11
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Infrastructure\KeyFingerprint;
use EnergyCRM\Infrastructure\RotatedKey;
use EnergyCRM\Persistence\CustomerFields;
use EnergyCRM\Persistence\CustomerRepository;
use EnergyCRM\Persistence\Schema\Migrations\RehashCustomerPhone;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class RehashCustomerPhoneTest extends IntegrationTestCase
{
    private const TYPED = '697 123 4567';

    private CustomerFields $fields;

    private int $customerId;

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;

        $this->encryptionOn();
        delete_option(KeyFingerprint::OPTION);

        $this->fields     = CustomerFields::default();
        $this->customerId = (new CustomerRepository())->create([
            'first_name' => 'Παλιό',
            'last_name'  => 'Hash',
            'phone'      => self::TYPED,
        ]);

        // Όπως ήταν πριν την (422): hash της τιμής όπως γράφτηκε.
        $wpdb->update(
            Tables::name(Tables::CUSTOMERS),
            [CustomerFields::PHONE_INDEX_COLUMN => $this->fields->index(self::TYPED)],
            ['id' => $this->customerId]
        );
    }

    protected function tearDown(): void
    {
        delete_option(KeyFingerprint::OPTION);

        parent::tearDown();
    }

    public function testTheHashIsRebuiltFromTheDigitsAndThePhoneStays(): void
    {
        $before = $this->storedRow('customers', $this->customerId);

        (new RehashCustomerPhone())->apply(new SchemaInspector());

        $after = $this->storedRow('customers', $this->customerId);
        self::assertSame($this->fields->phoneIndex('6971234567'), $after[CustomerFields::PHONE_INDEX_COLUMN]);
        self::assertSame($this->fields->phoneIndex('+30 697-123-4567'), $after[CustomerFields::PHONE_INDEX_COLUMN]);
        self::assertSame($before['phone'], $after['phone'], 'Το τηλέφωνο άλλαξε· έπρεπε μόνο το hash.');
    }

    public function testUnderAnotherKeyNothingIsWritten(): void
    {
        update_option(KeyFingerprint::OPTION, str_repeat('b', 64));
        self::assertFalse(KeyFingerprint::default()->matches());

        try {
            (new RehashCustomerPhone())->apply(new SchemaInspector());
            self::fail('Η μετάπτωση έτρεξε με λάθος κλειδί.');
        } catch (RotatedKey) {
            // Αναμενόμενο: ο runner θα την ξαναδοκιμάσει.
        }

        self::assertSame(
            $this->fields->index(self::TYPED),
            $this->storedRow('customers', $this->customerId)[CustomerFields::PHONE_INDEX_COLUMN]
        );
    }
}
