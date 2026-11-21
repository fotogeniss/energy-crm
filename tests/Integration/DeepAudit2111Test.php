<?php

/**
 * (442) Βαθύς έλεγχος 21/11: διαγραφή, απενεργοποίηση.
 *
 * - Διαγραφή: παλιές αιτήσεις και εισαγωγές δεν έχουν γεγονός «Ενεργή» ούτε
 *   signed_at, οπότε διαγράφονταν ενώ ήταν Ενεργές ή μέσα σε παρτίδα
 *   εκκαθάρισης.
 * - Απενεργοποίηση: απόφαση ιδιοκτήτη 21/11, οι ανοιχτές εργασίες και τα
 *   ανοιχτά leads πάνε σε αυτόν που απενεργοποίησε.
 *
 * @kanonas K5
 * @kanonas K6
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Capability;
use EnergyCRM\Access\Roles;
use EnergyCRM\Domain\Contract\DeletionGate;
use EnergyCRM\Persistence\Tables;
use EnergyCRM\Persistence\TeamRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

final class DeepAudit2111Test extends IntegrationTestCase
{
    private int $manager = 0;

    private int $seller = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = $this->makeCrmUser(Roles::PARTNER);
        $this->seller  = $this->makeCrmUser(Roles::SELLER);
        (new TeamRepository())->attach($this->seller, $this->manager);

        $user = get_user_by('id', $this->manager);
        self::assertInstanceOf(WP_User::class, $user);
        $user->add_cap(Capability::DELETE_CONTRACT);

        wp_set_current_user($this->manager);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    /** Ενεργή χωρίς γεγονός και χωρίς υπογραφή (παλιά/εισαγωγή): δεν σβήνεται. */
    public function testAnActiveContractWithoutHistoryIsNotDeleted(): void
    {
        $id = $this->contract(['status' => 'active']);

        $response = $this->delete($id);

        self::assertSame(409, $response->get_status());
        self::assertSame(DeletionGate::WAS_ACTIVE, $response->get_data()['error'] ?? null);
    }

    /** Μέσα σε παρτίδα εκκαθάρισης: δεν σβήνεται. */
    public function testAContractInAPayoutIsNotDeleted(): void
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::PAYOUTS), [
            'partner_user_id' => $this->manager,
            'period'          => '2026-10',
            'cnt'             => 1,
            'amount'          => 50,
            'status'          => 'paid',
        ]);
        $payout = (int) $wpdb->insert_id;

        $id = $this->contract(['status' => 'cancelled', 'payout_id' => $payout]);

        $response = $this->delete($id);

        self::assertSame(409, $response->get_status());
        self::assertSame(DeletionGate::IN_PAYOUT, $response->get_data()['error'] ?? null);
    }

    /** Πρόχειρο χωρίς τίποτα από τα παραπάνω: σβήνεται κανονικά. */
    public function testAPlainDraftIsStillDeleted(): void
    {
        $id = $this->contract(['status' => 'draft']);

        self::assertSame(200, $this->delete($id)->get_status());
    }

    /** Απενεργοποίηση: ανοιχτές εργασίες και ανοιχτά leads πάνε στον προϊστάμενο. */
    public function testDisablingHandsOverOpenTasksAndLeads(): void
    {
        global $wpdb;

        $tasks = Tables::name(Tables::TASKS);
        $leads = Tables::name(Tables::LEADS);

        $wpdb->insert($tasks, ['assigned_to' => $this->seller, 'created_by' => 0, 'title' => 'Α', 'status' => 'open']);
        $open = (int) $wpdb->insert_id;
        $wpdb->insert($tasks, ['assigned_to' => $this->seller, 'created_by' => 0, 'title' => 'Β', 'status' => 'done']);
        $done = (int) $wpdb->insert_id;

        $wpdb->insert($leads, ['partner_user_id' => $this->seller, 'name' => 'Νέο', 'stage' => 'callback']);
        $openLead = (int) $wpdb->insert_id;
        $wpdb->insert($leads, ['partner_user_id' => $this->seller, 'name' => 'Κλειστό', 'stage' => 'won']);
        $wonLead = (int) $wpdb->insert_id;

        $request = new WP_REST_Request('POST', '/ecrm/v1/team/' . $this->seller);
        $request->set_body_params(['op' => 'toggle']);
        $response = rest_do_request($request);

        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));
        self::assertTrue((new TeamRepository())->isDisabled($this->seller));

        self::assertSame($this->manager, $this->ownerOf($tasks, 'assigned_to', $open));
        self::assertSame($this->seller, $this->ownerOf($tasks, 'assigned_to', $done));
        self::assertSame($this->manager, $this->ownerOf($leads, 'partner_user_id', $openLead));
        self::assertSame($this->seller, $this->ownerOf($leads, 'partner_user_id', $wonLead));
    }

    /** @param array<string, mixed> $data */
    private function contract(array $data): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::CONTRACTS), $data + [
            'partner_user_id' => $this->seller,
            'supply_number'   => '12345678901',
            'energy_type'     => 'power',
            'code'            => 'ECRM-DEL-' . wp_generate_password(6, false),
        ]);

        return (int) $wpdb->insert_id;
    }

    private function delete(int $id): WP_REST_Response
    {
        return rest_do_request(new WP_REST_Request('DELETE', '/ecrm/v1/contracts/' . $id));
    }

    private function ownerOf(string $table, string $column, int $id): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare('SELECT %i FROM %i WHERE id = %d', $column, $table, $id));
    }
}
