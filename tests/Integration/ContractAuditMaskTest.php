<?php

/**
 * Το ιστορικό ενεργειών δεν επιτρέπεται να γράφει ό,τι η βάση κρύβει.
 *
 * ## Το πρόβλημα, μετρημένο (docs/CHANGELOG.md (167)/(168))
 *
 * Οι στήλες `afm, adt, street, street_no, postal_code, phone` του `customers`
 * είναι κρυπτογραφημένες (`CustomerFields::ENCRYPTED`). Μέχρι σήμερα η
 * `ECRM_Audit::log()` έγραφε την παλιά και τη νέα τιμή τους σε **καθαρό
 * κείμενο** στο `events.message`, όποτε ένα πεδίο άλλαζε — παρακάμπτοντας το
 * `ECRM_ENCRYPT_PII` από την πλαϊνή πόρτα. Ένα dump της βάσης έδινε ό,τι η
 * κρυπτογράφηση υποτίθεται ότι προστάτευε.
 *
 * ## Τι δοκιμάζεται εδώ, και γιατί integration και όχι μόνο unit
 *
 * Η ίδια η μάσκα (`ValueMask`) έχει δικό της unit test, καθαρό από βάση. Αυτό
 * εδώ δοκιμάζει το **σημείο σύνδεσης**: ότι η πραγματική διαδρομή αποθήκευσης
 * σύμβασης (`POST /contracts` → `ContractSaveController::recordHistory()` →
 * `ECRM_Audit::log()`) γράφει πράγματι τη μασκαρισμένη μορφή στη ΒΑΣΗ, όχι
 * απλώς ότι μια συνάρτηση επιστρέφει τη σωστή συμβολοσειρά απομονωμένα. Το
 * μάθημα της (165): μια unit κάλυψη δεν αποδεικνύει τίποτα για το πώς
 * συμπεριφέρεται η πραγματική διαδρομή του αιτήματος.
 *
 * ## Το όριο που ελέγχεται ρητά (mobile)
 *
 * Το `mobile` ΔΕΝ είναι κρυπτογραφημένη στήλη — κάθεται ήδη καθαρό στον
 * `customers` (`VARCHAR(40)`, χωρίς `_hash`). Μια μάσκα εκεί θα έκρυβε από
 * τον πωλητή κάτι που ένας κλέφτης θα έπαιρνε ούτως ή άλλως από τη διπλανή
 * στήλη — θόρυβος χωρίς κέρδος. Ο κανόνας είναι «μασκάρεται ό,τι προστατεύει
 * η βάση, τίποτα άλλο», και αυτό το test το αποδεικνύει και για τις δύο
 * κατευθύνσεις: τα έξι πεδία κρύβονται, το κινητό όχι.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Persistence\Tables;
use WP_REST_Request;
use WP_REST_Response;

final class ContractAuditMaskTest extends IntegrationTestCase
{
    private const ROUTE = '/ecrm/v1/contracts';

    /** Περνά τον έλεγχο ψηφίου — δεν μπλοκάρεται από 422. */
    private const OLD_AFM = '090003373';

    /** Δεύτερο, διαφορετικό ΑΦΜ που περνά τον ίδιο έλεγχο. */
    private const NEW_AFM = '100000003';

    protected function setUp(): void
    {
        parent::setUp();

        wp_set_current_user($this->makeCrmUser(Roles::SELLER));
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    public function testEncryptedFieldsAreMaskedInTheTimelineMessage(): void
    {
        $contractId = $this->makeContract([
            'first_name'  => 'Δοκιμή',
            'last_name'   => 'Μάσκας',
            'afm'         => self::OLD_AFM,
            'adt'         => 'ΑΚ111111',
            'street'      => 'Παλαιά Οδός',
            'street_no'   => '10',
            'postal_code' => '11111',
            'phone'       => '2101234567',
            'mobile'      => '6941234567',
            'status'      => 'draft',
        ]);

        $response = $this->save([
            'contract_id' => $contractId,
            'afm'         => self::NEW_AFM,
            'adt'         => 'ΑΜ222222',
            'street'      => 'Νέα Οδός',
            'street_no'   => '20',
            'postal_code' => '22222',
            'phone'       => '2109876543',
            'mobile'      => '6979876543',
        ]);

        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));

        $events = $this->eventsOf($contractId);

        self::assertCount(
            1,
            $events,
            'Ένα save με πολλαπλές αλλαγές πεδίων γράφει ένα μόνο field_change event.'
        );

        $message = (string) $events[0]['message'];

        // Τα έξι κρυπτογραφημένα πεδία: καμία πλήρης τιμή δεν διαρρέει.
        self::assertStringNotContainsString(self::OLD_AFM, $message, 'Το παλιό ΑΦΜ διέρρευσε ολόκληρο.');
        self::assertStringNotContainsString(self::NEW_AFM, $message, 'Το νέο ΑΦΜ διέρρευσε ολόκληρο.');
        self::assertStringNotContainsString('ΑΚ111111', $message, 'Το παλιό ΑΔΤ διέρρευσε ολόκληρο.');
        self::assertStringNotContainsString('ΑΜ222222', $message, 'Το νέο ΑΔΤ διέρρευσε ολόκληρο.');
        self::assertStringNotContainsString('Παλαιά Οδός', $message, 'Η παλιά οδός διέρρευσε.');
        self::assertStringNotContainsString('Νέα Οδός', $message, 'Η νέα οδός διέρρευσε.');
        self::assertStringNotContainsString('2101234567', $message, 'Το παλιό τηλέφωνο διέρρευσε ολόκληρο.');
        self::assertStringNotContainsString('2109876543', $message, 'Το νέο τηλέφωνο διέρρευσε ολόκληρο.');
        self::assertStringNotContainsString('11111', $message, 'Ο παλιός ΤΚ διέρρευσε ολόκληρος.');
        self::assertStringNotContainsString('22222', $message, 'Ο νέος ΤΚ διέρρευσε ολόκληρος.');

        // Η μασκαρισμένη μορφή είναι εκεί -- δεν λείπει απλώς η τιμή, γράφεται η σωστή μάσκα.
        self::assertStringContainsString('ΑΦΜ: ••••••373 → ••••••003', $message);
        self::assertStringContainsString('ΑΔΤ: •••••111 → •••••222', $message);
        self::assertStringContainsString('Τηλέφωνο: •••••••567 → •••••••543', $message);
        self::assertStringContainsString('ΤΚ: 111•• → 222••', $message);

        // Οδός και αριθμός: καμία μάσκα, μόνο η δήλωση ότι άλλαξε.
        self::assertStringContainsString('Οδός: άλλαξε', $message);
        self::assertStringContainsString('Αριθμός: άλλαξε', $message);
        self::assertStringNotContainsString('Οδός: →', $message, 'Ένα αδιαφανές πεδίο δεν παίρνει βέλος παλιά→νέα.');

        // Το κινητό ΔΕΝ είναι κρυπτογραφημένη στήλη -- μένει ορατό ολόκληρο.
        self::assertStringContainsString('Κινητό: 6941234567 → 6979876543', $message);
    }

    /**
     * Ένα draft, μέσα από την ΙΔΙΑ διαδρομή που δοκιμάζεται.
     *
     * @param array<string, mixed> $params
     */
    private function makeContract(array $params): int
    {
        $response = $this->save($params);
        $data     = $response->get_data();

        self::assertSame(200, $response->get_status(), (string) ($data['error'] ?? ''));
        self::assertGreaterThan(0, $data['contract_id'] ?? 0, 'Το fixture δεν αποθηκεύτηκε.');

        return (int) $data['contract_id'];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function save(array $params): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', self::ROUTE);
        $request->set_body_params($params);

        return rest_do_request($request);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function eventsOf(int $contractId): array
    {
        global $wpdb;

        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT message FROM %i WHERE contract_id = %d AND type = 'field_change' ORDER BY id ASC",
                Tables::name(Tables::EVENTS),
                $contractId
            ),
            ARRAY_A
        );

        return $rows;
    }
}
