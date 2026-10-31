<?php

/**
 * (345) Το email και η ειδοποίηση της 1ης του μήνα.
 *
 * Φυλάει: φεύγει για τον προηγούμενο μήνα, μία φορά (το option κρατά τον
 * μήνα), στον υπεύθυνο με κίνηση στην ομάδα του· όχι στον πωλητή, και όχι
 * όταν το καθημερινό email είναι κλειστό.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Domain\Analytics\Month;
use EnergyCRM\Infrastructure\MonthlyStoreReport;
use EnergyCRM\Persistence\StoreRepository;
use EnergyCRM\Persistence\Tables;
use EnergyCRM\Persistence\TeamRepository;
use EnergyCRM\Services;

final class MonthlyStoreReportTest extends IntegrationTestCase
{
    /** @var list<array{to: string, subject: string, message: string}> */
    private array $mails = [];

    private int $manager;

    private int $member;

    private string $previous;

    protected function setUp(): void
    {
        parent::setUp();

        delete_option(MonthlyStoreReport::SENT_OPTION);
        $this->mails = [];
        add_filter('pre_wp_mail', [$this, 'capture'], 10, 2);

        $this->manager = $this->makeCrmUser(Roles::PARTNER);
        $this->member  = $this->makeCrmUser(Roles::SELLER);
        update_user_meta($this->member, TeamRepository::PARENT_META, $this->manager);

        $this->previous = Month::previous((new StoreRepository())->calendar()['month']);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_wp_mail', [$this, 'capture'], 10);
        delete_option(MonthlyStoreReport::SENT_OPTION);

        parent::tearDown();
    }

    /** @param array<string, mixed> $atts */
    public function capture(mixed $short, array $atts): bool
    {
        unset($short);
        $this->mails[] = [
            'to'      => (string) $atts['to'],
            'subject' => (string) $atts['subject'],
            'message' => (string) $atts['message'],
        ];

        return true;
    }

    public function testTheManagerGetsLastMonthOnce(): void
    {
        $this->activityLastMonth();

        Services::monthlyStoreReport()->run();
        $mine = $this->mailsTo($this->manager);

        self::assertCount(1, $mine);
        self::assertStringStartsWith(Month::label($this->previous) . ' σε μια ματιά', $mine[0]['subject']);
        self::assertStringContainsString('Αιτήσεις: 1', $mine[0]['message']);
        self::assertCount(0, $this->mailsTo($this->member), 'Ο πωλητής δεν έχει ομάδα.');
        self::assertSame($this->previous, get_option(MonthlyStoreReport::SENT_OPTION));
        self::assertSame(1, $this->noticesFor($this->manager));

        $this->mails = [];
        self::assertSame(0, Services::monthlyStoreReport()->run(), 'Δεύτερη φορά τον ίδιο μήνα: τίποτα.');
        self::assertCount(0, $this->mails);
    }

    public function testNoActivityNoMail(): void
    {
        Services::monthlyStoreReport()->run();

        self::assertCount(0, $this->mailsTo($this->manager));
    }

    public function testTheDigestSwitchAlsoStopsIt(): void
    {
        $this->activityLastMonth();
        update_option(ECRM_PREFIX . 'notify_digest', '0');

        try {
            self::assertSame(0, Services::monthlyStoreReport()->run());
            self::assertFalse(get_option(MonthlyStoreReport::SENT_OPTION));
        } finally {
            delete_option(ECRM_PREFIX . 'notify_digest');
        }
    }

    private function activityLastMonth(): void
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::CONTRACTS), [
            'partner_user_id' => $this->member,
            'status'          => 'presale',
            'supply_number'   => '12345678901',
            'energy_type'     => 'power',
            'code'            => 'ECRM-MON-' . wp_generate_password(8, false),
            'created_at'      => Month::firstDay($this->previous) . ' 11:00:00',
        ]);
    }

    /** @return list<array{to: string, subject: string, message: string}> */
    private function mailsTo(int $userId): array
    {
        $email = get_userdata($userId)->user_email;

        return array_values(array_filter($this->mails, static fn (array $m): bool => $m['to'] === $email));
    }

    private function noticesFor(int $userId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE user_id = %d AND type = %s',
            Tables::name(Tables::NOTIFICATIONS),
            $userId,
            MonthlyStoreReport::TYPE_PREFIX . $this->previous
        ));
    }
}
