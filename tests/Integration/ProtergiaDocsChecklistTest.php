<?php

/**
 * (432) Τα δικαιολογητικά της Protergia στο checklist και στην πύλη.
 *
 * Νέες αιτήσεις Protergia (από `ProtergiaDocs::FROM`) ακολουθούν τον πίνακα του
 * Back Office, με «ένα από» και υπενθυμίσεις. Όσες υπήρχαν ήδη μένουν στον
 * γενικό πίνακα, ώστε να μην κολλήσουν (απόφαση ιδιοκτήτη 20/11).
 *
 * @kanonas K4
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use ECRM_Docs;
use ECRM_Files;
use EnergyCRM\Access\Roles;
use EnergyCRM\Domain\Document\ProtergiaDocs;
use EnergyCRM\Persistence\FileRepository;
use EnergyCRM\Persistence\Tables;

final class ProtergiaDocsChecklistTest extends IntegrationTestCase
{
    private int $provider = 0;

    private int $owner = 0;

    protected function setUp(): void
    {
        global $wpdb;

        parent::setUp();

        $this->owner = $this->makeCrmUser(Roles::SELLER);

        $table          = Tables::name(Tables::PROVIDERS);
        $this->provider = (int) $wpdb->get_var(
            $wpdb->prepare('SELECT id FROM %i WHERE slug = %s', $table, ProtergiaDocs::SLUG)
        );

        if ($this->provider <= 0) {
            $wpdb->insert($table, ['slug' => ProtergiaDocs::SLUG, 'name' => 'Protergia']);
            $this->provider = (int) $wpdb->insert_id;
        }
    }

    /** Επανασύνδεση: φωτογραφία μετρητή, και ένα από Ε9 / μισθωτήριο / λογαριασμό. */
    public function testANewReconnectionFollowsTheProtergiaTable(): void
    {
        $id = $this->contract('reconnection', '2026-11-22 10:00:00');

        $this->attach($id, 'id_card');
        $this->attach($id, 'lease');

        $check = ECRM_Docs::checklist($id, 'reconnection', 'power');

        self::assertFalse($check['complete']);
        self::assertSame(['Φωτογραφία μετρητή'], ECRM_Docs::missing_labels($id, 'reconnection', 'power'));
        self::assertNotEmpty($check['reminders'] ?? []);

        $this->attach($id, 'meter_photo');

        self::assertTrue(ECRM_Docs::checklist($id, 'reconnection', 'power')['complete']);
    }

    /** Ενδείξεις: αρκεί να είναι γραμμένες στη φόρμα, χωρίς φωτογραφία. */
    public function testReadingsCanBeTypedInsteadOfPhotographed(): void
    {
        $id = $this->contract('change_provider', '2026-11-22 10:00:00', ['day_indication' => '12345']);

        $this->attach($id, 'id_card');
        $this->attach($id, 'provider_bill');

        self::assertTrue(ECRM_Docs::checklist($id, 'change_provider', 'power')['complete']);
    }

    /** Όσες υπήρχαν πριν δεν κολλάνε: γενικός πίνακας (ταυτότητα + λογαριασμός). */
    public function testAnOlderContractKeepsTheGeneralList(): void
    {
        $id = $this->contract('reconnection', '2026-11-19 10:00:00');

        $this->attach($id, 'id_card');
        $this->attach($id, 'provider_bill');

        $check = ECRM_Docs::checklist($id, 'reconnection', 'power');

        self::assertTrue($check['complete']);
        self::assertArrayNotHasKey('reminders', $check);
    }

    /**
     * @param array<string, string> $extra
     */
    private function contract(string $activation, string $createdAt, array $extra = []): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::CONTRACTS), [
            'partner_user_id' => $this->owner,
            'provider_id'     => $this->provider,
            'status'          => 'registration',
            'code'            => 'PRT-' . wp_generate_password(6, false),
            'energy_type'     => 'power',
            'category'        => 'home',
            'customer_type'   => 'individual',
            'activation_type' => $activation,
            'extra_json'      => $extra === [] ? null : (string) wp_json_encode($extra),
            'created_at'      => $createdAt,
        ]);

        return (int) $wpdb->insert_id;
    }

    private function attach(int $contractId, string $kind): void
    {
        $bytes = 'fixture ' . wp_generate_password(8, false);
        $saved = ECRM_Files::put_bytes($bytes, 'jpg', 'image/jpeg', $kind . '.jpg');
        self::assertIsArray($saved);

        $fileId = (new FileRepository(ECRM_Files::dir()))
            ->attach($contractId, $kind, $kind . '.jpg', 'image/jpeg', (string) $saved['path']);
        self::assertGreaterThan(0, $fileId);
    }
}
