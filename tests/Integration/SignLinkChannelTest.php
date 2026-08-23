<?php

/**
 * Το κανάλι αποστολής για υπογραφή, και η καταγραφή του (B4, 21/08).
 *
 * Τρία πράγματα ελέγχονται εδώ, και κανένα δεν είναι «στέλνει σωστά»: το να
 * φύγει Viber χρειάζεται πάροχο, και ένας πάροχος σε test suite θα ήταν
 * προσποίηση. Ελέγχεται ό,τι ΜΠΟΡΕΙ να ειπωθεί με βεβαιότητα:
 *
 * 1. **Ότι το κανάλι φτάνει στον server και δεν αγνοείται.** Μέχρι σήμερα η
 *    οθόνη έστελνε σκληρά `email:true` και ο controller δεν είχε άλλη επιλογή.
 *
 * 2. **Ότι κάθε αποστολή αφήνει ίχνος, ΚΑΙ ΟΤΑΝ ΑΠΟΤΥΓΧΑΝΕΙ.** Αυτό ήταν το
 *    πραγματικό κενό: η σύμβαση πήγαινε σε «αναμονή υπογραφής» ενώ το email
 *    είχε αποτύχει σιωπηλά, το `emailed:false` έφτανε στον browser και
 *    πεταγόταν, και αύριο κανείς δεν ήξερε αν ο πελάτης ειδοποιήθηκε ποτέ.
 *
 * 3. **Ότι το `comms` λέει την αλήθεια για το τι μπορεί να δουλέψει.** Ο
 *    διάλογος χτίζεται πάνω σε αυτό· αν πει «μπορείς Viber» χωρίς πάροχο,
 *    υπόσχεται σιωπηλή αποτυχία — ακριβώς αυτό που ήρθε να κλείσει.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Persistence\CustomerRepository;
use EnergyCRM\Persistence\EventRepository;
use EnergyCRM\Persistence\Tables;
use WP_REST_Request;
use WP_REST_Response;

final class SignLinkChannelTest extends IntegrationTestCase
{
    private int $seller;

    private int $contractId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seller     = $this->makeCrmUser(Roles::SELLER);
        $this->contractId = $this->contractWithCustomer($this->customerData());

        wp_set_current_user($this->seller);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    /**
     * @param array<string, string> $customer
     */
    private function contractWithCustomer(array $customer): int
    {
        global $wpdb;

        $customerId = (new CustomerRepository())->create($customer);

        $wpdb->insert(Tables::name(Tables::CONTRACTS), [
            'customer_id'     => $customerId,
            'partner_user_id' => $this->seller,
            'status'          => 'new',
            'code'            => 'ΕΝ-TEST-' . $customerId,
            'supply_number'   => '12345678901',
            'energy_type'     => 'power',
        ]);

        return (int) $wpdb->insert_id;
    }

    /** @param array<string, mixed> $body */
    private function send(array $body, ?int $contractId = null): WP_REST_Response
    {
        $request = new WP_REST_Request(
            'POST',
            '/ecrm/v1/contracts/' . ($contractId ?? $this->contractId) . '/sign-link'
        );

        foreach ($body as $key => $value) {
            $request->set_param($key, $value);
        }

        return rest_do_request($request);
    }

    /** @return list<string> τύποι γεγονότων, νεότερα πρώτα */
    private function eventTypes(?int $contractId = null): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['type'],
            (new EventRepository())->forContract($contractId ?? $this->contractId)
        );
    }

    // ── 1. Το κανάλι φτάνει, και δεν αγνοείται ───────────────────────────

    public function testTheLinkOnlyChannelIsASuccessAndNotAnAbsenceOfAction(): void
    {
        $body = $this->send(['channel' => 'link'])->get_data();

        // Ο συνεργάτης το ζήτησε ρητά: θα τον στείλει ο ίδιος. Ένα `false` εδώ
        // θα κατέγραφε «απέτυχε» για κάτι που πήγε ακριβώς όπως ζητήθηκε.
        self::assertTrue($body['ok']);
        self::assertSame('link', $body['channel']);
        self::assertTrue($body['delivered']);
        self::assertNotEmpty($body['url']);
    }

    public function testAskingForSmsIsNotSilentlyTurnedIntoEmail(): void
    {
        $body = $this->send(['channel' => 'sms'])->get_data();

        self::assertSame('sms', $body['channel']);

        // Χωρίς ρυθμισμένο πάροχο δεν φεύγει τίποτα — και αυτό ΛΕΓΕΤΑΙ.
        self::assertFalse($body['delivered']);
        self::assertNotNull($body['why']);
        self::assertFalse($body['emailed']);
    }

    public function testTheOldEmailBooleanStillMeansEmail(): void
    {
        // Ένας καλών που δεν έμαθε ποτέ για κανάλια δεν πρέπει να αρχίσει
        // ξαφνικά να «αντιγράφει σύνδεσμο» αντί να στέλνει.
        $body = $this->send(['email' => true])->get_data();

        self::assertSame('email', $body['channel']);
    }

    public function testWithNothingAskedTheDefaultIsTheLinkAndNotAnEmail(): void
    {
        $body = $this->send([])->get_data();

        self::assertSame('link', $body['channel']);
    }

    // ── 2. Κάθε αποστολή αφήνει ίχνος, ΚΑΙ ΟΤΑΝ ΑΠΟΤΥΓΧΑΝΕΙ ──────────────

    public function testEveryDispatchLeavesAnEventEvenTheQuietOne(): void
    {
        $this->send(['channel' => 'link']);

        self::assertContains('sign_sent_link', $this->eventTypes());
    }

    public function testAFailedDeliveryIsRecordedAsFailedAndNotOmitted(): void
    {
        $noEmail = $this->contractWithCustomer(
            array_merge($this->customerData('987654321'), ['email' => '', 'phone' => ''])
        );

        $body = $this->send(['channel' => 'email'], $noEmail)->get_data();

        // Αυτό ακριβώς ήταν το κενό: η αποτυχία υπήρχε, αλλά πουθενά.
        self::assertFalse($body['delivered']);
        self::assertSame('no_email', $body['why']);
        self::assertContains('sign_failed_email', $this->eventTypes($noEmail));
    }

    public function testTheContractStillMovesWhenTheChannelFailed(): void
    {
        $noEmail = $this->contractWithCustomer(
            array_merge($this->customerData('111222333'), ['email' => '', 'phone' => ''])
        );

        $this->send(['channel' => 'email'], $noEmail);

        // Δεν είναι σφάλμα: ο σύνδεσμος δουλεύει και ο συνεργάτης μπορεί να τον
        // στείλει με το χέρι. Αυτό που δεν επιτρέπεται είναι να μην το μάθει —
        // και γι' αυτό υπάρχει το γεγονός από πάνω.
        self::assertContains('status_change', $this->eventTypes($noEmail));
        self::assertSame('pending_signature', $this->statusOf($noEmail));
    }

    /**
     * Η μνήμη του διαλόγου διαβάζει ΜΟΝΟ τα `sign_*`.
     *
     * Ένα σκέτο `sms` μπορεί να είναι το αυτόματο μήνυμα της «ενεργοποιήθηκε».
     * Αν η μνήμη το μετρούσε, ο διάλογος θα έλεγε «στάλθηκε Viber» για μήνυμα
     * που δεν αφορούσε καθόλου την υπογραφή.
     */
    public function testTheSignatureEventIsDistinguishableFromAnyOtherMessage(): void
    {
        $this->send(['channel' => 'link']);

        foreach ($this->eventTypes() as $type) {
            if (str_starts_with($type, 'sign_')) {
                self::assertMatchesRegularExpression('/^sign_(sent|failed)_(sms|email|link)$/', $type);
            }
        }

        self::assertContains('sign_sent_link', $this->eventTypes());
    }

    // ── 3. Το comms λέει την αλήθεια ─────────────────────────────────────

    public function testCommsRefusesSmsWhenNoProviderIsConfigured(): void
    {
        $request = new WP_REST_Request('GET', '/ecrm/v1/contracts/' . $this->contractId);
        $body    = rest_do_request($request)->get_data();

        $comms = $body['contract']['comms'];

        self::assertFalse($comms['sms']['ok']);
        self::assertSame('no_provider', $comms['sms']['why']);
    }

    public function testCommsSaysEmailIsPossibleWhenTheCustomerHasOne(): void
    {
        $request = new WP_REST_Request('GET', '/ecrm/v1/contracts/' . $this->contractId);
        $comms   = rest_do_request($request)->get_data()['contract']['comms'];

        self::assertTrue($comms['email']['ok']);
        self::assertArrayNotHasKey('why', $comms['email']);
    }

    public function testCommsSaysWhyEmailIsImpossible(): void
    {
        $noEmail = $this->contractWithCustomer(
            array_merge($this->customerData('444555666'), ['email' => ''])
        );

        $request = new WP_REST_Request('GET', '/ecrm/v1/contracts/' . $noEmail);
        $comms   = rest_do_request($request)->get_data()['contract']['comms'];

        self::assertFalse($comms['email']['ok']);
        self::assertSame('no_email', $comms['email']['why']);
    }

    public function testTheLinkIsAlwaysPossibleBecauseItDependsOnNothing(): void
    {
        $request = new WP_REST_Request('GET', '/ecrm/v1/contracts/' . $this->contractId);
        $comms   = rest_do_request($request)->get_data()['contract']['comms'];

        // Ο σύνδεσμος ΕΙΝΑΙ το tracking URL. Μπαίνει ρητά ώστε ο διάλογος να
        // διαβάζει έναν πίνακα και όχι έναν πίνακα συν μια εξαίρεση.
        self::assertTrue($comms['link']['ok']);
    }

    private function statusOf(int $contractId): string
    {
        global $wpdb;

        return (string) $wpdb->get_var(
            $wpdb->prepare('SELECT status FROM %i WHERE id = %d', Tables::name(Tables::CONTRACTS), $contractId)
        );
    }
}
