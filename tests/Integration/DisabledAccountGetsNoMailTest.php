<?php

/**
 * (438) Ο απενεργοποιημένος λογαριασμός δεν παίρνει πια email με πελάτες.
 *
 * Έλεγχος λογικής 21/11: το `DisabledAccounts` αφαιρεί τα δικαιώματα του CRM,
 * αλλά ο ρόλος και το email μένουν. Η ημερήσια σύνοψη διάλεγε παραλήπτες από
 * τον ρόλο, και το email της εκκρεμότητας από τον κάτοχο, οπότε ένας
 * συνεργάτης που έφυγε συνέχιζε να παίρνει ονόματα πελατών και κωδικούς
 * αιτήσεων. Η κλιμάκωση SLA πήγαινε στον άμεσο προϊστάμενο ακόμα κι αν ήταν
 * απενεργοποιημένος: εργασία και email σε κάποιον που δεν μπαίνει πια.
 *
 * @kanonas K6
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use ECRM_Notifications;
use ECRM_Tasks;
use EnergyCRM\Access\Roles;
use EnergyCRM\Persistence\Tables;
use EnergyCRM\Persistence\TeamRepository;

final class DisabledAccountGetsNoMailTest extends IntegrationTestCase
{
    private TeamRepository $team;

    /** @var list<string> */
    private array $recipients = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->team       = new TeamRepository();
        $this->recipients = [];

        add_filter('pre_wp_mail', [$this, 'capture'], 10, 2);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_wp_mail', [$this, 'capture'], 10);

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $atts
     */
    public function capture(mixed $short, array $atts): bool
    {
        foreach ((array) ($atts['to'] ?? []) as $address) {
            $this->recipients[] = (string) $address;
        }

        return true;
    }

    /** Η σύνοψη: ο ενεργός την παίρνει, ο απενεργοποιημένος όχι. */
    public function testTheDailyDigestSkipsADisabledPartner(): void
    {
        $seller = $this->makeCrmUser(Roles::SELLER);
        $this->contract($seller, 'pending_issue', 20);

        ECRM_Notifications::run_digest();
        self::assertContains($this->email($seller), $this->recipients, 'Χωρίς αυτό το test δεν λέει τίποτα.');

        $this->recipients = [];
        $this->team->setDisabled($seller, true);

        ECRM_Notifications::run_digest();
        self::assertNotContains($this->email($seller), $this->recipients);
    }

    /** Το email της εκκρεμότητας δεν πάει σε απενεργοποιημένο κάτοχο. */
    public function testTheStatusEmailSkipsADisabledOwner(): void
    {
        $seller = $this->makeCrmUser(Roles::SELLER);
        $id     = $this->contract($seller, 'pending_issue', 0);

        ECRM_Notifications::notify_status_change($id, 'pending_issue');
        self::assertSame([$this->email($seller)], $this->recipients);

        $this->recipients = [];
        $this->team->setDisabled($seller, true);

        ECRM_Notifications::notify_status_change($id, 'pending_issue');
        self::assertSame([], $this->recipients);
    }

    /** Κλιμάκωση SLA: ο άμεσος είναι απενεργοποιημένος, πάει στον επόμενο ενεργό. */
    public function testAnSlaEscalationSkipsADisabledManager(): void
    {
        $top     = $this->makeCrmUser(Roles::PARTNER);
        $manager = $this->makeCrmUser(Roles::SELLER);
        $seller  = $this->makeCrmUser(Roles::SELLER);
        update_user_meta($manager, 'ecrm_parent', $top);
        update_user_meta($seller, 'ecrm_parent', $manager);

        $this->team->setDisabled($manager, true);

        $id = $this->contract($seller, 'pending_issue', 20);
        ECRM_Tasks::run_sla_escalation();

        $assigned = $this->taskOwnersFor($id);
        self::assertSame([$top], $assigned);
        self::assertContains($this->email($top), $this->recipients);
        self::assertNotContains($this->email($manager), $this->recipients);
    }

    private function contract(int $owner, string $status, int $stuckDays): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::CUSTOMERS), ['first_name' => 'Δοκιμή', 'last_name' => 'Πελάτης']);
        $customer = (int) $wpdb->insert_id;

        $wpdb->insert(Tables::name(Tables::CONTRACTS), [
            'partner_user_id' => $owner,
            'customer_id'     => $customer,
            'status'          => $status,
            'supply_number'   => '12345678901',
            'energy_type'     => 'power',
            'code'            => 'ECRM-DIS-' . wp_generate_password(8, false),
        ]);
        $id = (int) $wpdb->insert_id;

        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET updated_at = NOW() - INTERVAL %d DAY WHERE id = %d',
            Tables::name(Tables::CONTRACTS),
            $stuckDays,
            $id
        ));

        return $id;
    }

    /** @return list<int> */
    private function taskOwnersFor(int $contractId): array
    {
        global $wpdb;

        /** @var list<string> $ids */
        $ids = $wpdb->get_col($wpdb->prepare(
            'SELECT assigned_to FROM %i WHERE contract_id = %d ORDER BY id',
            Tables::name(Tables::TASKS),
            $contractId
        ));

        return array_map('intval', $ids);
    }

    private function email(int $userId): string
    {
        $user = get_userdata($userId);
        self::assertNotFalse($user);

        return (string) $user->user_email;
    }
}
