<?php

/**
 * (342) Λόγος απόρριψης/ακύρωσης από άκρη σε άκρη: η αλλαγή κατάστασης τον
 * ζητά, το ιστορικό τον κρατά, η εργασία τον γράφει, τα Στατιστικά τον μετράνε
 * και η λίστα συμβάσεων φιλτράρει με αυτόν. docs/UI-REJECT-REASONS.html.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\Tables;
use WP_REST_Request;
use WP_REST_Response;

final class CloseReasonFlowTest extends IntegrationTestCase
{
    private int $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->makeCrmUser(Roles::PARTNER);
        wp_set_current_user($this->owner);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    public function testCancellingASubmittedApplicationNeedsAReason(): void
    {
        $id = $this->contract('presale');

        $refused = $this->status($id, ['status' => 'cancelled']);
        self::assertSame(422, $refused->get_status());
        self::assertArrayHasKey('changed_mind', $refused->get_data()['reasons']);
        self::assertSame('presale', $this->statusOf($id));

        $other = $this->status($id, ['status' => 'cancelled', 'reason' => 'other']);
        self::assertSame(422, $other->get_status(), '«Άλλο» χωρίς σημείωση');

        $ok = $this->status($id, [
            'status'  => 'cancelled',
            'reason'  => 'changed_mind',
            'message' => 'βρήκε φθηνότερο',
        ]);
        self::assertSame(200, $ok->get_status());
        self::assertSame('cancelled', $this->statusOf($id));

        $event = $this->lastEvent($id);
        self::assertSame('changed_mind', $event['reason']);
        self::assertSame('βρήκε φθηνότερο', $event['message']);
    }

    public function testThrowingAwayADraftNeedsNoReason(): void
    {
        $id = $this->contract('draft');

        self::assertSame(200, $this->status($id, ['status' => 'cancelled'])->get_status());
        self::assertNull($this->lastEvent($id)['reason']);
    }

    public function testTheReasonReachesTheTimelineTheTaskTheStatsAndTheList(): void
    {
        $id = $this->contract('finalisation');

        $moved = $this->status($id, ['status' => 'thalis_rejected', 'reason' => 'data_mismatch']);
        self::assertSame(200, $moved->get_status());

        $events = $this->call('GET', '/contracts/' . $id)->get_data()['contract']['events'];
        self::assertSame('Στοιχεία δεν ταιριάζουν (ΑΦΜ, ΑΔΤ, όνομα)', $events[0]['reason_label']);

        global $wpdb;
        $title = (string) $wpdb->get_var($wpdb->prepare(
            'SELECT title FROM %i WHERE contract_id = %d ORDER BY id DESC LIMIT 1',
            Tables::name(Tables::TASKS),
            $id
        ));
        self::assertStringContainsString('στοιχεία δεν ταιριάζουν', $title);

        $lost = $this->call('GET', '/analytics', ['scope' => 'own'])->get_data()['lost_reasons'];
        self::assertContains('data_mismatch', array_column($lost, 'reason'));

        $rows = $this->call('GET', '/contracts', ['reason' => 'data_mismatch'])->get_data()['rows'];
        self::assertSame([$id], array_map('intval', array_column($rows, 'id')));
        self::assertSame([], $this->call('GET', '/contracts', ['reason' => 'debt'])->get_data()['rows']);
    }

    public function testBulkCancellationTakesOneReasonForAll(): void
    {
        $a = $this->contract('presale');
        $b = $this->contract('presale');

        $refused = $this->call('POST', '/contracts/bulk', [
            'ids'    => [$a, $b],
            'action' => 'status',
            'value'  => 'cancelled',
        ]);
        self::assertSame(422, $refused->get_status());

        $ok = $this->call('POST', '/contracts/bulk', [
            'ids'    => [$a, $b],
            'action' => 'status',
            'value'  => 'cancelled',
            'reason' => 'retention',
        ]);
        self::assertSame(200, $ok->get_status());
        self::assertSame('retention', $this->lastEvent($a)['reason']);
        self::assertSame('retention', $this->lastEvent($b)['reason']);
    }

    private function contract(string $status): int
    {
        $id = (new ContractRepository())->create(
            ['status' => $status, 'supply_number' => '12345678901', 'energy_type' => 'power'],
            UserScope::forSelf($this->owner)
        );
        self::assertGreaterThan(0, $id);

        return $id;
    }

    private function statusOf(int $id): string
    {
        return (string) $this->storedRow('contracts', $id)['status'];
    }

    /** @return array<string, mixed> */
    private function lastEvent(int $id): array
    {
        global $wpdb;

        return (array) $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM %i WHERE contract_id = %d AND type = 'status_change' ORDER BY id DESC LIMIT 1",
            Tables::name(Tables::EVENTS),
            $id
        ), ARRAY_A);
    }

    /** @param array<string, mixed> $params */
    private function status(int $id, array $params): WP_REST_Response
    {
        return $this->call('POST', '/contracts/' . $id . '/status', $params);
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
