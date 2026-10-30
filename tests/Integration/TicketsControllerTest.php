<?php

/**
 * (339) Αναφορές προβλημάτων -- docs/UI-TICKETS.html.
 *
 * Φυλάει: ποιος βλέπει ποιες (πίνακας «Ποιος τι κάνει»), ότι μόνο ο
 * διαχειριστής αλλάζει κατάσταση, ότι στο κλείσιμο σβήνεται η εικόνα (GDPR),
 * το «Δεν λύθηκε;» των 14 ημερών, τις ειδοποιήσεις στο καμπανάκι, και ότι οι
 * κλειστές σβήνονται μετά από 12 μήνες.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use ECRM_Files;
use EnergyCRM\Access\Roles;
use EnergyCRM\Infrastructure\TicketNotices;
use EnergyCRM\Persistence\Tables;
use EnergyCRM\Persistence\TeamRepository;
use EnergyCRM\Services;
use WP_REST_Request;
use WP_REST_Response;

final class TicketsControllerTest extends IntegrationTestCase
{
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makePartner();
        get_user_by('id', $this->admin)->set_role('administrator');
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    public function testASellerReportsAndTheAdminIsNotifiedWithPersonalNumbersHidden(): void
    {
        $seller = $this->makeCrmUser(Roles::SELLER);
        $id     = $this->report($seller, 'Το PDF βγήκε χωρίς υπογραφή. Πελάτης ΑΦΜ 123456789, κιν. 6912345678.', [
            'view'          => 'contract-detail',
            'contract_id'   => 412,
            'contract_code' => 'EC-2026-0412',
            'password'      => 'δεν κρατιέται',
        ]);

        $row = Services::tickets()->find($id);
        self::assertNotNull($row);
        self::assertSame('new', $row['status']);
        self::assertStringNotContainsString('123456789', (string) $row['body']);
        self::assertStringNotContainsString('6912345678', (string) $row['body']);

        $context = json_decode((string) $row['context'], true);
        self::assertSame(412, $context['contract_id']);
        self::assertSame('Πωλητής', $context['role']);
        self::assertArrayNotHasKey('password', $context);

        self::assertContains(TicketNotices::type($id), $this->bellTypes($this->admin));
        self::assertNotContains(TicketNotices::type($id), $this->bellTypes($seller), 'όχι στον ίδιο');

        wp_set_current_user($this->admin);
        $links = array_column($this->call('GET', '/notifications')->get_data()['notifs'], 'link');
        self::assertContains('#tickets/' . $id, $links);
    }

    public function testATooShortReportIsRefused(): void
    {
        wp_set_current_user($this->makeCrmUser(Roles::SELLER));

        self::assertSame(400, $this->call('POST', '/tickets', ['body' => 'ok'])->get_status());
    }

    public function testEachRoleSeesWhatTheTableSays(): void
    {
        $manager = $this->makeCrmUser(Roles::PARTNER);
        $member  = $this->makeCrmUser(Roles::SELLER);
        $other   = $this->makeCrmUser(Roles::SELLER);
        $office  = $this->makeCrmUser(Roles::BACKOFFICE);
        update_user_meta($member, TeamRepository::PARENT_META, $manager);

        $ofMember = $this->report($member, 'Δεν φορτώνει η λίστα πελατών');
        $ofOther  = $this->report($other, 'Η αναζήτηση ΑΦΜ δεν φέρνει διεύθυνση');
        $ofOffice = $this->report($office, 'Λείπει το Gas Sure 2.0 από τη λίστα');

        self::assertSame([$ofMember], $this->visibleIds($manager), 'υπεύθυνος: της ομάδας του');
        self::assertSame([$ofOther], $this->visibleIds($other), 'πωλητής: μόνο τις δικές του');
        self::assertSame([$ofOffice], $this->visibleIds($office), 'back office: μόνο τις δικές του');

        $all = $this->visibleIds($this->admin);
        foreach ([$ofMember, $ofOther, $ofOffice] as $id) {
            self::assertContains($id, $all, 'διαχειριστής: όλες');
        }

        // Ξένη αναφορά: 404, ούτε καν «υπάρχει αλλά δεν σου επιτρέπεται».
        wp_set_current_user($other);
        self::assertSame(404, $this->call('GET', '/tickets/' . $ofMember)->get_status());
        $reply = $this->call('POST', '/tickets/' . $ofMember . '/comments', ['body' => 'x']);
        self::assertSame(404, $reply->get_status());
    }

    public function testOnlyTheAdminChangesTheStatusAndTheReporterIsTold(): void
    {
        $seller = $this->makeCrmUser(Roles::SELLER);
        $id     = $this->report($seller, 'Κολλάει η υπογραφή στο κινητό');

        wp_set_current_user($seller);
        $refused = $this->call('POST', '/tickets/' . $id . '/status', ['status' => 'resolved']);
        self::assertSame(403, $refused->get_status());

        wp_set_current_user($this->admin);
        $moved = $this->call('POST', '/tickets/' . $id . '/status', ['status' => 'in_progress']);
        self::assertSame(200, $moved->get_status());
        self::assertSame('in_progress', Services::tickets()->find($id)['status']);
        self::assertContains(TicketNotices::type($id), $this->bellTypes($seller));

        $thread = $this->call('GET', '/tickets/' . $id)->get_data()['comments'];
        self::assertSame('status', $thread[0]['kind']);
        self::assertSame('Σε εξέλιξη', $thread[0]['body']);
    }

    public function testClosingDeletesTheImageAndOnlyTheAdminCouldSeeIt(): void
    {
        $seller = $this->makeCrmUser(Roles::SELLER);
        $id     = $this->report($seller, 'Δείτε την εικόνα, το κουμπί δεν φαίνεται');

        $path = trailingslashit(ECRM_Files::dir()) . 'ticket-test-' . wp_generate_password(8, false) . '.png';
        file_put_contents($path, "\x89PNG\r\n\x1A\n" . str_repeat('x', 100));
        Services::tickets()->setAttachment($id, $path, 'image/png');

        wp_set_current_user($seller);
        self::assertFalse($this->call('GET', '/tickets/' . $id)->get_data()['ticket']['has_attachment']);
        self::assertSame(403, $this->call('GET', '/tickets/' . $id . '/attachment')->get_status());

        wp_set_current_user($this->admin);
        self::assertTrue($this->call('GET', '/tickets/' . $id)->get_data()['ticket']['has_attachment']);

        $this->call('POST', '/tickets/' . $id . '/status', ['status' => 'resolved']);

        $row = Services::tickets()->find($id);
        self::assertSame('resolved', $row['status']);
        self::assertNotNull($row['closed_at']);
        self::assertNull($row['attachment_path']);
        self::assertFileDoesNotExist($path);
    }

    public function testTheReporterReopensAResolvedReportOnlyWithinFourteenDays(): void
    {
        global $wpdb;

        $seller = $this->makeCrmUser(Roles::SELLER);
        $other  = $this->makeCrmUser(Roles::SELLER);
        $id     = $this->report($seller, 'Η εκτύπωση βγάζει λάθος ημερομηνία');

        wp_set_current_user($this->admin);
        $this->call('POST', '/tickets/' . $id . '/status', ['status' => 'resolved']);

        wp_set_current_user($other);
        self::assertSame(404, $this->call('POST', '/tickets/' . $id . '/reopen')->get_status());

        wp_set_current_user($seller);
        self::assertTrue($this->call('GET', '/tickets/' . $id)->get_data()['ticket']['can_reopen']);
        self::assertSame(200, $this->call('POST', '/tickets/' . $id . '/reopen')->get_status());
        self::assertSame('new', Services::tickets()->find($id)['status']);
        self::assertNull(Services::tickets()->find($id)['closed_at']);

        // Ξανά «Λύθηκε», αλλά πριν από 20 μέρες: δεν ξανανοίγει.
        wp_set_current_user($this->admin);
        $this->call('POST', '/tickets/' . $id . '/status', ['status' => 'resolved']);
        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET closed_at = NOW() - INTERVAL 20 DAY WHERE id = %d',
            Tables::name(Tables::TICKETS),
            $id
        ));

        wp_set_current_user($seller);
        self::assertFalse($this->call('GET', '/tickets/' . $id)->get_data()['ticket']['can_reopen']);
        self::assertSame(403, $this->call('POST', '/tickets/' . $id . '/reopen')->get_status());
    }

    public function testRepliesGoToTheOtherSide(): void
    {
        $seller = $this->makeCrmUser(Roles::SELLER);
        $id     = $this->report($seller, 'Δεν βλέπω τις προμήθειες του μήνα');
        $this->clearBell($seller);

        wp_set_current_user($this->admin);
        $reply = $this->call('POST', '/tickets/' . $id . '/comments', ['body' => 'Το κοιτάζω']);
        self::assertSame(201, $reply->get_status());
        self::assertContains(TicketNotices::type($id), $this->bellTypes($seller));

        $this->clearBell($this->admin);
        wp_set_current_user($seller);
        $this->call('POST', '/tickets/' . $id . '/comments', ['body' => 'Ευχαριστώ']);
        self::assertContains(TicketNotices::type($id), $this->bellTypes($this->admin));

        $list = $this->call('GET', '/tickets')->get_data()['rows'];
        self::assertSame(2, $list[0]['comments']);
    }

    public function testAnUrgentReportAlsoReachesTheManager(): void
    {
        $manager = $this->makeCrmUser(Roles::PARTNER);
        $member  = $this->makeCrmUser(Roles::SELLER);
        update_user_meta($member, TeamRepository::PARENT_META, $manager);
        Services::network()->rebuildAll();

        $calm   = $this->report($member, 'Μικρό θέμα στη γραμματοσειρά');
        $urgent = $this->report($member, 'Δεν μπορώ να καταχωρήσω καμία αίτηση', [], true);

        $types = $this->bellTypes($manager);
        self::assertNotContains(TicketNotices::type($calm), $types);
        self::assertContains(TicketNotices::type($urgent), $types);
    }

    public function testClosedReportsAreDeletedAfterTwelveMonths(): void
    {
        global $wpdb;

        $seller = $this->makeCrmUser(Roles::SELLER);
        $old    = $this->report($seller, 'Παλιό πρόβλημα που λύθηκε');
        $open   = $this->report($seller, 'Παλιό πρόβλημα που μένει ανοιχτό');

        wp_set_current_user($this->admin);
        $this->call('POST', '/tickets/' . $old . '/status', ['status' => 'resolved']);
        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET closed_at = NOW() - INTERVAL 13 MONTH, created_at = NOW() - INTERVAL 14 MONTH WHERE id = %d',
            Tables::name(Tables::TICKETS),
            $old
        ));
        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET created_at = NOW() - INTERVAL 14 MONTH WHERE id = %d',
            Tables::name(Tables::TICKETS),
            $open
        ));

        Services::ticketRetention()->run();

        self::assertNull(Services::tickets()->find($old));
        self::assertSame([], Services::tickets()->comments($old));
        self::assertNotNull(Services::tickets()->find($open), 'ανοιχτή δεν σβήνεται, όσο παλιά κι αν είναι');
    }

    /**
     * @param array<string, mixed> $context
     */
    private function report(int $userId, string $body, array $context = [], bool $urgent = false): int
    {
        wp_set_current_user($userId);
        $response = $this->call('POST', '/tickets', [
            'body'    => $body,
            'urgent'  => $urgent ? '1' : '0',
            'context' => wp_json_encode($context),
        ]);
        self::assertSame(201, $response->get_status(), (string) wp_json_encode($response->get_data()));

        return (int) $response->get_data()['id'];
    }

    /** @return list<int> */
    private function visibleIds(int $userId): array
    {
        wp_set_current_user($userId);
        $ids = array_column($this->call('GET', '/tickets')->get_data()['rows'], 'id');
        sort($ids);

        return $ids;
    }

    /** @return list<string> */
    private function bellTypes(int $userId): array
    {
        return array_column(Services::notifications()->recentFor($userId, 100), 'type');
    }

    private function clearBell(int $userId): void
    {
        global $wpdb;

        $wpdb->delete(Tables::name(Tables::NOTIFICATIONS), ['user_id' => $userId]);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function call(string $method, string $route, array $params = []): WP_REST_Response
    {
        $request = new WP_REST_Request($method, '/ecrm/v1' . $route);

        if ($params !== []) {
            $method === 'GET' ? $request->set_query_params($params) : $request->set_body_params($params);
        }

        return rest_do_request($request);
    }
}
