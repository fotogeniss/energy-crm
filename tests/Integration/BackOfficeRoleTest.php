<?php

/**
 * (318) Ο ρόλος «Back office»: βλέπει ΟΛΕΣ τις αιτήσεις, κανένα ποσό.
 *
 * Δύο υποσχέσεις, και η μία μπορεί να σπάσει την άλλη σιωπηλά. Το Back office
 * παίρνει την ορατότητα του διαχειριστή (UserScope::forAdministrator) — κάθε
 * οθόνη που ως τώρα έδειχνε ποσά «σε όποιον βλέπει τον συνεργάτη» θα τα έδειχνε
 * τώρα και σε αυτό. Εδώ ελέγχεται κάθε σημείο όπου η ορατότητα και τα χρήματα
 * ήταν δεμένα: καρτέλα σύμβασης, καρτέλα συνεργάτη, Στατιστικά, Αρχική,
 * Προμήθειες, Εκκαθαρίσεις. Και, για αντίστιξη, ότι ο διαχειριστής και ο
 * ίδιος ο συνεργάτης βλέπουν ακόμα ό,τι έβλεπαν.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Capability;
use EnergyCRM\Access\Roles;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\CustomerRepository;
use WP_REST_Request;
use WP_User;

final class BackOfficeRoleTest extends IntegrationTestCase
{
    private int $partner;

    private int $backOffice;

    private int $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->partner    = $this->makeCrmUser(Roles::PARTNER);
        $this->backOffice = $this->makeCrmUser(Roles::BACKOFFICE);

        $customerId = (new CustomerRepository())->create($this->customerData());

        // Ενεργή = πληρωτέα, ώστε να μπαίνει και στην κατάταξη και στις προμήθειες.
        $this->contract = (new ContractRepository())->create(
            ['status' => 'active', 'customer_id' => $customerId],
            UserScope::forSelf($this->partner)
        );

        self::assertGreaterThan(0, $this->contract, 'Το fixture της σύμβασης δεν μπήκε.');
    }

    public function testTheRoleHasNoMoneyAndNoTeamManagement(): void
    {
        wp_set_current_user($this->backOffice);

        self::assertTrue(current_user_can(Capability::VIEW_ALL));
        self::assertFalse(current_user_can(Capability::VIEW_COMMISSIONS));
        self::assertFalse(current_user_can(Capability::MANAGE_TEAM));
        self::assertFalse(current_user_can('manage_options'), 'Το Back office δεν είναι διαχειριστής WordPress.');
    }

    public function testItSeesAnotherPartnersContractButNotItsPayout(): void
    {
        wp_set_current_user($this->backOffice);

        $contract = $this->get('/ecrm/v1/contracts/' . $this->contract)['contract'];

        self::assertSame($this->contract, (int) $contract['id']);
        self::assertArrayNotHasKey('payout_id', $contract);
        self::assertArrayNotHasKey('payout_amount', $contract);
    }

    public function testTheOwnerStillGetsThePayoutFields(): void
    {
        wp_set_current_user($this->partner);

        $contract = $this->get('/ecrm/v1/contracts/' . $this->contract)['contract'];

        self::assertArrayHasKey('payout_id', $contract);
        self::assertArrayHasKey('payout_amount', $contract);
    }

    public function testItSeesTheContractInTheTeamList(): void
    {
        wp_set_current_user($this->backOffice);

        $request = new WP_REST_Request('GET', '/ecrm/v1/contracts');
        $request->set_param('scope', 'team');
        $response = rest_do_request($request);

        self::assertSame(200, $response->get_status());
        self::assertContains(
            $this->contract,
            array_map('intval', array_column($response->get_data()['rows'], 'id')),
            'Το Back office πρέπει να βλέπει τις αιτήσεις όλων.'
        );
    }

    public function testCommissionsAndPayoutsAreClosedToIt(): void
    {
        wp_set_current_user($this->backOffice);

        foreach (['/ecrm/v1/commissions', '/ecrm/v1/payouts'] as $route) {
            $status = rest_do_request(new WP_REST_Request('GET', $route))->get_status();
            self::assertSame(403, $status, "Το {$route} άνοιξε στο Back office.");
        }
    }

    public function testThePartnerCardCarriesNoAmounts(): void
    {
        wp_set_current_user($this->backOffice);

        $data = $this->get('/ecrm/v1/team/' . $this->partner);

        self::assertNull($data['kpi']['commission']);
        self::assertNull($data['commission_rows']);
        self::assertArrayHasKey('month', $data['kpi'], 'Οι υπόλοιποι μετρητές μένουν.');
    }

    public function testTheAdministratorStillSeesTheAmountsOnTheCard(): void
    {
        wp_set_current_user($this->administrator());

        $data = $this->get('/ecrm/v1/team/' . $this->partner);

        self::assertIsArray($data['kpi']['commission']);
        self::assertIsArray($data['commission_rows']);
    }

    public function testTheLeaderboardIsCompanyWideAndHasNoAmounts(): void
    {
        wp_set_current_user($this->backOffice);

        $request = new WP_REST_Request('GET', '/ecrm/v1/analytics');
        $request->set_param('scope', 'team');
        $data = $this->dispatch($request);

        self::assertTrue($data['can_team'], 'Το Back office πρέπει να έχει προβολή «Ομάδας».');
        self::assertSame('team', $data['scope']);
        self::assertNotSame([], $data['leaderboard'], 'Η σύμβαση του συνεργάτη δεν μετρήθηκε.');

        foreach ($data['leaderboard'] as $row) {
            self::assertSame(['name', 'count'], array_keys($row));
        }
    }

    public function testTheAdministratorsLeaderboardKeepsTheAmount(): void
    {
        wp_set_current_user($this->administrator());

        $request = new WP_REST_Request('GET', '/ecrm/v1/analytics');
        $request->set_param('scope', 'team');
        $data = $this->dispatch($request);

        self::assertNotSame([], $data['leaderboard']);
        self::assertSame(['name', 'count', 'amount'], array_keys($data['leaderboard'][0]));
    }

    public function testTheHomeTilesCarryNoCommission(): void
    {
        wp_set_current_user($this->backOffice);

        $tiles = $this->get('/ecrm/v1/dashboard')['tiles'];

        self::assertArrayHasKey('closed_month', $tiles);
        self::assertArrayNotHasKey('closed_month_commission', $tiles);
    }

    /** Η καρτέλα μέλους λέει ό,τι ισχύει στα αιτήματά του: όλοι οι πάροχοι. */
    public function testItSeesEveryProviderWithoutAGrantList(): void
    {
        $visibility = \EnergyCRM\Services::providerVisibility();

        self::assertTrue($visibility->forUser($this->backOffice)->isUnrestricted());
    }

    public function testItCannotAddTeamMembers(): void
    {
        wp_set_current_user($this->backOffice);

        $request = new WP_REST_Request('POST', '/ecrm/v1/team');
        $request->set_param('name', 'Νέος');
        $request->set_param('email', 'neos.' . wp_generate_password(6, false) . '@example.test');

        self::assertSame(403, rest_do_request($request)->get_status());
    }

    // --- Fixtures ----------------------------------------------------------

    /** @return array<string, mixed> */
    private function get(string $route): array
    {
        return $this->dispatch(new WP_REST_Request('GET', $route));
    }

    /** @return array<string, mixed> */
    private function dispatch(WP_REST_Request $request): array
    {
        $response = rest_do_request($request);

        self::assertSame(200, $response->get_status(), $request->get_route() . ' δεν πέρασε τους φύλακες.');

        /** @var array<string, mixed> $data */
        $data = $response->get_data();

        return $data;
    }

    private function administrator(): int
    {
        $userId = $this->makePartner();
        $user   = get_user_by('id', $userId);
        self::assertInstanceOf(WP_User::class, $user);
        $user->set_role('administrator');

        return $userId;
    }
}
