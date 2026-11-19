<?php

/**
 * (422) Ξαναϋπολογίζει το `phone_hash` κάθε πελάτη με τον νέο κανόνα
 * (`PhoneNumber::key()`: μόνο ψηφία, χωρίς +30/0030).
 *
 * Τα υπάρχοντα hash έχουν φτιαχτεί από την τιμή όπως γράφτηκε. Χωρίς αυτό, ο
 * πελάτης που καταχωρήθηκε με «697 123 4567» θα έβρισκε μόνο με τα κενά,
 * δηλαδή χειρότερα από πριν.
 *
 * Κανόνας K10: πρώτα ελέγχεται ότι το κλειδί είναι το σωστό
 * (`KeyFingerprint::assertUsable()`). Με άλλα salts η αποκρυπτογράφηση δίνει
 * κενό και το hash βγαίνει με λάθος κλειδί: η μετάπτωση σταματά χωρίς να
 * γράψει τίποτα, και ο runner την ξαναδοκιμάζει. Τηλέφωνο κρυπτογραφημένο που
 * δεν ανοίγει μένει με το hash που είχε.
 *
 * Το ίδιο το τηλέφωνο δεν αλλάζει (K7): αλλάζει μόνο το hash.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Infrastructure\FieldCipher;
use EnergyCRM\Infrastructure\KeyFingerprint;
use EnergyCRM\Persistence\CustomerFields;
use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class RehashCustomerPhone implements Migration
{
    public function id(): string
    {
        return '0065_rehash_customer_phone';
    }

    public function description(): string
    {
        return 'Ξαναϋπολογίζει το phone_hash από τα ψηφία του τηλεφώνου (422)';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $table = Tables::name(Tables::CUSTOMERS);

        if (! $schema->hasTable($table) || ! $schema->hasColumn($table, CustomerFields::PHONE_INDEX_COLUMN)) {
            return;
        }

        KeyFingerprint::assertUsable();

        $fields = CustomerFields::default();

        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT id, phone FROM %i WHERE phone IS NOT NULL AND phone <> ''", $table),
            ARRAY_A
        );

        foreach ($rows as $row) {
            $stored = (string) $row['phone'];
            $plain  = (string) ($fields->fromStorage(['phone' => $stored])['phone'] ?? '');

            if ($plain === '' && FieldCipher::isEncrypted($stored)) {
                continue;
            }

            $wpdb->update(
                $table,
                [CustomerFields::PHONE_INDEX_COLUMN => $fields->phoneIndex($plain)],
                ['id' => (int) $row['id']]
            );
        }
    }
}
