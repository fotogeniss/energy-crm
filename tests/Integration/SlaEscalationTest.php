<?php

/**
 * (372) Η κλιμάκωση SLA (`ECRM_Tasks::run_sla_escalation`).
 *
 * Φυλάει το σφάλμα που βρέθηκε στο 344: ο έλεγχος «υπάρχει ήδη;» κοίταζε
 * μόνο ανοιχτές εργασίες, οπότε μετά το «Έγινε» η ίδια εργασία και το ίδιο
 * email ξαναβγαιναν κάθε πρωί. Και η σήμανση `sla_escalation` φαινόταν ως
 * σημείωση στην εργασία. Τεστ για την κλιμάκωση δεν υπήρχαν ως τώρα.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use ECRM_Tasks;
use EnergyCRM\Access\Roles;
use EnergyCRM\Domain\Contract\SlaEscalation;
use EnergyCRM\Persistence\Tables;

final class SlaEscalationTest extends IntegrationTestCase
{
    private int $seller;

    private int $manager;

    /** @var list<string> */
    private array $mails = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = $this->makeCrmUser(Roles::SELLER);
        $this->seller  = $this->makeCrmUser(Roles::SELLER);
        update_user_meta($this->seller, 'ecrm_parent', $this->manager);

        $this->mails = [];
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
        $this->mails[] = (string) ($atts['subject'] ?? '');

        return true;
    }

    public function testAStuckContractIsEscalatedToTheManager(): void
    {
        $id = $this->contract('pending_issue', 20);

        ECRM_Tasks::run_sla_escalation();

        $tasks = $this->tasksFor($id);
        self::assertCount(1, $tasks);
        self::assertSame(SlaEscalation::KIND, $tasks[0]['kind']);
        self::assertNull($tasks[0]['note'], 'Η σήμανση δεν φαίνεται πια ως σημείωση.');
        self::assertSame($this->manager, (int) $tasks[0]['assigned_to']);
        self::assertSame('high', $tasks[0]['priority']);
        self::assertStringStartsWith('SLA: ', $tasks[0]['title']);
        self::assertCount(1, $this->mailsFor($id));
    }

    public function testRunningAgainDoesNotDuplicate(): void
    {
        $id = $this->contract('pending_issue', 20);

        ECRM_Tasks::run_sla_escalation();
        ECRM_Tasks::run_sla_escalation();

        self::assertCount(1, $this->tasksFor($id));
        self::assertCount(1, $this->mailsFor($id));
    }

    /** Το σφάλμα: μετά το «Έγινε» η κλιμάκωση δεν ξαναβγαίνει, ούτε το email. */
    public function testACompletedEscalationIsNotRecreated(): void
    {
        global $wpdb;

        $id = $this->contract('pending_issue', 20);
        ECRM_Tasks::run_sla_escalation();
        $wpdb->update(Tables::name(Tables::TASKS), ['status' => 'done'], ['contract_id' => $id]);

        ECRM_Tasks::run_sla_escalation();

        self::assertCount(1, $this->tasksFor($id));
        self::assertCount(1, $this->mailsFor($id));
    }

    /** Η αίτηση άλλαξε μετά την κλιμάκωση και ξανακόλλησε: νέα κλιμάκωση. */
    public function testAContractThatMovedAndStuckAgainIsEscalatedAgain(): void
    {
        global $wpdb;

        $id = $this->contract('pending_issue', 40);
        ECRM_Tasks::run_sla_escalation();
        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET created_at = NOW() - INTERVAL 30 DAY WHERE contract_id = %d',
            Tables::name(Tables::TASKS),
            $id
        ));
        $this->stuckFor($id, 15);

        ECRM_Tasks::run_sla_escalation();

        self::assertCount(2, $this->tasksFor($id));
    }

    public function testFreshFinalOrDraftContractsAreLeftAlone(): void
    {
        $fresh  = $this->contract('pending_issue', 3);
        $active = $this->contract('active', 20);
        $draft  = $this->contract('draft', 20);

        ECRM_Tasks::run_sla_escalation();

        self::assertCount(0, $this->tasksFor($fresh));
        self::assertCount(0, $this->tasksFor($active));
        self::assertCount(0, $this->tasksFor($draft));
    }

    /** Μια παλιά εργασία με note = 'sla_escalation', μετά τη μετάβαση 0059, μετρά ως ήδη κλιμακωμένη. */
    public function testAMigratedEscalationCountsAsDone(): void
    {
        global $wpdb;

        $id = $this->contract('pending_issue', 20);
        $wpdb->insert(Tables::name(Tables::TASKS), [
            'contract_id' => $id,
            'assigned_to' => $this->manager,
            'created_by'  => 0,
            'kind'        => SlaEscalation::KIND,
            'title'       => 'SLA: παλιά',
            'status'      => 'done',
        ]);

        ECRM_Tasks::run_sla_escalation();

        self::assertCount(1, $this->tasksFor($id));
    }

    private function contract(string $status, int $stuckDays): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::CUSTOMERS), [
            'first_name' => 'Δοκιμή',
            'last_name'  => 'Πελάτης',
        ]);
        $customer = (int) $wpdb->insert_id;

        $wpdb->insert(Tables::name(Tables::CONTRACTS), [
            'partner_user_id' => $this->seller,
            'customer_id'     => $customer,
            'status'          => $status,
            'supply_number'   => '12345678901',
            'energy_type'     => 'power',
            'code'            => 'ECRM-SLA-' . wp_generate_password(8, false),
        ]);
        $id = (int) $wpdb->insert_id;

        $this->stuckFor($id, $stuckDays);

        return $id;
    }

    private function stuckFor(int $contractId, int $days): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET updated_at = NOW() - INTERVAL %d DAY WHERE id = %d',
            Tables::name(Tables::CONTRACTS),
            $days,
            $contractId
        ));
    }

    /** @return list<array<string, mixed>> */
    private function tasksFor(int $contractId): array
    {
        global $wpdb;

        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM %i WHERE contract_id = %d ORDER BY id',
            Tables::name(Tables::TASKS),
            $contractId
        ), ARRAY_A);
    }

    /** @return list<string> */
    private function mailsFor(int $contractId): array
    {
        global $wpdb;

        $code = (string) $wpdb->get_var($wpdb->prepare(
            'SELECT code FROM %i WHERE id = %d',
            Tables::name(Tables::CONTRACTS),
            $contractId
        ));

        return array_values(array_filter(
            $this->mails,
            static fn (string $subject): bool => str_contains($subject, $code)
        ));
    }
}
