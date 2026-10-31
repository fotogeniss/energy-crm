<?php

/**
 * (345) Στόχοι μήνα και η μηνιαία αναφορά του «Κατάστημα».
 *
 * Φυλάει: ποιος βάζει στόχους και σε ποιους (μόνο στην ομάδα του), σε ποιον
 * μήνα (τρέχοντα ή επόμενο), ότι ο στόχος φτάνει στον πίνακα πωλητών και
 * στην Αρχική του πωλητή, και ότι η αναφορά ενός μήνα μετρά μόνο εκείνον.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Domain\Analytics\Month;
use EnergyCRM\Persistence\StoreRepository;
use EnergyCRM\Persistence\Tables;
use EnergyCRM\Persistence\TeamRepository;
use WP_REST_Request;
use WP_REST_Response;

final class StoreTargetsTest extends IntegrationTestCase
{
    private int $manager;

    private int $member;

    private int $stranger;

    private string $month;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager  = $this->makeCrmUser(Roles::PARTNER);
        $this->member   = $this->makeCrmUser(Roles::SELLER);
        $this->stranger = $this->makeCrmUser(Roles::SELLER);
        update_user_meta($this->member, TeamRepository::PARENT_META, $this->manager);
        $this->month = (new StoreRepository())->calendar()['month'];
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    public function testAManagerSetsTargetsOnlyForTheTeam(): void
    {
        wp_set_current_user($this->manager);

        $saved = $this->save($this->month, [$this->member => 40, $this->stranger => 99]);

        self::assertSame(200, $saved->get_status());
        self::assertSame(1, $saved->get_data()['saved'], 'Ο ξένος πωλητής αγνοείται.');
        self::assertSame(40, $this->targetOf($this->member));
        self::assertNull($this->targetOf($this->stranger));
    }

    public function testEmptyRemovesTheTarget(): void
    {
        wp_set_current_user($this->manager);
        $this->save($this->month, [$this->member => 40]);
        $this->save($this->month, [$this->member => null]);

        self::assertNull($this->targetOf($this->member));
    }

    public function testOnlyThisOrNextMonth(): void
    {
        wp_set_current_user($this->manager);

        self::assertSame(200, $this->save(Month::next($this->month), [$this->member => 30])->get_status());
        self::assertSame(422, $this->save(Month::previous($this->month), [$this->member => 30])->get_status());
        self::assertSame(422, $this->save(Month::next(Month::next($this->month)), [$this->member => 30])->get_status());
        self::assertSame(422, $this->save($this->month, [$this->member => -1])->get_status());
    }

    public function testASellerCannotSetTargets(): void
    {
        wp_set_current_user($this->member);

        self::assertSame(403, $this->save($this->month, [$this->member => 400])->get_status());
    }

    /** Ο στόχος φαίνεται στον πίνακα πωλητών και στο σύνολο του καταστήματος. */
    public function testTheTargetReachesTheStoreTab(): void
    {
        $this->contract($this->member, 'presale');
        wp_set_current_user($this->manager);
        $this->save($this->month, [$this->member => 40]);

        $data = rest_do_request(new WP_REST_Request('GET', '/ecrm/v1/store'))->get_data();
        $row  = array_values(array_filter($data['members'], fn (array $m): bool => $m['id'] === $this->member))[0];

        self::assertSame(40, $row['target']);
        self::assertSame(40, $data['tiles']['target']);
        self::assertSame($this->month, $data['month']);
        self::assertSame($this->month, $data['months'][0]['month']);
    }

    /** Ο πωλητής βλέπει τον στόχο του στην Αρχική. */
    public function testTheSellerSeesTheirOwnTarget(): void
    {
        $this->contract($this->member, 'presale');
        $this->contract($this->member, 'draft');
        wp_set_current_user($this->manager);
        $this->save($this->month, [$this->member => 40]);

        wp_set_current_user($this->member);
        $data = rest_do_request(new WP_REST_Request('GET', '/ecrm/v1/dashboard'))->get_data();

        self::assertSame(['target' => 40, 'done' => 1], $data['target']);
    }

    public function testTheTargetsDialogListsTheTeamWithLastMonth(): void
    {
        $old = $this->contract($this->member, 'presale');
        $this->moveCreated($old, Month::firstDay(Month::previous($this->month)) . ' 12:00:00');

        wp_set_current_user($this->manager);
        $data = rest_do_request(new WP_REST_Request('GET', '/ecrm/v1/store/targets'))->get_data();
        $ids  = array_column($data['rows'], 'id');

        self::assertContains($this->member, $ids);
        self::assertNotContains($this->stranger, $ids);
        $row = array_values(array_filter($data['rows'], fn (array $r): bool => $r['id'] === $this->member))[0];
        self::assertSame(1, $row['previous']);
    }

    /**
     * Η αναφορά του προηγούμενου μήνα μετρά μόνο εκείνον: αιτήσεις, ενεργές
     * και απορρίψεις μέσα στον μήνα, με τον στόχο του μήνα εκείνου.
     */
    public function testTheMonthReportCountsOnlyThatMonth(): void
    {
        $prev  = Month::previous($this->month);
        $start = Month::firstDay($prev);

        $inside  = $this->contract($this->member, 'active');
        $this->moveCreated($inside, $start . ' 09:00:00');
        $this->event($inside, 'active', $start . ' 15:00:00', 'finalisation');

        $lost = $this->contract($this->member, 'cancelled');
        $this->moveCreated($lost, $start . ' 10:00:00');
        $this->event($lost, 'cancelled', $start . ' 16:00:00', 'presale', 'changed_mind');

        $this->contract($this->member, 'presale'); // αυτόν τον μήνα: δεν μετρά

        global $wpdb;
        $wpdb->insert(Tables::name(Tables::TARGETS), [
            'user_id' => $this->member, 'month' => $prev, 'applications' => 4, 'set_by' => $this->manager,
        ]);

        wp_set_current_user($this->manager);
        $response = $this->month($prev);
        $report   = $response->get_data()['report'];
        $mine     = array_filter($report['members'], fn (array $m): bool => $m['id'] === $this->member);
        $row      = array_values($mine)[0];

        self::assertSame(200, $response->get_status());
        self::assertSame(2, $report['tiles']['applications']);
        self::assertSame(1, $report['tiles']['activated']);
        self::assertSame(1, $report['tiles']['lost']);
        $expected = ['applications' => 2, 'target' => 4, 'of_target' => 50, 'activated' => 1, 'lost' => 1];
        self::assertSame($expected, [
            'applications' => $row['applications'],
            'target'       => $row['target'],
            'of_target'    => $row['of_target'],
            'activated'    => $row['activated'],
            'lost'         => $row['lost'],
        ]);
    }

    public function testTheMonthReportRefusesBadMonths(): void
    {
        wp_set_current_user($this->manager);

        self::assertSame(422, $this->month('2026-13')->get_status());
        self::assertSame(422, $this->month(Month::next($this->month))->get_status());
    }

    /** @param array<int, int|null> $targets */
    private function save(string $month, array $targets): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/ecrm/v1/store/targets');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode(['month' => $month, 'targets' => $targets]));

        return rest_do_request($request);
    }

    private function month(string $month): WP_REST_Response
    {
        $request = new WP_REST_Request('GET', '/ecrm/v1/store/month');
        $request->set_param('month', $month);

        return rest_do_request($request);
    }

    private function targetOf(int $userId): ?int
    {
        global $wpdb;

        $value = $wpdb->get_var($wpdb->prepare(
            'SELECT applications FROM %i WHERE user_id = %d AND month = %s',
            Tables::name(Tables::TARGETS),
            $userId,
            $this->month
        ));

        return $value === null ? null : (int) $value;
    }

    private function contract(int $owner, string $status): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::CONTRACTS), [
            'partner_user_id' => $owner,
            'status'          => $status,
            'supply_number'   => '12345678901',
            'energy_type'     => 'power',
            'code'            => 'ECRM-TGT-' . wp_generate_password(8, false),
        ]);

        return (int) $wpdb->insert_id;
    }

    private function moveCreated(int $id, string $when): void
    {
        global $wpdb;

        $wpdb->update(Tables::name(Tables::CONTRACTS), ['created_at' => $when], ['id' => $id]);
    }

    private function event(int $contractId, string $to, string $when, string $from, ?string $why = null): void
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::EVENTS), [
            'contract_id' => $contractId,
            'user_id'     => 0,
            'type'        => 'status_change',
            'from_status' => $from,
            'to_status'   => $to,
            'reason'      => $why,
            'created_at'  => $when,
        ]);
    }
}
