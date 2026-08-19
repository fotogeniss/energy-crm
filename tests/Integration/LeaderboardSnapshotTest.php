<?php

/**
 * Η κατάταξη της ομάδας μετράει ό,τι πληρώθηκε, όχι ό,τι θα πληρωνόταν σήμερα.
 *
 * Ήταν το τέταρτο σημείο που έπαιρνε την ίδια απόφαση και το μόνο που την
 * έπαιρνε λάθος. Οι άλλες τρεις διορθώθηκαν 18/08/2026 — οθόνη προμηθειών,
 * βεβαίωση PDF, ακύρωση παρτίδας — και στην αναφορά εκείνης της μέρας έγραψα
 * «τρία σημεία διάβαζαν λάθος, όχι ένα». Ήταν τέσσερα. Το τέταρτο δεν
 * αναφέρει καν τη λέξη `payout_amount`, οπότε καμία αναζήτηση δεν το έβρισκε.
 *
 * Η κατάταξη είναι και το χειρότερο σημείο για να διαφωνεί ένας αριθμός: είναι
 * εκεί που οι άνθρωποι συγκρίνονται μεταξύ τους.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\TeamRepository;
use EnergyCRM\Persistence\Tables;
use WP_REST_Request;

final class LeaderboardSnapshotTest extends IntegrationTestCase
{
    /** Ποσό που κανένας υπολογισμός δεν παράγει, με άδειους κανόνες. */
    private const SNAPSHOT = 777.77;

    private ContractRepository $contracts;

    private int $manager;

    private int $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contracts = new ContractRepository();

        $this->manager = $this->makeCrmUser(Roles::PARTNER);
        $this->member  = $this->makeCrmUser(Roles::SELLER);

        (new TeamRepository())->attach($this->member, $this->manager);

        wp_set_current_user($this->manager);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    /** Σφραγισμένη σύμβαση: η κατάταξη διαβάζει το ποσό της παρτίδας. */
    public function testTheLeaderboardUsesTheSettledAmount(): void
    {
        $this->settle($this->payableContract($this->member), self::SNAPSHOT);

        self::assertSame(self::SNAPSHOT, $this->amountFor($this->member));
    }

    /**
     * Ασφράγιστη: ζωντανά, δηλαδή 0 χωρίς κανόνες.
     *
     * Χωρίς αυτό, μια υλοποίηση που θα έδειχνε παντού το στιγμιότυπο θα περνούσε
     * το test από πάνω.
     */
    public function testAContractOutsideAnyBatchIsStillCalculatedLive(): void
    {
        $this->payableContract($this->member);

        self::assertSame(0.0, $this->amountFor($this->member));
    }

    /**
     * Οι δύο πηγές συνυπάρχουν στο ίδιο άθροισμα.
     *
     * Το όνομα λέει τι πραγματικά ελέγχεται: η ασφράγιστη μπαίνει ως 0 και δεν
     * παρασύρει το σύνολο — ούτε αγνοείται, ούτε αντικαθιστά το στιγμιότυπο.
     */
    public function testAnUnsettledContractDoesNotDisturbTheSettledTotal(): void
    {
        $this->settle($this->payableContract($this->member), self::SNAPSHOT);
        $this->payableContract($this->member);

        self::assertSame(self::SNAPSHOT, $this->amountFor($this->member));
    }

    // --- fixtures ----------------------------------------------------------

    /**
     * Το ποσό ενός συνεργάτη στην κατάταξη της ομάδας.
     *
     * Η κατάταξη επιστρέφεται μόνο με `scope=team` και μόνο σε όποιον έχει
     * MANAGE_TEAM — γι' αυτό ενεργεί ο προϊστάμενος.
     */
    private function amountFor(int $userId): float
    {
        $request = new WP_REST_Request('GET', '/ecrm/v1/analytics');
        $request->set_query_params(['scope' => 'team']);

        $response = rest_do_request($request);

        self::assertSame(200, $response->get_status());

        $name = (string) get_userdata($userId)->display_name;

        foreach ($response->get_data()['leaderboard'] as $row) {
            if ((string) $row['name'] === $name) {
                return (float) $row['amount'];
            }
        }

        self::fail('Ο συνεργάτης δεν εμφανίστηκε στην κατάταξη.');
    }

    private function payableContract(int $ownerId): int
    {
        $contractId = $this->contracts->create(
            ['status' => 'active', 'supply_number' => '12345678901', 'energy_type' => 'power'],
            UserScope::forSelf($ownerId)
        );

        self::assertGreaterThan(0, $contractId, 'Το fixture σύμβασης δεν αποθηκεύτηκε.');

        return $contractId;
    }

    /** Σφραγίζει τη σύμβαση με ποσό, όπως θα την άφηνε η δημιουργία παρτίδας. */
    private function settle(int $contractId, float $amount): void
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::PAYOUTS), [
            'partner_user_id' => $this->member,
            'period'          => '2026-08',
            'cnt'             => 1,
            'amount'          => $amount,
            'status'          => 'paid',
        ]);

        $payoutId = (int) $wpdb->insert_id;

        self::assertGreaterThan(0, $payoutId, 'Η παρτίδα δεν αποθηκεύτηκε.');

        $wpdb->update(
            Tables::name(Tables::CONTRACTS),
            ['payout_id' => $payoutId, 'payout_amount' => $amount],
            ['id' => $contractId]
        );
    }
}
