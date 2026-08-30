<?php

/**
 * `CommissionsController::index()` -- ένα στρογγύλεμα, όχι δύο διαφορετικά.
 *
 * Εσωτερική επισκόπηση 30/08. Πριν τη διόρθωση, `$total`/`$paid`/`$unpaid`
 * άθροιζαν το ΑΣΤΡΟΓΓΥΛΕΥΤΟ ποσό κάθε γραμμής και στρογγύλευαν μόνο στο
 * τέλος, ενώ η ίδια η γραμμή στο `rows[]` έδειχνε το ατομικά στρογγυλεμένο
 * ποσό -- δύο διαφορετικοί υπολογισμοί από την ίδια αρχική τιμή. Το
 * `admin/class-ecrm-payouts.php::create()` το λέει ρητά στο δικό του
 * docblock: το σύνολο είναι το άθροισμα των ήδη στρογγυλεμένων γραμμών, όχι
 * η στρογγυλεμένη ολότητα -- ακριβώς για να μη διαφωνεί ποτέ το εμφανιζόμενο
 * σύνολο με το άθροισμα που θα έκανε ο συνεργάτης με το χέρι πάνω στις
 * γραμμές που βλέπει.
 *
 * Με τους σημερινούς τύπους (payout_amount / commission_rules.amount και τα
 * δύο DECIMAL(10,2)) η παλιά διαδρομή δεν παρήγαγε ποτέ σε αυτό το test ένα
 * observable λάθος λεπτού -- η απόκλιση sum-then-round/round-then-sum
 * χρειάζεται είτε δεκαδικά χωρίς ακριβή δυαδική αναπαράσταση σε μεγάλο
 * πλήθος γραμμών, είτε ζωντανό υπολογισμό με κλάσμα, κανένα από τα δύο δεν
 * υπάρχει σήμερα σε αυτό το endpoint. Αυτό το test δεν αναπαράγει λοιπόν μια
 * αποτυχία στον παλιό κώδικα -- κλειδώνει το αναλλοίωτο που η διόρθωση
 * εγγυάται δομικά (μία τιμή, ένα στρογγύλεμα, τρεις συσσωρευτές από την ΙΔΙΑ
 * τιμή): `total === paid_total + unpaid_total` ακριβώς στο λεπτό.
 *
 * Σκόπιμα δεν περνά από `ECRM_Commissions::amount_for()`: το
 * `active_rules()` εκεί κρατά στατική μνήμη ανά διεργασία (βλ.
 * `PayoutSnapshotTest`) -- ένα προηγούμενο test στην ίδια σουίτα θα μπορούσε
 * να την έχει ήδη γεμίσει άδεια. Το στιγμιότυπο (`payout_amount`) είναι
 * ανεξάρτητο από αυτή τη μνήμη και αρκεί: η λογική που διορθώθηκε
 * (στρογγύλεμα + άθροιση) είναι η ίδια είτε η τιμή είναι στιγμιότυπο είτε
 * ζωντανή.
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

final class CommissionsRoundingTest extends IntegrationTestCase
{
    private const ROUTE = '/ecrm/v1/commissions';

    private ContractRepository $contracts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contracts = new ContractRepository();
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    public function testTotalIsExactlyPaidPlusUnpaidToTheCent(): void
    {
        $partner = $this->makeCrmUser(Roles::SELLER);

        $paidContract   = $this->routedContract($partner, '77788800001');
        $unpaidContract = $this->routedContract($partner, '77788800002');
        $this->snapshotAmount($unpaidContract, 12.34);
        $this->markPaidWithAmount($partner, $paidContract, 12.34);

        wp_set_current_user($partner);

        $response = rest_do_request(new WP_REST_Request('GET', self::ROUTE));
        $data     = $response->get_data();

        self::assertTrue($data['ok']);
        self::assertCount(2, $data['rows']);
        self::assertSame(12.34, $data['rows'][0]['amount']);
        self::assertSame(12.34, $data['rows'][1]['amount']);

        self::assertSame(
            $data['total'],
            round($data['paid_total'] + $data['unpaid_total'], 2),
            'Το σύνολο πρέπει να ισούται ΑΚΡΙΒΩΣ με πληρωμένα + απλήρωτα -- ίδια στρογγυλεμένη τιμή, τρεις συσσωρευτές.'
        );
        self::assertSame(12.34, $data['paid_total']);
        self::assertSame(12.34, $data['unpaid_total']);
        self::assertSame(24.68, $data['total']);
    }

    // --- fixtures ------------------------------------------------------

    private function routedContract(int $partner, string $supply): int
    {
        $id = $this->contracts->create(
            ['status' => 'routed', 'supply_number' => $supply, 'energy_type' => 'power'],
            UserScope::forSelf($partner)
        );

        self::assertGreaterThan(0, $id, 'Το fixture σύμβασης δεν αποθηκεύτηκε.');

        return $id;
    }

    private function snapshotAmount(int $contractId, float $amount): void
    {
        global $wpdb;

        $wpdb->update(Tables::name(Tables::CONTRACTS), ['payout_amount' => $amount], ['id' => $contractId]);
    }

    private function markPaidWithAmount(int $partner, int $contractId, float $amount): void
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::PAYOUTS), [
            'partner_user_id' => $partner,
            'period'          => '2026-08',
            'cnt'             => 1,
            'amount'          => $amount,
            'status'          => 'paid',
        ]);

        $payoutId = (int) $wpdb->insert_id;

        $wpdb->update(
            Tables::name(Tables::CONTRACTS),
            ['payout_id' => $payoutId, 'payout_amount' => $amount],
            ['id' => $contractId]
        );
    }
}
