<?php

/**
 * (439) Η δουλειά ενός απενεργοποιημένου πάει στον πρώτο ενεργό από πάνω του.
 *
 * Έλεγχος λογικής 21/11: ο απενεργοποιημένος κρατά ρόλο και αιτήσεις αλλά δεν
 * μπαίνει. Οι αυτόματες εργασίες (κλήση ανανέωσης, απόρριψη/ακύρωση,
 * διπλοεγγραφή) γράφονταν στο όνομά του και δεν τις έβλεπε κανείς: ο πελάτης
 * έληγε χωρίς τηλέφωνο. Και ο διαχειριστής μπορούσε να δώσει εργασία με το
 * χέρι σε απενεργοποιημένο ή συνδρομητή (ίδιο κενό με το 436).
 *
 * @kanonas K6
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Domain\Contract\RenewalCall;
use EnergyCRM\Persistence\Tables;
use EnergyCRM\Persistence\TeamRepository;
use EnergyCRM\Services;
use WP_REST_Request;

final class DisabledOwnerWorkGoesUpTest extends IntegrationTestCase
{
    private TeamRepository $team;

    private int $top = 0;

    private int $manager = 0;

    private int $seller = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->team    = new TeamRepository();
        $this->top     = $this->makeCrmUser(Roles::PARTNER);
        $this->manager = $this->makeCrmUser(Roles::SELLER);
        $this->seller  = $this->makeCrmUser(Roles::SELLER);
        update_user_meta($this->manager, 'ecrm_parent', $this->top);
        update_user_meta($this->seller, 'ecrm_parent', $this->manager);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    public function testTheHandlerIsTheFirstActiveUserUpTheChain(): void
    {
        $network = Services::network();

        self::assertSame($this->seller, $network->handlerFor($this->seller));

        $this->team->setDisabled($this->seller, true);
        self::assertSame($this->manager, $network->handlerFor($this->seller));

        $this->team->setDisabled($this->manager, true);
        self::assertSame($this->top, $network->handlerFor($this->seller));

        $this->team->setDisabled($this->top, true);
        self::assertSame(0, $network->handlerFor($this->seller));
    }

    /** Η κλήση ανανέωσης δεν χάνεται στον απενεργοποιημένο πωλητή. */
    public function testARenewalCallGoesToTheManager(): void
    {
        $this->team->setDisabled($this->seller, true);
        $id = $this->contract('active');

        self::assertSame(1, Services::renewalCalls()->run(30));
        self::assertSame([$this->manager], $this->taskOwnersFor($id, RenewalCall::KIND));
    }

    /** Και η εργασία μετά από ακύρωση. */
    public function testACancellationTaskGoesToTheManager(): void
    {
        $this->team->setDisabled($this->seller, true);
        $id = $this->contract('cancelled');

        Services::rejectionFollowUp()->onStatusChanged($id, 'cancelled');

        self::assertSame([$this->manager], $this->taskOwnersFor($id));
    }

    /** Ο ενεργός πωλητής παίρνει τη δική του, όπως πριν. */
    public function testAnActiveSellerKeepsHisOwnWork(): void
    {
        $id = $this->contract('active');

        Services::renewalCalls()->run(30);

        self::assertSame([$this->seller], $this->taskOwnersFor($id, RenewalCall::KIND));
    }

    /** Με το χέρι: ο διαχειριστής δεν δίνει εργασία σε απενεργοποιημένο. */
    public function testAnAdministratorCannotAssignATaskToADisabledUser(): void
    {
        $admin = $this->makeCrmUser('administrator');
        $this->team->setDisabled($this->seller, true);
        wp_set_current_user($admin);

        $request = new WP_REST_Request('POST', '/ecrm/v1/tasks');
        $request->set_body_params(['title' => 'Τηλέφωνο', 'assigned_to' => $this->seller]);
        $response = rest_do_request($request);

        self::assertLessThan(300, $response->get_status());

        global $wpdb;
        $assigned = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT assigned_to FROM %i WHERE title = %s ORDER BY id DESC LIMIT 1',
            Tables::name(Tables::TASKS),
            'Τηλέφωνο'
        ));
        self::assertSame($admin, $assigned);
    }

    private function contract(string $status): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::CUSTOMERS), ['first_name' => 'Δοκιμή', 'last_name' => 'Πελάτης']);
        $customer = (int) $wpdb->insert_id;

        $wpdb->insert(Tables::name(Tables::CONTRACTS), [
            'partner_user_id' => $this->seller,
            'customer_id'     => $customer,
            'status'          => $status,
            'supply_number'   => '12345678901',
            'energy_type'     => 'power',
            'code'            => 'ECRM-DOW-' . wp_generate_password(8, false),
        ]);
        $id = (int) $wpdb->insert_id;

        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET end_date = CURDATE() + INTERVAL 20 DAY WHERE id = %d',
            Tables::name(Tables::CONTRACTS),
            $id
        ));

        return $id;
    }

    /** @return list<int> */
    private function taskOwnersFor(int $contractId, string $kind = ''): array
    {
        global $wpdb;

        /** @var list<array{assigned_to: string, kind: string|null}> $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT assigned_to, kind FROM %i WHERE contract_id = %d ORDER BY id',
            Tables::name(Tables::TASKS),
            $contractId
        ), ARRAY_A);

        $owners = [];

        foreach ((array) $rows as $row) {
            if ($kind === '' || (string) $row['kind'] === $kind) {
                $owners[] = (int) $row['assigned_to'];
            }
        }

        return $owners;
    }
}
