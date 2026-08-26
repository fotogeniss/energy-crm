<?php

/**
 * Race condition: διπλή μετατροπή του ίδιου lead σε πελάτη/σύμβαση.
 *
 * Εύρημα #4 του ελέγχου ασφαλείας/UI-UX/ροής-λογικής (26/08/2026). Το παλιό
 * `LeadsController::convert()` διάβαζε το lead, έβλεπε `contract_id` κενό,
 * έφτιαχνε πελάτη+σύμβαση, και μόνο στο τέλος ενημέρωνε το lead -- χωρίς
 * κανένα σημείο ατομικής απόφασης, παρά το docblock που υποσχόταν
 * idempotency. Δύο σχεδόν ταυτόχρονες μετατροπές έφτιαχναν δύο ξεχωριστούς
 * πελάτες/συμβάσεις.
 *
 * Η `LeadRepository::finishConversion()` είναι τώρα το ΜΟΝΟ σημείο
 * απόφασης -- ατομικό `UPDATE ... WHERE contract_id IS NULL`, ΑΦΟΥ πρώτα
 * φτιαχτεί η πραγματική σύμβαση (το `leads.contract_id` έχει FK προς
 * `contracts.id`, οπότε δεν γίνεται να «κλειδώσουμε» με ένα sentinel πριν
 * υπάρχει πραγματική σύμβαση -- δες το docblock της μεθόδου).
 *
 * Δύο επίπεδα δοκιμών εδώ:
 *  - το ΙΔΙΟ ΤΟ RACE, στο επίπεδο του repository -- ίδια τεχνική με το ήδη
 *    υπάρχον `PayoutPaidAtTest`/`PayoutDeletePendingRaceTest`: καλούμε το
 *    guarded update δύο φορές χειροκίνητα και βλέπουμε ότι μόνο η πρώτη
 *    κερδίζει·
 *  - η ΣΥΝΕΠΕΙΑ στο επίπεδο του controller, μέσω πραγματικού REST αιτήματος
 *    (`rest_do_request`, ίδιο μοτίβο με το `ContractRestAccessTest`) -- ότι
 *    ένα αίτημα που χάνει τη διεκδίκηση δεν αφήνει πίσω του ορφανή πρόχειρη
 *    σύμβαση.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\LeadRepository;
use WP_REST_Request;
use WP_REST_Response;

final class LeadConversionClaimRaceTest extends IntegrationTestCase
{
    private LeadRepository $leads;

    private ContractRepository $contracts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->leads     = new LeadRepository();
        $this->contracts = new ContractRepository();
    }

    protected function tearDown(): void
    {
        // The current user is global; left set, it would decide the next test.
        wp_set_current_user(0);

        parent::tearDown();
    }

    public function testFinishConversionSucceedsForAFreshLead(): void
    {
        $partner    = $this->makePartner();
        $scope      = UserScope::forSelf($partner);
        $leadId     = $this->leadOf($partner);
        $contractId = $this->draftContractFor($partner);

        self::assertTrue($this->leads->finishConversion($leadId, $scope, $contractId));

        $row = $this->storedRow('leads', $leadId);
        self::assertSame($contractId, (int) $row['contract_id']);
        self::assertSame('won', $row['stage']);
    }

    /**
     * ΤΟ ΙΔΙΟ ΤΟ RACE: μια δεύτερη ολοκλήρωση πριν ξαναδιαβαστεί το lead
     * πρέπει να χάνει -- ακριβώς το σενάριο που έσπαγε πριν (δύο ξεχωριστοί
     * πελάτες/συμβάσεις για το ίδιο lead).
     */
    public function testASecondFinishConversionForADifferentContractLoses(): void
    {
        $partner  = $this->makePartner();
        $scope    = UserScope::forSelf($partner);
        $leadId   = $this->leadOf($partner);
        $winner   = $this->draftContractFor($partner);
        $loser    = $this->draftContractFor($partner);

        self::assertTrue(
            $this->leads->finishConversion($leadId, $scope, $winner),
            'Η πρώτη ολοκλήρωση πρέπει να κερδίζει.'
        );
        self::assertFalse(
            $this->leads->finishConversion($leadId, $scope, $loser),
            'Η δεύτερη ολοκλήρωση πρέπει να χάνει.'
        );

        // Η σύμβαση του πρώτου -- όχι του δεύτερου -- πρέπει να έμεινε.
        $row = $this->storedRow('leads', $leadId);
        self::assertSame($winner, (int) $row['contract_id']);
    }

    public function testFinishConversionOnAnAbsentLeadFails(): void
    {
        $partner    = $this->makePartner();
        $contractId = $this->draftContractFor($partner);

        self::assertFalse($this->leads->finishConversion(999999, UserScope::forSelf($partner), $contractId));
    }

    /**
     * Η ΣΥΝΕΠΕΙΑ στο επίπεδο του REST controller: ένα αίτημα που χάνει τη
     * διεκδίκηση (γιατί κάποιο άλλο πρόλαβε να ολοκληρώσει) δεν πρέπει να
     * αφήνει πίσω του ορφανή πρόχειρη σύμβαση -- αυτή διαγράφεται, δεν
     * απλώς αγνοείται.
     */
    public function testALosingConvertRequestDeletesItsDraftAndReturnsTheWinner(): void
    {
        $partner = $this->makeCrmUser(Roles::SELLER);
        $leadId  = $this->leadOf($partner);
        $scope   = UserScope::forSelf($partner);

        // Προσομοιώνει ένα άλλο αίτημα που πρόλαβε να ολοκληρώσει τη
        // μετατροπή πριν καν ξεκινήσει το δικό μας.
        $winner = $this->draftContractFor($partner);
        self::assertTrue($this->leads->finishConversion($leadId, $scope, $winner));

        wp_set_current_user($partner);

        $response = $this->post('/ecrm/v1/leads/' . $leadId . '/convert');
        $body     = $response->get_data();

        self::assertSame(200, $response->get_status());
        self::assertTrue($body['ok']);
        self::assertTrue($body['existing']);
        self::assertSame($winner, $body['contract_id'], 'Πρέπει να επιστρέψει τη σύμβαση του νικητή, όχι δεύτερη.');

        // Μόνο η σύμβαση του νικητή πρέπει να έχει μείνει -- ο πρόχειρος που
        // έφτιαξε το χαμένο αίτημα διαγράφηκε, δεν έμεινε ορφανός.
        self::assertSame(
            1,
            $this->contractCountFor($partner),
            'Η χαμένη προσπάθεια πρέπει να διέγραψε τον πρόχειρο που μόλις έφτιαξε.'
        );
    }

    public function testConvertingAFreshLeadCreatesExactlyOneContract(): void
    {
        $partner = $this->makeCrmUser(Roles::SELLER);
        $leadId  = $this->leadOf($partner);

        wp_set_current_user($partner);

        $response = $this->post('/ecrm/v1/leads/' . $leadId . '/convert');
        $body     = $response->get_data();

        self::assertSame(200, $response->get_status());
        self::assertTrue($body['ok']);
        self::assertGreaterThan(0, $body['contract_id']);

        $row = $this->storedRow('leads', $leadId);
        self::assertSame((int) $body['contract_id'], (int) $row['contract_id']);
        self::assertSame('won', $row['stage']);

        self::assertSame(1, $this->contractCountFor($partner));
    }

    // --- fixtures / helpers ---------------------------------------------

    private function leadOf(int $partner): int
    {
        $leadId = $this->leads->create(
            ['name' => 'Υποψήφιος Δοκιμής', 'phone' => '2310123456'],
            UserScope::forSelf($partner)
        );

        self::assertGreaterThan(0, $leadId, 'Το fixture lead δεν αποθηκεύτηκε.');

        return $leadId;
    }

    /**
     * Μια πρόχειρη σύμβαση, ίδιο σχήμα με αυτή που φτιάχνει το
     * `LeadsController::convert()` -- χρειάζεται πραγματικό, υπάρχον id
     * γιατί το `leads.contract_id` έχει FK προς `contracts.id`.
     */
    private function draftContractFor(int $partner): int
    {
        $contractId = $this->contracts->create(
            ['status' => 'draft', 'energy_type' => 'power', 'category' => 'home', 'customer_type' => 'individual'],
            UserScope::forSelf($partner)
        );

        self::assertGreaterThan(0, $contractId, 'Το fixture σύμβασης δεν αποθηκεύτηκε.');

        return $contractId;
    }

    private function contractCountFor(int $partner): int
    {
        global $wpdb;

        $table = \EnergyCRM\Persistence\Tables::name(\EnergyCRM\Persistence\Tables::CONTRACTS);

        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM %i WHERE partner_user_id = %d", $table, $partner)
        );
    }

    private function post(string $path): WP_REST_Response
    {
        return rest_do_request(new WP_REST_Request('POST', $path));
    }
}
