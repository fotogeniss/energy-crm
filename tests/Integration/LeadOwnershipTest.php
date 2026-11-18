<?php

/**
 * (418) Το lead ενός πωλητή, όταν το μετατρέπει ο μάνατζερ, γίνεται αίτηση
 * του πωλητή (και η προμήθεια μαζί), όχι του μάνατζερ.
 *
 * @kanonas K6
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\LeadRepository;
use EnergyCRM\Persistence\TeamRepository;
use WP_REST_Request;

final class LeadOwnershipTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    public function testTheManagerConvertsButTheSellerOwns(): void
    {
        $manager = $this->makeCrmUser(Roles::PARTNER);
        $seller  = $this->makeCrmUser(Roles::SELLER);
        (new TeamRepository())->attach($seller, $manager);

        $leadId = (new LeadRepository())->create(
            ['name' => 'Μαρία Υποψήφια', 'phone' => '6912345678'],
            UserScope::forSelf($seller)
        );
        self::assertGreaterThan(0, $leadId);

        wp_set_current_user($manager);
        $response = rest_do_request(new WP_REST_Request('POST', '/ecrm/v1/leads/' . $leadId . '/convert'));

        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));
        $contract = $this->storedRow('contracts', (int) $response->get_data()['contract_id']);
        self::assertSame($seller, (int) $contract['partner_user_id']);
        self::assertNotSame('', (string) $contract['code'], 'Ο κωδικός δεν γράφτηκε.');
    }
}
