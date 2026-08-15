<?php

/**
 * Το /analytics στέλνει ό,τι διαβάζει η οθόνη «Στατιστικά».
 *
 * Τρίτο εύρημα της σάρωσης κλειδιών API ↔ JS, στις 2026-08-14, και το πιο
 * διδακτικό από τα τρία: η ασυμφωνία ήταν ΔΙΠΛΑ σε δουλεύοντα κώδικα.
 *
 * Η barList() του ecrm-view-analytics.js τρέχει πάνω σε τρεις κατανομές —
 * by_provider, by_energy, by_region — και διαβάζει `it.count` και από τις
 * τρεις. Το by_energy περνούσε από array_map σε `label`/`count` και δούλευε.
 * Τα άλλα δύο πήγαιναν ωμά από το SQL, που κάνει `COUNT(*) c`. Αποτέλεσμα:
 *
 *   Math.round(100 * undefined / 1)  →  NaN  →  style="width:NaN%"
 *   '<div class="…__val">' + it.count  →  η λέξη «undefined» στην οθόνη
 *
 * Καμία εξαίρεση, κανένα κόκκινο στο console, η σουίτα πράσινη. Ακριβώς το
 * «λείπει μόνο εδώ ενώ δίπλα δουλεύει» του HANDOVER §6β — δύο γραμμές πιο
 * κάτω από τη γραμμή που το έκανε σωστά.
 *
 * Η διόρθωση μπήκε στον controller (AnalyticsController::labelled) και όχι
 * στο repository, επειδή το ίδιο σχήμα (`name`, `c`) το διαβάζει ΣΩΣΤΑ το
 * dashboard, από άλλη μέθοδο, ως `p.c`. Αλλαγή στο SQL θα έσπαγε εκείνη την
 * οθόνη για να φτιάξει αυτήν.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\CustomerRepository;
use WP_REST_Request;

final class AnalyticsPayloadTest extends IntegrationTestCase
{
    private const ROUTE = '/ecrm/v1/analytics';

    /** Οι τρεις κατανομές που περνούν από την ίδια barList(). */
    private const DISTRIBUTIONS = ['by_provider', 'by_energy', 'by_region'];

    private ContractRepository $contracts;

    private CustomerRepository $customers;

    private int $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contracts = new ContractRepository();
        $this->customers = new CustomerRepository();

        // Συνεργάτης, όχι Πωλητής: το /analytics είναι το μόνο από τα τρία
        // payload tests που ζητά χωριστό capability — Guards::needs(
        // VIEW_ANALYTICS), που το matrix() δίνει μόνο στον PARTNER. Με τον
        // προεπιλεγμένο SELLER η διαδρομή απαντά 403 και το test αποτυγχάνει
        // στον φύλακα, όχι στο σχήμα.
        $this->partner = $this->makeCrmUser(Roles::PARTNER);

        wp_set_current_user($this->partner);
    }

    /**
     * Ολόκληρος ο πίνακας κλειδιών, με τη σειρά, ακριβώς αυτός.
     *
     * assertSame και όχι assertArrayHasKey ανά ένα: το bug ήταν μετονομασία,
     * και ο έλεγχος ανά κλειδί περνάει ευχαρίστως δίπλα σε ένα ξεχασμένο.
     */
    public function testTheResponseCarriesExactlyTheKeysTheScreenReads(): void
    {
        $data = $this->getAnalytics();

        self::assertSame(
            [
                'ok',
                'scope',
                'can_team',
                'total',
                'won',
                'lost',
                'conv_rate',
                'canc_rate',
                'avg_days',
                'funnel',
                'by_provider',
                'by_energy',
                'by_region',
                'monthly',
                'leaderboard',
            ],
            array_keys($data),
            'Το σχήμα της απάντησης άλλαξε. Πριν το αλλάξεις εδώ, ψάξε ποιος το '
            . 'διαβάζει: grep -n "d\\." public/assets/ecrm-view-analytics.js'
        );
    }

    /**
     * Και οι τρεις κατανομές έχουν ΤΟ ΙΔΙΟ σχήμα, γιατί τις σχεδιάζει η ίδια
     * συνάρτηση.
     *
     * Αυτό είναι το test που θα είχε πιάσει το bug. Η by_energy ήταν σωστή,
     * οπότε οποιοσδήποτε έλεγχος μόνο σε αυτήν θα ήταν πράσινος όσο οι άλλες
     * δύο ήταν σπασμένες. Ο βρόχος πάνω και στις τρεις είναι το νόημα.
     */
    public function testTheThreeDistributionsShareTheOneShapeBarListReads(): void
    {
        $this->aContract();

        $data = $this->getAnalytics();

        foreach (self::DISTRIBUTIONS as $key) {
            self::assertNotSame([], $data[$key], "Το {$key} ήρθε κενό — το fixture δεν μετρήθηκε.");

            foreach ($data[$key] as $i => $row) {
                self::assertSame(
                    ['label', 'count'],
                    array_keys($row),
                    "Το {$key}[{$i}] δεν έχει το σχήμα που διαβάζει η barList()."
                );
                self::assertIsInt($row['count'], "Το {$key}[{$i}]['count'] πρέπει να είναι αριθμός.");
            }
        }
    }

    /**
     * Τα βήματα του funnel, που έχουν δικό τους σχήμα.
     *
     * Δεν περνούν από τη barList(): η οθόνη τα σχεδιάζει μόνη της με badge
     * ανά κατάσταση, οπότε χρειάζεται και το `status` πέρα από label/count.
     */
    public function testAFunnelStepCarriesStatusLabelAndCount(): void
    {
        $this->aContract();

        $funnel = $this->getAnalytics()['funnel'];

        self::assertNotSame([], $funnel, 'Το funnel ήρθε κενό.');
        self::assertSame(['status', 'label', 'count'], array_keys($funnel[0]));
    }

    /**
     * Ο μηνιαίος πίνακας είναι δώδεκα ΑΡΙΘΜΟΙ, όχι γραμμές.
     *
     * Η οθόνη κάνει `v / mmax` απευθείας πάνω στα στοιχεία. Πίνακας από
     * array θα έδινε NaN σε κάθε ύψος μπάρας — το ίδιο σφάλμα με τις
     * κατανομές, από την άλλη μεριά.
     */
    public function testTheMonthlyTrendIsTwelvePlainNumbers(): void
    {
        $monthly = $this->getAnalytics()['monthly'];

        self::assertCount(12, $monthly);
        self::assertSame(array_keys($monthly), range(0, 11), 'Η οθόνη το διατρέχει με δείκτη 0-11.');

        foreach ($monthly as $month => $value) {
            self::assertIsInt($value, "Ο μήνας {$month} δεν είναι αριθμός.");
        }
    }

    // --- Fixtures ----------------------------------------------------------

    /** @return array<string, mixed> */
    private function getAnalytics(): array
    {
        $response = rest_do_request(new WP_REST_Request('GET', self::ROUTE));

        self::assertSame(200, $response->get_status(), 'Το GET /analytics δεν πέρασε τους φύλακες.');

        /** @var array<string, mixed> $data */
        $data = $response->get_data();

        return $data;
    }

    private function aContract(): int
    {
        $customerId = $this->customers->create($this->customerData());

        $contractId = $this->contracts->create(
            ['status' => 'active', 'customer_id' => $customerId],
            UserScope::forSelf($this->partner)
        );

        self::assertGreaterThan(0, $contractId, 'Το fixture της σύμβασης δεν μπήκε.');

        return $contractId;
    }
}
