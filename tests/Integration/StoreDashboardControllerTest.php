<?php

/**
 * (343) GET /store -- η καρτέλα «Κατάστημα» στην Αρχική.
 *
 * Φυλάει: ποιος τη βλέπει (μόνο όποιος έχει ομάδα), ότι ο υπεύθυνος βλέπει
 * μόνο την ομάδα του, και ότι οι αριθμοί μετράνε ό,τι λέει η οθόνη -- χωρίς
 * πρόχειρα, υπογραφές «πάνω από 2 μέρες» από την ώρα που μπήκαν σε αναμονή,
 * απορρίψεις 30 ημερών με τον συχνότερο λόγο.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Domain\Contract\CloseReason;
use EnergyCRM\Persistence\Tables;
use EnergyCRM\Persistence\TeamRepository;
use WP_REST_Request;
use WP_REST_Response;

final class StoreDashboardControllerTest extends IntegrationTestCase
{
    private int $manager;

    private int $member;

    private int $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager  = $this->makeCrmUser(Roles::PARTNER);
        $this->member   = $this->makeCrmUser(Roles::SELLER);
        $this->stranger = $this->makeCrmUser(Roles::SELLER);
        update_user_meta($this->member, TeamRepository::PARENT_META, $this->manager);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    public function testASellerWithoutATeamIsRefused(): void
    {
        wp_set_current_user($this->member);

        self::assertSame(403, $this->store()->get_status());
    }

    public function testAnonymousIsRefused(): void
    {
        wp_set_current_user(0);

        self::assertSame(401, $this->store()->get_status());
    }

    /** Ο υπεύθυνος βλέπει την ομάδα του· ο ξένος πωλητής δεν φαίνεται πουθενά. */
    public function testAManagerSeesOnlyTheirTeam(): void
    {
        $this->contract($this->member, 'presale');
        $this->contract($this->member, 'registration');
        $this->contract($this->stranger, 'presale');

        wp_set_current_user($this->manager);
        $data = $this->store()->get_data();
        $ids  = array_column($data['members'], 'id');

        self::assertContains($this->member, $ids);
        self::assertContains($this->manager, $ids);
        self::assertNotContains($this->stranger, $ids);
        self::assertSame(2, $data['tiles']['today']);
        self::assertSame(2, $this->member($data, $this->member)['today']);
    }

    /** Ένα πρόχειρο δεν στάλθηκε ποτέ· δεν είναι «αίτηση σήμερα». */
    public function testDraftsAreNotCounted(): void
    {
        $this->contract($this->member, 'draft');

        wp_set_current_user($this->manager);

        self::assertSame(0, $this->store()->get_data()['tiles']['today']);
    }

    /** Οι μέρες μετράνε από την ώρα που μπήκε σε αναμονή υπογραφής. */
    public function testLateSignaturesCountFromWhenTheWaitStarted(): void
    {
        $late  = $this->contract($this->member, 'awaiting_signature');
        $fresh = $this->contract($this->member, 'awaiting_signature');
        $this->event($late, 'awaiting_signature', 4);
        $this->event($fresh, 'awaiting_signature', 1);

        wp_set_current_user($this->manager);
        $data = $this->store()->get_data();
        $line = $this->line($data, 'signatures');

        self::assertSame(2, $data['tiles']['awaiting']);
        self::assertSame(1, $data['tiles']['awaiting_late']);
        self::assertSame(1, $line['count']);
        self::assertSame(get_userdata($this->member)->display_name, $line['split'][0]['name']);
    }

    /**
     * Απορρίψεις 30 ημερών ανά πωλητή, με τον συχνότερο λόγο. Παλιότερες δεν
     * μετράνε, ούτε ένα πρόχειρο που πετάχτηκε.
     */
    public function testRejectionsAndTheirTopReason(): void
    {
        foreach ([3, 5] as $days) {
            $id = $this->contract($this->member, 'thalis_rejected');
            $this->event($id, 'thalis_rejected', $days, 'finalisation', 'documents');
        }

        $other = $this->contract($this->member, 'cancelled');
        $this->event($other, 'cancelled', 2, 'presale', 'changed_mind');

        $old = $this->contract($this->member, 'cancelled');
        $this->event($old, 'cancelled', 45, 'presale', 'changed_mind');

        $draft = $this->contract($this->member, 'cancelled');
        $this->event($draft, 'cancelled', 1, 'draft', null);

        wp_set_current_user($this->manager);
        $data = $this->store()->get_data();

        self::assertSame(3, $data['tiles']['lost']);
        self::assertSame(3, $this->member($data, $this->member)['lost']);
        self::assertSame(CloseReason::label('documents'), $data['tiles']['top_reason']);
    }

    /** Από το ιστορικό: ενεργοποιήθηκε φέτος τον μήνα, ό,τι κι αν έγινε μετά. */
    public function testActivatedThisMonthComesFromHistory(): void
    {
        $id = $this->contract($this->member, 'cancelled');
        $this->event($id, 'active', 0, 'finalisation');

        wp_set_current_user($this->manager);

        self::assertSame(1, $this->member($this->store()->get_data(), $this->member)['activated']);
    }

    public function testOverdueCallbacksAreSplitBySeller(): void
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::LEADS), [
            'partner_user_id' => $this->member,
            'name'            => 'Δοκιμή',
            'stage'           => 'new',
            'callback_at'     => '2020-01-01 10:00:00',
        ]);

        wp_set_current_user($this->manager);
        $line = $this->line($this->store()->get_data(), 'leads');

        self::assertSame(1, $line['count']);
        self::assertSame(get_userdata($this->member)->display_name, $line['split'][0]['name']);
    }

    public function testTheOnlineDotFollowsTheLastHalfHour(): void
    {
        $this->contract($this->member, 'presale');

        wp_set_current_user($this->manager);
        $data = $this->store()->get_data();

        self::assertTrue($this->member($data, $this->member)['online']);
        self::assertFalse($this->member($data, $this->manager)['online']);
    }

    private function store(): WP_REST_Response
    {
        return rest_do_request(new WP_REST_Request('GET', '/ecrm/v1/store'));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function member(array $data, int $id): array
    {
        foreach ($data['members'] as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }

        self::fail("Λείπει ο πωλητής {$id}.");
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function line(array $data, string $key): array
    {
        foreach ($data['todo'] as $row) {
            if ($row['key'] === $key) {
                return $row;
            }
        }

        self::fail("Λείπει η γραμμή {$key}.");
    }

    private function contract(int $owner, string $status): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::CONTRACTS), [
            'partner_user_id' => $owner,
            'status'          => $status,
            'supply_number'   => '12345678901',
            'energy_type'     => 'power',
            'code'            => 'ECRM-STR-' . wp_generate_password(8, false),
        ]);

        return (int) $wpdb->insert_id;
    }

    private function event(int $contractId, string $to, int $daysAgo, ?string $from = null, ?string $why = null): void
    {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO %i (contract_id, user_id, type, from_status, to_status, reason, created_at)
                 VALUES (%d, 0, %s, %s, %s, %s, NOW() - INTERVAL %d DAY)',
                Tables::name(Tables::EVENTS),
                $contractId,
                'status_change',
                $from,
                $to,
                $why,
                $daysAgo
            )
        );
    }
}
