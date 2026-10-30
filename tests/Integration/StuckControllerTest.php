<?php

/**
 * (338) GET /stuck -- «Κολλημένες αιτήσεις».
 *
 * Φυλάει τρία πράγματα: το scope (ο καθένας μόνο όσα βλέπει ήδη), τον κανόνα
 * (μέρες στην ΚΑΤΑΣΤΑΣΗ, διπλάσιο του συνηθισμένου, αλλιώς σταθερό όριο) και
 * την ομαδοποίηση που ζητά η οθόνη για κάθε ρόλο.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use ECRM_Notifications;
use EnergyCRM\Access\Roles;
use EnergyCRM\Persistence\StatusDwellRepository;
use EnergyCRM\Persistence\Tables;
use EnergyCRM\Persistence\TeamRepository;
use WP_REST_Request;
use WP_REST_Response;

final class StuckControllerTest extends IntegrationTestCase
{
    private int $providerA;

    private int $providerB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->providerA = $this->provider('Πάροχος Α');
        $this->providerB = $this->provider('Πάροχος Β');
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    public function testASellerSeesOnlyTheirOwnStuckApplications(): void
    {
        $limit  = ECRM_Notifications::escalation_days();
        $seller = $this->makeCrmUser(Roles::SELLER);
        $other  = $this->makeCrmUser(Roles::SELLER);

        $mine   = $this->sitting($seller, 'registration', $limit + 2, $this->providerA);
        $theirs = $this->sitting($other, 'registration', $limit + 2, $this->providerA);

        wp_set_current_user($seller);
        $data = $this->stuck()->get_data();
        $ids  = array_column($data['rows'], 'id');

        self::assertSame('none', $data['group']);
        self::assertContains($mine, $ids);
        self::assertNotContains($theirs, $ids);
    }

    /**
     * Μετράνε οι μέρες στην κατάσταση, όχι το τελευταίο άγγιγμα: μια αίτηση
     * που άλλαξε χθες κάτι άσχετο αλλά κάθεται στην ίδια κατάσταση 3 εβδομάδες
     * είναι κολλημένη.
     */
    public function testATouchDoesNotResetTheDaysInStatus(): void
    {
        global $wpdb;

        $limit  = ECRM_Notifications::escalation_days();
        $seller = $this->makeCrmUser(Roles::SELLER);
        $id     = $this->sitting($seller, 'registration', $limit + 5, $this->providerA);

        $wpdb->update(Tables::name(Tables::CONTRACTS), ['supply_number' => '99999999999'], ['id' => $id]);

        wp_set_current_user($seller);
        $row = $this->row($id);

        self::assertNotNull($row);
        self::assertSame($limit + 5, $row['days']);
        self::assertFalse($row['compared']);
        self::assertSame($limit, $row['limit']);
    }

    public function testBelowTheLimitWithoutAComparisonNothingIsShown(): void
    {
        $limit  = ECRM_Notifications::escalation_days();
        $seller = $this->makeCrmUser(Roles::SELLER);
        $fresh  = $this->sitting($seller, 'registration', max(0, $limit - 1), $this->providerA);

        wp_set_current_user($seller);

        self::assertNull($this->row($fresh));
    }

    public function testWithASampleTheProvidersTypicalTimeDecides(): void
    {
        $seller = $this->makeCrmUser(Roles::SELLER);

        // Δέκα αιτήσεις του παρόχου Β πέρασαν από «finalisation» σε 2 μέρες.
        for ($i = 0; $i < StatusDwellRepository::MIN_SAMPLE; $i++) {
            $done = $this->contract($seller, 'active', $this->providerB);
            $this->moved($done, 'finalisation', 30);
            $this->moved($done, 'active', 28);
        }

        $late   = $this->sitting($seller, 'finalisation', 5, $this->providerB);
        $onTime = $this->sitting($seller, 'finalisation', 3, $this->providerB);

        wp_set_current_user($seller);
        $row = $this->row($late);

        self::assertNotNull($row, '5 μέρες ≥ 2 × 2');
        self::assertTrue($row['compared']);
        self::assertSame(2, $row['typical']);
        self::assertNull($this->row($onTime), '3 μέρες < 4');
    }

    public function testTheBackOfficeSeesTheWholeCompanyByProvider(): void
    {
        $limit  = ECRM_Notifications::escalation_days();
        $office = $this->makeCrmUser(Roles::BACKOFFICE);
        $a      = $this->sitting($this->makeCrmUser(Roles::SELLER), 'registration', $limit + 1, $this->providerA);
        $b      = $this->sitting($this->makeCrmUser(Roles::SELLER), 'registration', $limit + 3, $this->providerB);

        wp_set_current_user($office);
        $data = $this->stuck()->get_data();
        $ids  = array_column($data['rows'], 'id');

        self::assertSame('provider', $data['group']);
        self::assertContains($a, $ids);
        self::assertContains($b, $ids);
        self::assertLessThan(array_search($a, $ids, true), array_search($b, $ids, true), 'πιο παλιά πρώτα');
        self::assertSame('Πάροχος Β', $this->row($b)['provider'] ?? null);
    }

    public function testAManagerSeesTheirTeamByOwner(): void
    {
        $limit    = ECRM_Notifications::escalation_days();
        $manager  = $this->makeCrmUser(Roles::PARTNER);
        $member   = $this->makeCrmUser(Roles::SELLER);
        $stranger = $this->makeCrmUser(Roles::SELLER);
        update_user_meta($member, TeamRepository::PARENT_META, $manager);

        $ours   = $this->sitting($member, 'registration', $limit + 1, $this->providerA);
        $theirs = $this->sitting($stranger, 'registration', $limit + 1, $this->providerA);

        wp_set_current_user($manager);
        $data = $this->stuck()->get_data();
        $ids  = array_column($data['rows'], 'id');

        self::assertSame('owner', $data['group']);
        self::assertContains($ours, $ids);
        self::assertNotContains($theirs, $ids);
        self::assertSame(get_userdata($member)->display_name, $this->row($ours)['owner_name'] ?? null);
    }

    /** Ενεργές και ακυρωμένες έχουν τελειώσει· δεν «κάθονται». */
    public function testSettledContractsAreNeverStuck(): void
    {
        $seller = $this->makeCrmUser(Roles::SELLER);
        $active = $this->sitting($seller, 'active', 300, $this->providerA);

        wp_set_current_user($seller);

        self::assertNull($this->row($active));
    }

    public function testAnonymousIsRefused(): void
    {
        wp_set_current_user(0);

        self::assertSame(401, $this->stuck()->get_status());
    }

    private function stuck(): WP_REST_Response
    {
        return rest_do_request(new WP_REST_Request('GET', '/ecrm/v1/stuck'));
    }

    /** @return array<string, mixed>|null */
    private function row(int $id): ?array
    {
        foreach ($this->stuck()->get_data()['rows'] as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    private function provider(string $name): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::PROVIDERS), [
            'slug' => sanitize_title($name) . '-' . wp_generate_password(6, false),
            'name' => $name,
        ]);

        return (int) $wpdb->insert_id;
    }

    private function contract(int $owner, string $status, int $providerId): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::CONTRACTS), [
            'partner_user_id' => $owner,
            'provider_id'     => $providerId,
            'status'          => $status,
            'supply_number'   => '12345678901',
            'energy_type'     => 'power',
            'code'            => 'ECRM-STK-' . wp_generate_password(8, false),
        ]);

        return (int) $wpdb->insert_id;
    }

    /** Αίτηση που μπήκε στην κατάσταση πριν από N μέρες και είναι ακόμα εκεί. */
    private function sitting(int $owner, string $status, int $days, int $providerId): int
    {
        $id = $this->contract($owner, $status, $providerId);
        $this->moved($id, $status, $days);

        return $id;
    }

    private function moved(int $contractId, string $to, int $daysAgo): void
    {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO %i (contract_id, user_id, type, to_status, created_at)
                 VALUES (%d, 0, %s, %s, NOW() - INTERVAL %d DAY)',
                Tables::name(Tables::EVENTS),
                $contractId,
                'status_change',
                $to,
                $daysAgo
            )
        );
    }
}
