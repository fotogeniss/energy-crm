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

use EnergyCRM\Access\Roles;
use EnergyCRM\Persistence\NotificationRepository;
use WP_REST_Request;
use WP_REST_Response;

final class NotificationsMarkReadScopeTest extends IntegrationTestCase
{
    private NotificationRepository $notifications;

    protected function setUp(): void
    {
        parent::setUp();

        $this->notifications = new NotificationRepository();
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
