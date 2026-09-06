<?php

/**
 * `/notifications`, `/notifications/read` -- το κουδούνι ειδοποιήσεων.
 *
 * AUDIT εύρημα §2.5 (EKKREMI-29-08.html): 19 από τα 54 route paths χωρίς
 * integration test, τέσσερα ρητά αναφερόμενα -- αυτό είναι το τέταρτο και
 * τελευταίο. Το `NotificationRepository::markRead()` λέει ρητά στο δικό
 * του docblock: "the user id is part of the WHERE clause ... so marking
 * someone else's notification read is not expressible". Ο κώδικας ήταν ήδη
 * σωστός -- κανένα test όμως δεν το είχε αποδείξει μέσω της ίδιας της
 * διαδρομής, ούτε το ότι το "mark all" (χωρίς id) μένει επίσης scoped.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use ECRM_Notifications;
use EnergyCRM\Access\Roles;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\NotificationRepository;
use EnergyCRM\Persistence\Tables;
use WP_REST_Request;
use WP_REST_Response;

final class NotificationsMarkReadScopeTest extends IntegrationTestCase
{
    private NotificationRepository $notifications;
    private ContractRepository $contracts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->notifications = new NotificationRepository();
        $this->contracts     = new ContractRepository();
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    /** The exact scenario the repository's own docblock says cannot happen. */
    public function testMarkingAnotherUsersNotificationByIdChangesNothing(): void
    {
        $stranger = $this->makeCrmUser(Roles::SELLER);
        $this->notifications->add($stranger, 'note', 'Ξένη ειδοποίηση');
        $strangerNotifId = $this->onlyNotificationIdOf($stranger);

        $attacker = $this->makeCrmUser(Roles::SELLER);
        wp_set_current_user($attacker);

        $this->markRead($strangerNotifId);

        self::assertSame(
            1,
            $this->notifications->unreadCount($stranger),
            "The stranger's notification must stay unread."
        );
    }

    public function testMarkingOwnNotificationByIdWorks(): void
    {
        $owner = $this->makeCrmUser(Roles::SELLER);
        $this->notifications->add($owner, 'note', 'Δική μου ειδοποίηση');
        $notifId = $this->onlyNotificationIdOf($owner);

        wp_set_current_user($owner);
        $response = $this->markRead($notifId);

        self::assertSame(200, $response->get_status());
        self::assertSame(0, $this->notifications->unreadCount($owner));
    }

    /** "Mark all" (no id) must stay scoped to the caller, same as the single-id path. */
    public function testMarkAllOnlyTouchesTheCallersOwnNotifications(): void
    {
        $owner = $this->makeCrmUser(Roles::SELLER);
        $this->notifications->add($owner, 'note', 'Πρώτη');
        $this->notifications->add($owner, 'note', 'Δεύτερη');

        $other = $this->makeCrmUser(Roles::SELLER);
        $this->notifications->add($other, 'note', 'Άλλου');

        wp_set_current_user($owner);
        $this->markRead(null);

        self::assertSame(0, $this->notifications->unreadCount($owner));
        self::assertSame(1, $this->notifications->unreadCount($other), "Someone else's unread count must not move.");
    }

    /** GET /notifications with no scope=team must report only the caller's own unread count. */
    public function testIndexReportsOnlyTheCallersOwnUnreadCountByDefault(): void
    {
        $owner = $this->makeCrmUser(Roles::SELLER);
        $this->notifications->add($owner, 'note', 'Μη διαβασμένη');

        $other = $this->makeCrmUser(Roles::SELLER);
        $this->notifications->add($other, 'note', 'Άλλου, μη διαβασμένη');

        wp_set_current_user($owner);
        $data = rest_do_request(new WP_REST_Request('GET', '/ecrm/v1/notifications'))->get_data();

        self::assertSame(1, $data['unread']);
    }

    /**
     * (59deb0d) «μόλις πατήσω το καμπανάκι να φεύγουν» -- committed χωρίς
     * κανένα integration test. Αυτό το σετ καλύπτει το κενό: το mass
     * mark-read πρέπει να φωτογραφίζει και να αποσιωπά τις ΔΙΚΕΣ ΤΟΥ
     * θεατή εκκρεμότητες, χωρίς να αγγίζει άλλον χρήστη, χωρίς να το κάνει
     * το single-id mark-read, και μόνο μέχρι να αλλάξει κάτι πραγματικό.
     */
    public function testMarkAllDismissesTheCallersOwnStaleContract(): void
    {
        $owner = $this->makeCrmUser(Roles::SELLER);
        $contractId = $this->staleContract($owner, 'pending');

        wp_set_current_user($owner);

        $before = ECRM_Notifications::followups_for([$owner], $owner);
        self::assertSame(1, $before['stale'], 'Fixture must start stale.');

        $this->markRead(null);

        $after = ECRM_Notifications::followups_for([$owner], $owner);
        self::assertSame(
            0,
            $after['stale'],
            'Dismissing via the bell must stop counting the still-open contract as stale.'
        );

        $row = $this->rowFor($after, $contractId);
        self::assertNotNull($row, 'The contract itself must still be listed -- dismissal never hides it.');
        self::assertFalse($row['stale']);
    }

    public function testDismissingDoesNotAffectAnotherUsersStaleContract(): void
    {
        $viewer   = $this->makeCrmUser(Roles::SELLER);
        $stranger = $this->makeCrmUser(Roles::SELLER);

        $viewerContract   = $this->staleContract($viewer, 'pending');
        $strangerContract = $this->staleContract($stranger, 'pending');

        wp_set_current_user($viewer);
        $this->markRead(null);

        // Ίδιο doc-claim με το followups_for(): μόνο η δική του φωτογραφία
        // εφαρμόζεται -- ένας θεατής που βλέπει την ίδια ομάδα (scope=team)
        // δεν πρέπει ξαφνικά να χάσει την εκκρεμότητα του ξένου.
        $strangerView = ECRM_Notifications::followups_for([$viewer, $stranger], $stranger);
        $row = $this->rowFor($strangerView, $strangerContract);
        self::assertNotNull($row);
        self::assertTrue($row['stale'], "Someone else's stale contract must not be dismissed by my click.");

        $ownView = ECRM_Notifications::followups_for([$viewer], $viewer);
        $ownRow = $this->rowFor($ownView, $viewerContract);
        self::assertNotNull($ownRow);
        self::assertFalse($ownRow['stale']);
    }

    /** Only the mass mark-all (no id) dismisses -- a single stored-notification id must not. */
    public function testMarkingASingleNotificationByIdDoesNotDismissStaleContracts(): void
    {
        $owner = $this->makeCrmUser(Roles::SELLER);
        $this->staleContract($owner, 'pending');
        $this->notifications->add($owner, 'note', 'Κάτι άλλο');
        $notifId = $this->onlyNotificationIdOf($owner);

        wp_set_current_user($owner);
        $this->markRead($notifId);

        $data = ECRM_Notifications::followups_for([$owner], $owner);
        self::assertSame(
            1,
            $data['stale'],
            'Reading one stored notification must not silently dismiss the pending-contract count too.'
        );
    }

    /** The whole point of a snapshot, not a hide: a real change re-arms the alert. */
    public function testAContractThatChangesAfterDismissalCountsAsStaleAgain(): void
    {
        $owner = $this->makeCrmUser(Roles::SELLER);
        $contractId = $this->staleContract($owner, 'pending');

        wp_set_current_user($owner);
        $this->markRead(null);
        self::assertSame(0, ECRM_Notifications::followups_for([$owner], $owner)['stale']);

        $this->contracts->update($contractId, UserScope::forSelf($owner), ['status' => 'routed']);
        // +2 μέρες αντί για +1: αν το UPDATE και η επόμενη γήρανση πέσουν στο
        // ίδιο δευτερόλεπτο (πολύ πιθανό σε γρήγορο test run), το DATETIME
        // της MySQL δεν έχει κλάσματα δευτερολέπτου -- ίδιο πλήθος ημερών θα
        // παρήγαγε ΤΑΥΤΟΣΗΜΗ τιμή με τη φωτογραφία απόρριψης και το test θα
        // απέτυχε για λάθος λόγο (φαινομενικά ξαναδέχεται dismissal ενώ απλά
        // δεν άλλαξε τίποτα μετρήσιμο). Διαφορετικό πλήθος ημερών εγγυάται
        // διαφορετικό updated_at.
        $this->ageContract($contractId, ECRM_Notifications::threshold_days() + 2);

        $after = ECRM_Notifications::followups_for([$owner], $owner);
        $row = $this->rowFor($after, $contractId);
        self::assertNotNull($row);
        self::assertTrue(
            $row['stale'],
            'A real change (new updated_at) must drop out of the dismissed snapshot and count again.'
        );
    }

    /**
     * Ίδιο τέχνασμα με `EscalationsScopeTest::staleContract()`/`ageContract()`:
     * μόνο ένα δεύτερο UPDATE ενεργοποιεί το `ON UPDATE CURRENT_TIMESTAMP`.
     */
    private function staleContract(int $ownerId, string $status): int
    {
        $contractId = $this->contracts->create(
            ['status' => $status, 'code' => 'ECRM-BELL-' . $ownerId . '-' . $status],
            UserScope::forSelf($ownerId)
        );

        $this->ageContract($contractId, ECRM_Notifications::threshold_days() + 1);

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

    /** @param array{rows: array} $data */
    private function rowFor(array $data, int $contractId): ?array
    {
        foreach ($data['rows'] as $row) {
            if ($row['id'] === $contractId) {
                return $row;
            }
        }

        return null;
    }

    private function onlyNotificationIdOf(int $userId): int
    {
        $rows = $this->notifications->recentFor($userId);

        self::assertCount(1, $rows, 'Fixture must have inserted exactly one notification.');

        return (int) $rows[0]['id'];
    }

    private function markRead(?int $id): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/ecrm/v1/notifications/read');

        if ($id !== null) {
            $request->set_param('id', $id);
        }

        return rest_do_request($request);
    }
}
