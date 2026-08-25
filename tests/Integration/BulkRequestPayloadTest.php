<?php

/**
 * Το /contracts/bulk δέχεται ΑΚΡΙΒΩΣ το body που στέλνει η οθόνη.
 *
 * Καθρέφτης των payload tests της απάντησης, και το κενό που άφησαν. Εκείνα
 * ρωτούν «στέλνει ο server ό,τι διαβάζει η οθόνη;». Αυτό ρωτά το αντίστροφο:
 * **δέχεται ο server ό,τι στέλνει η οθόνη;**
 *
 * Η σάρωση της αντίστροφης κατεύθυνσης, 2026-08-15, βρήκε ότι κάθε κλειδί
 * που στέλνει η JS υπάρχει στο `args` του route ή διαβάζεται ρητά — με ΕΝΑ
 * σημείο ανοιχτό, και είναι εδώ:
 *
 *   runBulk({ action: 'assign', value: +v })   ← ΑΡΙΘΜΟΣ, από το `+v`
 *   'value' => ['type' => 'string', 'default' => '']   ← δηλωμένο ως string
 *
 * Ο controller μετά κάνει `(int) $request['value']`, δηλαδή θέλει αριθμό αλλά
 * τον δηλώνει string. Αν το WP απορρίπτει τον αριθμό στην επικύρωση, το κουμπί
 * «Ανάθεση» της μαζικής επιλογής **δεν δούλεψε ποτέ** και γυρίζει 400. Αν τον
 * δέχεται, δεν υπάρχει bug και το test καρφώνει ότι δεν θα εμφανιστεί.
 *
 * Δεν το δηλώσαμε ούτε ως bug ούτε ως καθαρό: το `ContractWritesTest` δοκιμάζει
 * την `reassign()` του repository, ποτέ τη διαδρομή REST, οπότε αυτό το body
 * δεν είχε περάσει ΠΟΤΕ από τον validator του WP. Η ερώτηση απαντήθηκε εδώ
 * αντί να απαντηθεί από μνήμη.
 *
 * **Η ΑΠΑΝΤΗΣΗ ΗΤΑΝ 400.** Το test γράφτηκε πράσινο-ή-κόκκινο και βγήκε
 * κόκκινο: ο validator του WP απορρίπτει τον αριθμό, η μαζική «Ανάθεση»
 * γύριζε 400 και δεν δούλεψε ποτέ. Διορθώθηκε στο schema του route
 * (`'type' => ['string', 'integer']`), όχι στη JS — το γιατί είναι γραμμένο
 * δίπλα στη γραμμή, στον `ContractsBulkController`.
 *
 * ## Γιατί ανάθεση στον ΙΔΙΟ τον χρήστη
 *
 * Το `UserScope::includes()` περιλαμβάνει πάντα τον ίδιο τον actor, και η
 * `reassign()` επιστρέφει `$result !== false` — άρα ενημέρωση χωρίς αλλαγή
 * τιμής μετράει ως επιτυχία. Έτσι η happy path δοκιμάζεται χωρίς δεύτερο
 * χρήστη: το HANDOVER καταγράφει ότι η σπορά χρηστών με `wp_insert_user()`
 * διπλασίασε κάποτε τη σουίτα (40→78s), επειδή κάθε `update_user_meta`
 * ξαναπερπατά την αλυσίδα γονέων.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Capability;
use EnergyCRM\Access\Roles;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

final class BulkRequestPayloadTest extends IntegrationTestCase
{
    private const ROUTE = '/ecrm/v1/contracts/bulk';

    private ContractRepository $contracts;

    private int $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contracts = new ContractRepository();

        // Συνεργάτης: τρεις από τις τέσσερις μαζικές ενέργειες θέλουν
        // capabilities (ASSIGN_CONTRACT, EXPORT_DATA) που το matrix() δίνει
        // σε αυτόν τον ρόλο. Η «Διαγραφή» ΔΕΝ είναι πια μία απ' αυτές — ο
        // Συνεργάτης έχασε το DELETE_CONTRACT (v3, 25/08, Roles::matrix()) —
        // γι' αυτό το test της διαγραφής δίνει το capability απευθείας στον
        // χρήστη παρακάτω, αντί να το περιμένει από τον ρόλο.
        $this->partner = $this->makeCrmUser(Roles::PARTNER);

        wp_set_current_user($this->partner);
    }

    /**
     * Το body της «Ανάθεσης», με το `value` ΑΡΙΘΜΟ όπως το στέλνει το `+v`.
     *
     * Αυτό είναι όλο το test. Αν το WP απορρίψει τον αριθμό σε πεδίο δηλωμένο
     * ως string, εδώ θα δούμε 400 και θα ξέρουμε ότι το κουμπί δεν δούλεψε
     * ποτέ.
     */
    public function testTheAssignBodyTheScreenSendsIsAcceptedWithANumericValue(): void
    {
        $contractId = $this->aContract();

        $response = $this->bulk([
            'ids'    => [$contractId],
            'action' => 'assign',
            'value'  => $this->partner,
        ]);

        self::assertSame(
            200,
            $response->get_status(),
            'Το body της μαζικής ανάθεσης απορρίφθηκε. Το ecrm-view-contracts.js '
            . 'στέλνει value: +v — αριθμό — ενώ το route το δηλώνει string.'
        );

        /** @var array<string, mixed> $data */
        $data = $response->get_data();

        self::assertTrue($data['ok']);
        self::assertSame(1, $data['updated'], 'Η ανάθεση πέρασε τον validator αλλά δεν άγγιξε γραμμή.');
    }

    /** Το ίδιο body με ΚΕΙΜΕΝΟ στο value — η «Αλλαγή κατάστασης». */
    public function testTheStatusBodyTheScreenSendsIsAcceptedWithAStringValue(): void
    {
        $contractId = $this->aContract();

        $response = $this->bulk([
            'ids'    => [$contractId],
            'action' => 'status',
            'value'  => 'routed',
        ]);

        self::assertSame(200, $response->get_status());
        self::assertTrue($response->get_data()['ok']);
    }

    /**
     * Η «Διαγραφή» και το «Export» δεν στέλνουν `value` καθόλου.
     *
     * Το `runBulk({ action: 'delete' })` δεν το βάζει στο body. Το route το
     * δηλώνει με `'default' => ''`, οπότε η παράλειψη είναι έγκυρη — αλλά
     * μόνο όσο το πεδίο μένει προαιρετικό. Ένα `required => true` εκεί θα
     * έσπαγε δύο κουμπιά σιωπηλά.
     */
    public function testTheDeleteBodyOmitsValueEntirelyAndIsStillAccepted(): void
    {
        $contractId = $this->aContract();

        // Το ερώτημα εδώ είναι το σχήμα του body, όχι ποιος επιτρέπεται να
        // σβήσει — το capability δίνεται απευθείας ώστε το test να μην
        // μπλοκάρει στο permission_callback πριν καν φτάσει στον validator
        // που δοκιμάζεται.
        $user = get_user_by('id', $this->partner);
        self::assertInstanceOf(WP_User::class, $user);
        $user->add_cap(Capability::DELETE_CONTRACT);

        $response = $this->bulk(['ids' => [$contractId], 'action' => 'delete']);

        self::assertSame(200, $response->get_status());
        self::assertTrue($response->get_data()['ok']);
    }

    /** Τα ids φεύγουν ως πίνακας ακεραίων — `.map(c => +c.getAttribute(…))`. */
    public function testTheIdListIsAcceptedAsAnArrayOfIntegers(): void
    {
        $first  = $this->aContract();
        $second = $this->aContract();

        $response = $this->bulk([
            'ids'    => [$first, $second],
            'action' => 'assign',
            'value'  => $this->partner,
        ]);

        self::assertSame(200, $response->get_status());
        self::assertSame(2, $response->get_data()['updated']);
    }

    // --- Fixtures ----------------------------------------------------------

    /** @param array<string, mixed> $body */
    private function bulk(array $body): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', self::ROUTE);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode($body));

        return rest_do_request($request);
    }

    private function aContract(): int
    {
        $contractId = $this->contracts->create(
            ['status' => 'new'],
            UserScope::forSelf($this->partner)
        );

        self::assertGreaterThan(0, $contractId, 'Το fixture της σύμβασης δεν μπήκε.');

        return $contractId;
    }
}
