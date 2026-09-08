<?php

/**
 * Το ίδιο αίτημα δημιουργίας, δύο φορές, φτιάχνει ένα πράγμα.
 *
 * ## Τι φυλάει, και γιατί δεν αρκούσε ένα UNIQUE στη σύμβαση
 *
 * Ο κρίσιμος έλεγχος εδώ δεν είναι ο αριθμός των συμβάσεων -- είναι ο αριθμός
 * των **πελατών**. Στη ροή του `ContractSaveController` ο πελάτης γράφεται
 * πριν τη σύμβαση, οπότε ένα UNIQUE πάνω στη στήλη της σύμβασης θα έπιανε τη
 * δεύτερη σύμβαση αφού είχε ήδη γραφτεί δεύτερη γραμμή πελάτη -- και ο
 * διπλός πελάτης είναι ακριβώς αυτό που ο τυφλός δείκτης `afm_hash` υπάρχει
 * για να αποτρέψει. Γι' αυτό η δέσμευση γίνεται σε δικό της πίνακα, πριν από
 * κάθε εγγραφή.
 *
 * ## Και το άλλο μισό: τι ΔΕΝ πρέπει να σπάσει
 *
 * Τρία από τα test εδώ δεν αφορούν διπλασιασμό. Ενα αίτημα χωρίς κλειδί
 * περνά ακριβώς όπως πριν (καρτέλα με παλιό JS από την cache), δύο
 * διαφορετικά κλειδιά φτιάχνουν κανονικά δύο συμβάσεις (δύο πελάτες, όχι
 * διπλοεγγραφή), και -- το πιο εύκολο να ξεχαστεί -- ένα κλειδί που
 * απορρίφθηκε ελευθερώνεται: ο συνεργάτης που διορθώνει το ΑΦΜ και ξαναπατά
 * στέλνει το ΙΔΙΟ κλειδί, γιατί είναι ακόμα η ίδια πρόθεση, και δεν
 * επιτρέπεται να κλειδωθεί έξω από τη δική του δημιουργία.
 *
 * Δες `docs/OFFLINE-MODEL.md` §2Γ και `Domain\Contract\RequestKey`.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Persistence\Tables;
use WP_REST_Request;

final class ContractSaveIdempotencyTest extends IntegrationTestCase
{
    /** Περνά τον έλεγχο ψηφίου, ώστε να μη σκεπάζει ποτέ άλλη αποτυχία. */
    private const VALID_AFM = '090003373';

    private const KEY_A = '3f2504e0-4f89-41d3-9a0c-0305e82c3301';
    private const KEY_B = '3f2504e0-4f89-41d3-9a0c-0305e82c3302';

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

    /**
     * @param array<string, mixed> $params
     */
    private function save(array $params): \WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/ecrm/v1/contracts');
        $request->set_body_params($params + [
            'energy_type' => 'power',
            'status'      => 'draft',
            'afm'         => self::VALID_AFM,
            'first_name'  => 'Κωνσταντίνος',
            'last_name'   => 'Νίκας',
        ]);

        return rest_do_request($request);
    }

    private function countIn(string $unprefixedTable): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM %i', Tables::name($unprefixedTable))
        );
    }

    public function testTheSameKeyTwiceReturnsTheOriginalContractInsteadOfMakingASecond(): void
    {
        $before = $this->countIn(Tables::CONTRACTS);

        $first  = $this->save(['client_request_id' => self::KEY_A]);
        $second = $this->save(['client_request_id' => self::KEY_A]);

        self::assertSame(200, $first->get_status(), (string) ($first->get_data()['error'] ?? ''));
        self::assertSame(200, $second->get_status(), (string) ($second->get_data()['error'] ?? ''));

        self::assertSame(
            $first->get_data()['contract_id'],
            $second->get_data()['contract_id'],
            'Η επανάληψη έπρεπε να επιστρέψει την ΑΡΧΙΚΗ σύμβαση.'
        );

        self::assertSame($before + 1, $this->countIn(Tables::CONTRACTS), 'Γράφτηκε δεύτερη σύμβαση.');
    }

    /**
     * Το μισό που δεν θα έπιανε ποτέ ένα UNIQUE πάνω στη σύμβαση.
     */
    public function testTheSameKeyTwiceDoesNotCreateASecondCustomer(): void
    {
        $before = $this->countIn(Tables::CUSTOMERS);

        $this->save(['client_request_id' => self::KEY_A]);
        $this->save(['client_request_id' => self::KEY_A]);

        self::assertSame(
            $before + 1,
            $this->countIn(Tables::CUSTOMERS),
            'Η δεύτερη προσπάθεια έγραψε δεύτερη γραμμή πελάτη -- η δέσμευση έγινε πολύ αργά.'
        );
    }

    /** Το UI πρέπει να ξέρει ότι δεν αποθηκεύτηκε τώρα, αλλιώς λέει ψέματα. */
    public function testTheReplayIsMarkedAsSuch(): void
    {
        $this->save(['client_request_id' => self::KEY_A]);
        $second = $this->save(['client_request_id' => self::KEY_A]);

        self::assertTrue($second->get_data()['replayed'] ?? false);
    }

    public function testTwoDifferentKeysStillCreateTwoContracts(): void
    {
        $before = $this->countIn(Tables::CONTRACTS);

        $this->save(['client_request_id' => self::KEY_A]);
        $this->save(['client_request_id' => self::KEY_B]);

        self::assertSame($before + 2, $this->countIn(Tables::CONTRACTS));
    }

    /**
     * Μια καρτέλα ανοιχτή από χθες τρέχει ακόμα το προηγούμενο JS από την
     * cache και δεν στέλνει κλειδί. Δεν επιτρέπεται να χαλάσει η αποθήκευσή
     * της για ένα πεδίο που δεν ξέρει.
     */
    public function testARequestWithNoKeyBehavesExactlyAsBefore(): void
    {
        $before = $this->countIn(Tables::CONTRACTS);

        $first  = $this->save([]);
        $second = $this->save([]);

        self::assertSame(200, $first->get_status());
        self::assertSame(200, $second->get_status());
        self::assertSame($before + 2, $this->countIn(Tables::CONTRACTS));
    }

    /**
     * Απόρριψη και όχι σιωπηλή αγνόηση: ένας client που στέλνει κλειδί
     * νομίζει ότι προστατεύεται.
     */
    public function testAKeyThatIsNotAUuidIsRefused(): void
    {
        $response = $this->save(['client_request_id' => 'όχι-uuid']);

        self::assertSame(422, $response->get_status());
        self::assertSame('client_request_id', $response->get_data()['field']);
    }

    /** Ενα uuid v1 κουβαλά MAC και χρόνο συσκευής -- δεν είναι ταυτότητα αιτήματος. */
    public function testAUuidThatIsNotVersionFourIsRefused(): void
    {
        $response = $this->save(['client_request_id' => '3f2504e0-4f89-11d3-9a0c-0305e82c3301']);

        self::assertSame(422, $response->get_status());
    }

    /**
     * Το πιο εύκολο να ξεχαστεί: μετά από άρνηση, το κλειδί ξαναδουλεύει.
     */
    public function testAKeyIsFreedWhenTheSaveIsRefusedAndWorksOnTheRetry(): void
    {
        $refused = $this->save(['client_request_id' => self::KEY_A, 'afm' => '123456789']);

        self::assertSame(422, $refused->get_status(), 'Το στημένο ΑΦΜ έπρεπε να απορριφθεί.');

        $retry = $this->save(['client_request_id' => self::KEY_A]);

        self::assertSame(
            200,
            $retry->get_status(),
            'Ο συνεργάτης διόρθωσε το ΑΦΜ και κλειδώθηκε έξω από τη δική του αίτηση: '
            . (string) ($retry->get_data()['error'] ?? '')
        );
    }

    /** Και τίποτα δεν έμεινε κρατημένο μετά την άρνηση. */
    public function testNoClaimSurvivesARefusedSave(): void
    {
        $before = $this->countIn(Tables::REQUEST_KEYS);

        $this->save(['client_request_id' => self::KEY_A, 'afm' => '123456789']);

        self::assertSame($before, $this->countIn(Tables::REQUEST_KEYS));
    }

    /**
     * Το κλειδί ανήκει στον συνεργάτη που το έστειλε.
     *
     * Χωρίς το σύνθετο UNIQUE, ένα uuid θα ήταν καθολικός πόρος: ο πρώτος που
     * το στέλνει δεσμεύει τη θέση όλων των υπολοίπων.
     */
    public function testAnotherUsersKeyIsNotOurs(): void
    {
        $first = $this->save(['client_request_id' => self::KEY_A]);

        wp_set_current_user($this->makeCrmUser(Roles::SELLER));

        $second = $this->save(['client_request_id' => self::KEY_A]);

        self::assertSame(200, $second->get_status(), (string) ($second->get_data()['error'] ?? ''));
        self::assertNotSame(
            $first->get_data()['contract_id'],
            $second->get_data()['contract_id'],
            'Ο δεύτερος συνεργάτης πήρε τη σύμβαση του πρώτου.'
        );
    }

    /**
     * Σε ενημέρωση το κλειδί δεν κάνει τίποτα -- και δεν εμποδίζει τίποτα.
     *
     * Δύο διαδοχικές αποθηκεύσεις πεδίων πάνω στην ίδια σύμβαση με το ίδιο
     * κλειδί πρέπει να περνούν και οι δύο: γράφουν σε γραμμή που υπάρχει ήδη,
     * και μια «δεύτερη» τέτοια εγγραφή δεν δημιουργεί τίποτα.
     */
    public function testTheKeyIsIgnoredOnAnUpdate(): void
    {
        $created    = $this->save(['client_request_id' => self::KEY_A]);
        $contractId = (int) $created->get_data()['contract_id'];

        $edit = $this->save([
            'client_request_id' => self::KEY_A,
            'contract_id'       => $contractId,
            'meter_number'      => 'M-1',
        ]);

        self::assertSame(200, $edit->get_status(), (string) ($edit->get_data()['error'] ?? ''));
        self::assertSame($contractId, (int) $edit->get_data()['contract_id']);
        self::assertArrayNotHasKey('replayed', $edit->get_data());
    }

    /**
     * Μια δέσμευση που τρέχει ακόμα απαντά 409, ποτέ δεύτερη δημιουργία.
     *
     * Στημένη με το χέρι, γιατί δύο πραγματικά ταυτόχρονα requests δεν
     * γίνονται μέσα σε ένα PHPUnit process: η γραμμή με κενό `contract_id`
     * είναι ακριβώς η κατάσταση που αφήνει πίσω του ένα αίτημα εν εξελίξει.
     */
    public function testAClaimStillRunningIsRefusedRatherThanDuplicated(): void
    {
        global $wpdb;

        $before = $this->countIn(Tables::CONTRACTS);

        $wpdb->insert(Tables::name(Tables::REQUEST_KEYS), [
            'partner_user_id' => get_current_user_id(),
            'request_key'     => self::KEY_A,
        ]);

        $response = $this->save(['client_request_id' => self::KEY_A]);

        self::assertSame(409, $response->get_status());
        self::assertSame($before, $this->countIn(Tables::CONTRACTS), 'Δημιουργήθηκε σύμβαση παρά το 409.');
    }

    /**
     * Μια ξεχασμένη δέσμευση ξεκολλά μόνη της.
     *
     * Ενα αίτημα που έσκασε σε fatal error αφήνει γραμμή με κενό
     * `contract_id`. Χωρίς όριο ηλικίας, ο συνεργάτης θα έπαιρνε «εκτελείται
     * ήδη» για πάντα.
     */
    public function testAnAbandonedClaimIsReclaimedAfterTheStalePeriod(): void
    {
        global $wpdb;

        $table = Tables::name(Tables::REQUEST_KEYS);

        $wpdb->insert($table, [
            'partner_user_id' => get_current_user_id(),
            'request_key'     => self::KEY_A,
        ]);

        // Η ώρα τη γράφει η βάση, οπότε και το γέρασμα γίνεται με το ρολόι της.
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET created_at = (NOW() - INTERVAL 1 HOUR) WHERE request_key = %s',
                $table,
                self::KEY_A
            )
        );

        $response = $this->save(['client_request_id' => self::KEY_A]);

        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));
    }
}
