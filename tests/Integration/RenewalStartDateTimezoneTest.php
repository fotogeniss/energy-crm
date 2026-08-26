<?php

/**
 * Η ημερομηνία έναρξης μιας ανανέωσης ακολουθεί το ρολόι ΤΟΥ SITE, όχι UTC.
 *
 * MEDIUM εύρημα του ελέγχου ασφαλείας/UI-UX/ροής-λογικής (26/08/2026): η
 * `RenewalsController::nextTermStart()` υπολόγιζε το «σήμερα» με `gmdate(
 * 'Y-m-d')` -- ρολόι UTC -- αντί για `current_time('Y-m-d')` -- ρολόι του
 * site, που ακολουθεί το `timezone_string`/`gmt_offset`. Όσο οι δύο ζώνες
 * συμπίπτουν δεν φαίνεται τίποτα· κοντά στα μεσάνυχτα ώρας Ελλάδας, το
 * `gmdate()` έδινε ακόμα τη ΧΘΕΣΙΝΗ ημερομηνία UTC, οπότε μια ανανέωση
 * ξεκινούσε τη νέα περίοδο μία μέρα νωρίτερα από όσο έπρεπε. Ίδια οικογένεια
 * σφάλματος με το `ContractTransitions`/(96) -- δες `ContractUpdatedAtTest`.
 *
 * ## Γιατί ένα forced timezone offset και όχι το πραγματικό ρολόι
 *
 * Το bug φαίνεται μόνο κοντά σε αλλαγή ημέρας, και ένα test δεν μπορεί να
 * περιμένει τα μεσάνυχτα. Αντί γι' αυτό, το site's `gmt_offset` μετακινείται
 * σκόπιμα αρκετά μακριά ώστε η τοπική ημερομηνία να διαφέρει σίγουρα από την
 * UTC ημερομηνία ΤΗ ΣΤΙΓΜΗ που τρέχει το test, όποια ώρα κι αν είναι αυτή --
 * η κατεύθυνση (μπροστά +14 ή πίσω -12) διαλέγεται από την τρέχουσα ώρα UTC
 * ακριβώς ώστε η διαφορά να είναι εγγυημένη, όχι τυχερή.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use WP_REST_Request;

final class RenewalStartDateTimezoneTest extends IntegrationTestCase
{
    private ContractRepository $contracts;

    private int $partner;

    private string $originalGmtOffset;

    private string $originalTimezoneString;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contracts = new ContractRepository();
        $this->partner   = $this->makeCrmUser();

        wp_set_current_user($this->partner);

        $this->originalGmtOffset      = (string) get_option('gmt_offset', '0');
        $this->originalTimezoneString = (string) get_option('timezone_string', '');
    }

    protected function tearDown(): void
    {
        update_option('gmt_offset', $this->originalGmtOffset);
        update_option('timezone_string', $this->originalTimezoneString);
        wp_set_current_user(0);

        parent::tearDown();
    }

    /**
     * ΤΟ ΙΔΙΟ ΤΟ BUG: με το site's ρολόι μετατοπισμένο ώστε η τοπική
     * ημερομηνία να διαφέρει σίγουρα από την UTC, η ανανέωση μιας ήδη
     * ληγμένης σύμβασης πρέπει να ξεκινά ΣΗΜΕΡΑ ΚΑΤΑ ΤΟ SITE -- όχι κατά UTC.
     * Πριν τη διόρθωση αυτό το test θα απέτυχε πάντα σε αυτή την κατεύθυνση.
     */
    public function testRenewalStartDateFollowsTheSitesClockNotUtc(): void
    {
        $utcNow  = time();
        $utcHour = (int) gmdate('H', $utcNow);

        // Εγγυημένη μετατόπιση ημέρας, όποια ώρα κι αν τρέχει το test.
        $offsetHours = $utcHour >= 10 ? 14 : -12;

        update_option('timezone_string', '');
        update_option('gmt_offset', (string) $offsetHours);

        $expectedLocalDate = gmdate('Y-m-d', $utcNow + ($offsetHours * HOUR_IN_SECONDS));
        $utcDate           = gmdate('Y-m-d', $utcNow);

        self::assertNotSame(
            $utcDate,
            $expectedLocalDate,
            'Το test δεν αποδεικνύει τίποτα αν η μετατόπιση δεν άλλαξε ημέρα -- έλεγξε τη λογική επιλογής offset.'
        );

        $sourceId = $this->expiredContract();

        $response = rest_do_request(new WP_REST_Request('POST', '/ecrm/v1/contracts/' . $sourceId . '/renew'));
        $body     = $response->get_data();

        self::assertSame(200, $response->get_status());
        self::assertTrue($body['ok']);
        self::assertSame(
            $expectedLocalDate,
            $body['start_date'],
            'Η ανανέωση πρέπει να ξεκινά «σήμερα» κατά το ρολόι του site, όχι κατά UTC.'
        );
    }

    /** Μια σύμβαση που έληξε χθες (κατά UTC) -- σίγουρα ήδη εκτός συμβολαίου. */
    private function expiredContract(): int
    {
        $contractId = $this->contracts->create(
            [
                'status'        => 'active',
                'supply_number' => '99988877701',
                'energy_type'   => 'power',
                'end_date'      => gmdate('Y-m-d', strtotime('-1 day')),
                'term_months'   => 12,
            ],
            UserScope::forSelf($this->partner)
        );

        self::assertGreaterThan(0, $contractId, 'Το fixture σύμβασης δεν αποθηκεύτηκε.');

        return $contractId;
    }
}
