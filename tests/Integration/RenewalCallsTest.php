<?php

/**
 * (344) Η καθημερινή «Κλήση ανανέωσης».
 *
 * Φυλάει το σφάλμα που βρέθηκε: η παλιά υπενθύμιση έλεγχε μόνο ανοιχτές
 * εργασίες, οπότε μετά το «Έγινε» ξαναβγαινε κάθε πρωί μέχρι τη λήξη.
 * Τεστ για την υπενθύμιση δεν υπήρχαν ως τώρα.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Domain\Contract\RenewalCall;
use EnergyCRM\Persistence\Tables;
use EnergyCRM\Services;

final class RenewalCallsTest extends IntegrationTestCase
{
    private int $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seller = $this->makeCrmUser(Roles::SELLER);
    }

    public function testAContractEndingWithinTheWindowGetsOneCall(): void
    {
        $id = $this->contract('active', 20);

        self::assertSame(1, Services::renewalCalls()->run(30));

        $tasks = $this->tasksFor($id);
        self::assertCount(1, $tasks);
        self::assertSame(RenewalCall::KIND, $tasks[0]['kind']);
        self::assertSame($this->seller, (int) $tasks[0]['assigned_to']);
        self::assertSame('open', $tasks[0]['status']);
        self::assertStringStartsWith('Κλήση ανανέωσης: Δοκιμή Πελάτης · λήγει ', $tasks[0]['title']);
        self::assertStringContainsString('δες Λήξεις', (string) $tasks[0]['note']);
    }

    public function testRunningAgainDoesNotDuplicate(): void
    {
        $id = $this->contract('active', 20);

        Services::renewalCalls()->run(30);
        Services::renewalCalls()->run(30);

        self::assertCount(1, $this->tasksFor($id));
    }

    /** Το σφάλμα: μετά το «Έγινε» η κλήση δεν ξαναβγαίνει. */
    public function testACompletedCallIsNotRecreated(): void
    {
        global $wpdb;

        $id = $this->contract('active', 20);
        Services::renewalCalls()->run(30);
        $wpdb->update(Tables::name(Tables::TASKS), ['status' => 'done'], ['contract_id' => $id]);

        self::assertSame(0, Services::renewalCalls()->run(30));
        self::assertCount(1, $this->tasksFor($id));
    }

    public function testOutsideTheWindowOrCancelledThereIsNoCall(): void
    {
        $far       = $this->contract('active', 60);
        $cancelled = $this->contract('cancelled', 10);
        $past      = $this->contract('active', -3);

        Services::renewalCalls()->run(30);

        self::assertCount(0, $this->tasksFor($far));
        self::assertCount(0, $this->tasksFor($cancelled));
        self::assertCount(0, $this->tasksFor($past));
    }

    public function testTheLastWeekIsHighPriority(): void
    {
        $id = $this->contract('active', 5);

        Services::renewalCalls()->run(30);

        self::assertSame('high', $this->tasksFor($id)[0]['priority']);
    }

    /** Μια παλιά «auto_renewal» (μετά τη μετάβαση 0054) μετρά ως ήδη υπάρχουσα κλήση. */
    public function testAMigratedReminderCountsAsTheCall(): void
    {
        global $wpdb;

        $id  = $this->contract('active', 20);
        $end = (string) $wpdb->get_var($wpdb->prepare(
            'SELECT end_date FROM %i WHERE id = %d',
            Tables::name(Tables::CONTRACTS),
            $id
        ));
        $wpdb->insert(Tables::name(Tables::TASKS), [
            'contract_id' => $id,
            'assigned_to' => $this->seller,
            'created_by'  => 0,
            'kind'        => RenewalCall::KIND,
            'title'       => 'Λήξη σύμβασης σε 20 ημέρες — επανάκληση ανανέωσης',
            'due_at'      => $end . ' 10:00:00',
        ]);

        self::assertSame(0, Services::renewalCalls()->run(30));
    }

    /** Νέα λήξη (παράταση) = νέα κλήση. */
    public function testANewEndDateGetsANewCall(): void
    {
        global $wpdb;

        $id = $this->contract('active', 20);
        Services::renewalCalls()->run(30);
        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET end_date = CURDATE() + INTERVAL 25 DAY WHERE id = %d',
            Tables::name(Tables::CONTRACTS),
            $id
        ));

        self::assertSame(1, Services::renewalCalls()->run(30));
        self::assertCount(2, $this->tasksFor($id));
    }

    private function contract(string $status, int $endsInDays): int
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
            'code'            => 'ECRM-RNW-' . wp_generate_password(8, false),
        ]);
        $id = (int) $wpdb->insert_id;

        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET end_date = CURDATE() + INTERVAL %d DAY WHERE id = %d',
            Tables::name(Tables::CONTRACTS),
            $endsInDays,
            $id
        ));

        return $id;
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
}
