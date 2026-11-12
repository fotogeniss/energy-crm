<?php

/**
 * (362) Η «Βάση Γνώσης» στη νέα εμφάνιση δείχνει το λογότυπο κάθε παρόχου
 * στη λίστα αριστερά και στον φάκελό του. Το λογότυπο έρχεται στην ομάδα
 * του παρόχου, όχι σε κάθε ενότητα.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Persistence\Tables;
use WP_REST_Request;

final class KnowledgeBaseLogoTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    public function testEachProviderGroupCarriesItsLogo(): void
    {
        global $wpdb;

        $slug = 'ecrm-kb-' . wp_generate_password(6, false);
        $wpdb->insert(Tables::name(Tables::PROVIDERS), [
            'slug'       => $slug,
            'name'       => 'Πάροχος ' . $slug,
            'active'     => 1,
            'sort_order' => 0,
            'logo_url'   => 'https://example.test/kb.png',
        ]);
        $providerId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $providerId, 'Το provider fixture δεν μπήκε.');

        $title = 'Δικαιολογητικά ' . $slug;
        $wpdb->insert(Tables::name(Tables::KB_ENTRIES), [
            'provider_id'   => $providerId,
            'provider_name' => 'Πάροχος ' . $slug,
            'section'       => 'docs',
            'title'         => $title,
            'body'          => 'Ταυτότητα και λογαριασμός.',
        ]);
        self::assertGreaterThan(0, (int) $wpdb->insert_id, 'Η ενότητα δεν μπήκε.');

        wp_set_current_user($this->makeCrmUser(Roles::SELLER));

        $request = new WP_REST_Request('GET', '/ecrm/v1/kb');
        $request->set_query_params(['provider' => $providerId]);
        $response = rest_do_request($request);
        self::assertSame(200, $response->get_status());

        /** @var array{groups: list<array<string, mixed>>} $data */
        $data = $response->get_data();
        self::assertCount(1, $data['groups']);
        self::assertSame('https://example.test/kb.png', $data['groups'][0]['logo'] ?? null);
        self::assertSame($providerId, $data['groups'][0]['provider_id'] ?? null);
    }
}
