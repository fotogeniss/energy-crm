<?php

/**
 * (349) Το καμπανάκι: κάθε ειδοποίηση οδηγεί κάπου, και «Εκκαθάριση».
 *
 * Φυλάει: η «Εκκαθάριση» σβήνει μόνο τις ειδοποιήσεις του χρήστη (όχι ξένες,
 * όχι αιτήσεις), κρύβει από το καμπανάκι τις εκκρεμότητες της στιγμής αλλά
 * όχι από την οθόνη «Εκκρεμότητες», και τις ξαναφέρνει όταν αλλάξουν. Και
 * ότι οι ειδοποιήσεις χωρίς σύμβαση έχουν σύνδεσμο ή λένε ότι χάθηκε.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Infrastructure\MonthlyStoreReport;
use EnergyCRM\Persistence\NotificationRepository;
use EnergyCRM\Persistence\Tables;
use WP_REST_Request;
use WP_REST_Response;

final class BellClearTest extends IntegrationTestCase
{
    private NotificationRepository $notifications;

    private int $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->notifications = new NotificationRepository();
        $this->seller        = $this->makeCrmUser(Roles::SELLER);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    public function testClearRemovesOnlyMyNotifications(): void
    {
        $other = $this->makeCrmUser(Roles::SELLER);
        $this->notifications->add($this->seller, 'note', 'Δική μου');
        $this->notifications->add($other, 'note', 'Ξένη');

        wp_set_current_user($this->seller);
        self::assertSame(200, $this->clear()->get_status());

        self::assertSame([], $this->notifications->recentFor($this->seller));
        self::assertCount(1, $this->notifications->recentFor($other));
    }

    /** Από το καμπανάκι φεύγουν· από την οθόνη «Εκκρεμότητες» όχι. */
    public function testClearHidesTodaysFollowupsFromTheBellOnly(): void
    {
        $id = $this->openContract();

        wp_set_current_user($this->seller);
        self::assertContains($id, $this->rowIds($this->bell()));

        $this->clear();

        self::assertNotContains($id, $this->rowIds($this->bell()));
        self::assertContains($id, $this->rowIds($this->index(false)), 'Η οθόνη «Εκκρεμότητες» κρατά τη σύμβαση.');
    }

    /** Κάτι άλλαξε πάνω στη σύμβαση: ξαναβγαίνει στο καμπανάκι. */
    public function testAChangedContractComesBack(): void
    {
        global $wpdb;

        $id = $this->openContract();
        wp_set_current_user($this->seller);
        $this->clear();

        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET updated_at = updated_at + INTERVAL 1 MINUTE WHERE id = %d',
            Tables::name(Tables::CONTRACTS),
            $id
        ));

        self::assertContains($id, $this->rowIds($this->bell()));
    }

    public function testNoticesWithoutAContractLeadSomewhereOrSayItIsGone(): void
    {
        $this->notifications->add($this->seller, 'site_lead', 'Νέα αίτηση από το site', 'Νίκος Δ.');
        $monthly = MonthlyStoreReport::TYPE_PREFIX . '2026-10';
        $this->notifications->add($this->seller, $monthly, 'Οκτώβριος 2026 σε μια ματιά');
        $this->notifications->add($this->seller, 'pending_issue', 'Εκκρεμότητα: EC-1');

        wp_set_current_user($this->seller);
        $byTitle = [];

        foreach ($this->bell()->get_data()['notifs'] as $n) {
            $byTitle[$n['title']] = $n;
        }

        self::assertSame('#leads', $byTitle['Νέα αίτηση από το site']['link']);
        self::assertSame('#store/2026-10', $byTitle['Οκτώβριος 2026 σε μια ματιά']['link']);
        self::assertFalse($byTitle['Νέα αίτηση από το site']['gone']);
        self::assertTrue($byTitle['Εκκρεμότητα: EC-1']['gone'], 'Χωρίς σύμβαση και χωρίς σύνδεσμο.');
    }

    private function openContract(): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::CONTRACTS), [
            'partner_user_id' => $this->seller,
            'status'          => 'presale',
            'supply_number'   => '12345678901',
            'energy_type'     => 'power',
            'code'            => 'ECRM-BELL-' . wp_generate_password(8, false),
        ]);

        return (int) $wpdb->insert_id;
    }

    private function clear(): WP_REST_Response
    {
        return rest_do_request(new WP_REST_Request('POST', '/ecrm/v1/notifications/clear'));
    }

    private function bell(): WP_REST_Response
    {
        return $this->index(true);
    }

    private function index(bool $bell): WP_REST_Response
    {
        $request = new WP_REST_Request('GET', '/ecrm/v1/notifications');
        $request->set_param('bell', $bell);

        return rest_do_request($request);
    }

    /** @return list<int> */
    private function rowIds(WP_REST_Response $response): array
    {
        return array_map('intval', array_column($response->get_data()['rows'] ?? [], 'id'));
    }
}
