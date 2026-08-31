<?php

/**
 * `GET /team/escalations` -- η οθόνη CRM του «Της ομάδας σου» section του
 * email digest (197).
 *
 * AUDIT εύρημα ίδιου πνεύματος με το `NetworkScopeTest` (§2.5,
 * EKKREMI-29-08.html): νέο route, οπότε νέο risk να μπει χωρίς integration
 * test -- ό,τι ακριβώς κατέγραφε το εύρημα για τα άλλα 19. Δανείζεται τα ίδια
 * fixtures με το `DashboardAttentionTest` (`ContractRepository::create()` +
 * `ageContract()` με `DATE_SUB`, γιατί η παλαιότητα κρίνεται από το
 * `updated_at`) και το ίδιο σχήμα actor/upline με το `NetworkScopeTest`.
 *
 * Δύο πράγματα σπάνε σιωπηλά αν κάτι αλλάξει εδώ:
 *   1. Το scope -- ο προϊστάμενος βλέπει ΜΟΝΟ τη δική του κατάντη ομάδα, όχι
 *      όλη την εταιρεία (ίδιο ρίσκο με κάθε upline-φιλτραρισμένη λίστα).
 *   2. Το κατώφλι -- μια σύμβαση πιο πρόσφατη από το `escalation_days()` δεν
 *      πρέπει να εμφανίζεται, αλλιώς η λίστα γεμίζει με πράγματα που δεν
 *      χρειάζονται ακόμα προσοχή.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use ECRM_Notifications;
use EnergyCRM\Access\Roles;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\Tables;
use EnergyCRM\Persistence\TeamRepository;
use WP_REST_Request;
use WP_REST_Response;

final class EscalationsScopeTest extends IntegrationTestCase
{
    private ContractRepository $contracts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contracts = new ContractRepository();
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    public function testManagerSeesAStaleContractOfTheirDownline(): void
    {
        $manager = $this->makeCrmUser(Roles::PARTNER);
        $owner   = $this->makeCrmUser(Roles::PARTNER);
        update_user_meta($owner, TeamRepository::PARENT_META, $manager);

        $contractId = $this->staleContract($owner, 'routed');

        wp_set_current_user($manager);
        $rows = $this->escalations()->get_data()['rows'];
        $ids  = array_column($rows, 'id');

        self::assertContains(
            $contractId,
            $ids,
            'A stale contract of a direct report must be escalated to the manager.'
        );
    }

    public function testAnUnrelatedPartnersStaleContractDoesNotAppear(): void
    {
        $manager  = $this->makeCrmUser(Roles::PARTNER);
        $stranger = $this->makePartner();

        $strangerContract = $this->staleContract($stranger, 'pending');

        wp_set_current_user($manager);
        $ids = array_column($this->escalations()->get_data()['rows'], 'id');

        self::assertNotContains($strangerContract, $ids);
    }

    /** A contract that has not yet passed escalation_days() must not show up. */
    public function testAContractStillWithinTheOwnersOwnWindowDoesNotAppear(): void
    {
        $manager = $this->makeCrmUser(Roles::PARTNER);
        $owner   = $this->makeCrmUser(Roles::PARTNER);
        update_user_meta($owner, TeamRepository::PARENT_META, $manager);

        // Ίδια μέρα, καθόλου αδράνεια -- κάτω από το escalation_days() σε κάθε
        // λογική ρύθμιση κατωφλιού.
        $contractId = $this->contracts->create(
            ['status' => 'routed', 'code' => 'ECRM-ESC-fresh-' . $owner],
            UserScope::forSelf($owner)
        );

        wp_set_current_user($manager);
        $ids = array_column($this->escalations()->get_data()['rows'], 'id');

        self::assertNotContains($contractId, $ids);
    }

    /** Χωρίς MANAGE_TEAM δεν υπάρχει «της ομάδας σου» να δεις. */
    public function testAPlainSellerCannotReachTheRoute(): void
    {
        $seller = $this->makeCrmUser(Roles::SELLER);
        wp_set_current_user($seller);

        self::assertSame(403, $this->escalations()->get_status());
    }

    /** Κάθε γραμμή φέρνει ό,τι χρειάζεται η κάρτα -- κατάσταση, ηλικία, ιδιοκτήτη. */
    public function testARowCarriesTheFieldsTheScreenNeeds(): void
    {
        $manager = $this->makeCrmUser(Roles::PARTNER);
        $owner   = $this->makeCrmUser(Roles::PARTNER);
        update_user_meta($owner, TeamRepository::PARENT_META, $manager);

        $contractId = $this->staleContract($owner, 'routed');

        wp_set_current_user($manager);
        $row = null;
        foreach ($this->escalations()->get_data()['rows'] as $r) {
            if ($r['id'] === $contractId) {
                $row = $r;
            }
        }

        self::assertNotNull($row);
        self::assertSame('routed', $row['status']);
        self::assertArrayHasKey('status_label', $row);
        self::assertArrayHasKey('owner_name', $row);
        self::assertGreaterThan(0, $row['age_days']);
    }

    /**
     * Μια σύμβαση δημιουργημένη κατευθείαν στο `escalation_days()` όριο, με
     * το `updated_at` σπρωγμένο πίσω -- ίδιο τέχνασμα με το
     * `DashboardAttentionTest::ageContract()`, γιατί μόνο ένα UPDATE ενεργοποιεί
     * ξανά το `ON UPDATE CURRENT_TIMESTAMP` της στήλης.
     */
    private function staleContract(int $ownerId, string $status): int
    {
        $contractId = $this->contracts->create(
            ['status' => $status, 'code' => 'ECRM-ESC-' . $ownerId . '-' . $status],
            UserScope::forSelf($ownerId)
        );

        $this->ageContract($contractId, ECRM_Notifications::escalation_days() + 1);

        return $contractId;
    }

    private function ageContract(int $contractId, int $days): void
    {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET updated_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY) WHERE id = %d',
                Tables::name(Tables::CONTRACTS),
                $days,
                $contractId
            )
        );
    }

    private function escalations(): WP_REST_Response
    {
        return rest_do_request(new WP_REST_Request('GET', '/ecrm/v1/team/escalations'));
    }
}
