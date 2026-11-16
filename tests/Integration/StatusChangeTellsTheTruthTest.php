<?php

/**
 * (403) Η αλλαγή κατάστασης λέει την αλήθεια.
 *
 * Το `ContractLifecycle::moveTo()` αρνιόταν σιωπηλά (γύριζε false) μια
 * κατάσταση άλλου είδους αίτησης ή χωρίς υπογραφή, και οι δύο controllers
 * απαντούσαν «ΟΚ» με τη νέα κατάσταση: η λίστα την έδειχνε, η βάση όχι.
 * Βρέθηκε τοπικά 16/11: ρεύμα σε «Presale» → «Αναμονή SIM» = 200 ok, βάση
 * «presale». Και μια αίτηση που άλλαζε είδος έμενε σε κατάσταση που δεν
 * ανήκει στο νέο της είδος (39223: ρεύμα σε «Αναμονή παράδοσης SIM»).
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\DashboardRepository;
use EnergyCRM\Persistence\Tables;
use WP_REST_Request;
use WP_REST_Response;

final class StatusChangeTellsTheTruthTest extends IntegrationTestCase
{
    private int $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->makeCrmUser(Roles::SELLER);
        wp_set_current_user($this->owner);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    public function testAStatusOfAnotherTrackIsRefusedAndSaysWhy(): void
    {
        $id = $this->contract('presale', 'power');

        $response = $this->call('POST', '/contracts/' . $id . '/status', ['status' => 'awaiting_sim']);

        self::assertSame(422, $response->get_status(), 'Πριν: 200 ok με κατάσταση που δεν γράφτηκε.');
        self::assertStringContainsString('δεν ισχύει για αιτήσεις ρεύματος', (string) $response->get_data()['error']);
        self::assertSame('presale', $this->statusOf($id));
    }

    public function testAStatusThatFitsStillMoves(): void
    {
        $id = $this->contract('presale', 'power');

        $response = $this->call('POST', '/contracts/' . $id . '/status', ['status' => 'registration']);

        self::assertSame(200, $response->get_status());
        self::assertSame('registration', $this->statusOf($id));
    }

    public function testSavingTheFormWithAStatusOfAnotherTrackIsRefusedBeforeWriting(): void
    {
        $id = $this->contract('presale', 'power');

        $response = $this->call('POST', '/contracts', [
            'contract_id' => $id,
            'status'      => 'awaiting_sim',
            'notes'       => 'δεν πρέπει να γραφτεί',
        ]);

        self::assertSame(422, $response->get_status());
        self::assertSame('presale', $this->statusOf($id));
    }

    public function testChangingTheTrackUnderAStatusThatOnlyBelongsToTheOldOneIsRefused(): void
    {
        $id = $this->contract('awaiting_sim', 'mobile');

        $response = $this->call('POST', '/contracts', [
            'contract_id' => $id,
            'energy_type' => 'power',
        ]);

        self::assertSame(422, $response->get_status());
        self::assertStringContainsString('Άλλαξε πρώτα την κατάσταση', (string) $response->get_data()['error']);
        self::assertSame('mobile', (string) $this->storedRow('contracts', $id)['energy_type']);
        self::assertSame('awaiting_sim', $this->statusOf($id));
    }

    public function testChangingTheTrackUnderACommonStatusIsFine(): void
    {
        $id = $this->contract('presale', 'mobile');

        $response = $this->call('POST', '/contracts', [
            'contract_id' => $id,
            'energy_type' => 'power',
        ]);

        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));
        self::assertSame('power', (string) $this->storedRow('contracts', $id)['energy_type']);
    }

    /** (403) Τα πρόχειρα δεν μετρούν ως αιτήσεις στην Αρχική, όπως και στο Κατάστημα. */
    public function testDraftsAreNotCountedOnTheHomeScreen(): void
    {
        $this->contract('draft', 'power');
        $this->contract('presale', 'power');
        $this->contract('active', 'power');

        $dashboard = new DashboardRepository();
        $since     = '2000-01-01 00:00:00';
        $cards     = $dashboard->cards($this->owner, $since, $since, '1999-12-31 00:00:00');

        self::assertSame(2, $cards['today']);
        self::assertSame(2, $cards['month']);
        $byProvider = array_map('intval', array_column($dashboard->byProviderSince($this->owner, $since), 'c'));
        self::assertSame(2, array_sum($byProvider));

        $year = (int) current_time('Y');
        self::assertSame(2, array_sum($dashboard->monthlyTotals($this->owner, $year)));
    }

    private function contract(string $status, string $energy): int
    {
        $id = (new ContractRepository())->create(
            ['status' => $status, 'supply_number' => '12345678901', 'energy_type' => $energy],
            UserScope::forSelf($this->owner)
        );
        self::assertGreaterThan(0, $id);

        global $wpdb;
        $wpdb->update(Tables::name(Tables::CONTRACTS), ['status' => $status], ['id' => $id]);

        return $id;
    }

    private function statusOf(int $id): string
    {
        return (string) $this->storedRow('contracts', $id)['status'];
    }

    /** @param array<string, mixed> $params */
    private function call(string $method, string $route, array $params = []): WP_REST_Response
    {
        $request = new WP_REST_Request($method, '/ecrm/v1' . $route);

        if ($params !== []) {
            $method === 'GET' ? $request->set_query_params($params) : $request->set_body_params($params);
        }

        return rest_do_request($request);
    }
}
