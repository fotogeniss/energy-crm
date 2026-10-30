<?php

/**
 * (336) Οι διαχειριστές μαθαίνουν ποιες τιμές παλιώνουν, μία φορά την εβδομάδα.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Infrastructure\StalePriceNotice;
use EnergyCRM\Persistence\NotificationRepository;
use EnergyCRM\Persistence\ProviderRepository;
use EnergyCRM\Persistence\Tables;

final class StalePriceNoticeTest extends IntegrationTestCase
{
    public function testAdminsAreToldOnceAndSellersNever(): void
    {
        global $wpdb;

        delete_option('ecrm_stale_price_notice');

        $admin = $this->makePartner();
        $user  = get_user_by('id', $admin);
        self::assertInstanceOf(\WP_User::class, $user);
        $user->set_role('administrator');

        $seller = $this->makeCrmUser();

        $wpdb->insert(Tables::name(Tables::PROVIDERS), ['slug' => 'ecrm-stale', 'name' => 'Παλιές']);
        $wpdb->insert(Tables::name(Tables::PROGRAMS), [
            'provider_id' => (int) $wpdb->insert_id, 'name' => 'AAA Κυμαινόμενο Χ', 'energy_type' => 'power',
            'price_type' => 'variable', 'price_kwh' => 0.15, 'active' => 1, 'price_updated' => '2025-01-01',
        ]);

        $notice = new StalePriceNotice(new ProviderRepository(), new NotificationRepository());
        $notice->run();
        $notice->run();

        $mine = array_values(array_filter(
            (new NotificationRepository())->recentFor($admin),
            static fn (array $n): bool => $n['type'] === StalePriceNotice::TYPE
        ));

        self::assertCount(1, $mine, 'Δεύτερη εκτέλεση την ίδια μέρα δεν ξαναστέλνει.');
        // Η βάση δοκιμών έχει ήδη τα κυμαινόμενα του καταλόγου χωρίς τιμή· το
        // κείμενο δείχνει τα πρώτα πέντε αλφαβητικά, γι' αυτό το «AAA».
        self::assertStringContainsString('AAA Κυμαινόμενο Χ', (string) $mine[0]['body']);
        self::assertSame([], (new NotificationRepository())->recentFor($seller));
    }
}
