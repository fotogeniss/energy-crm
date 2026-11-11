<?php

/**
 * (354b) Υπογραφή με ΑΦΜ — docs/UI-SIGN-AFM.html.
 *
 * Ο server επιβάλλει: χωρίς σωστό ΑΦΜ δεν υπάρχει απόδειξη, και χωρίς
 * απόδειξη δεν υπάρχει υπογραφή, όποιο κουμπί κι αν δείχνει η σελίδα.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use ECRM_Tracking;
use EnergyCRM\Access\Roles;
use EnergyCRM\Domain\Contract\SignatureRoles;
use EnergyCRM\Domain\Contract\SignProof;
use EnergyCRM\Persistence\CustomerRepository;
use EnergyCRM\Persistence\Tables;
use WP_REST_Request;
use WP_REST_Response;

final class SignAfmTest extends IntegrationTestCase
{
    private const AFM = '094259216';

    private int $contractId;

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;

        $seller     = $this->makeCrmUser(Roles::SELLER);
        $customerId = (new CustomerRepository())->create($this->customerData(self::AFM));

        $wpdb->insert(Tables::name(Tables::CONTRACTS), [
            'partner_user_id' => $seller,
            'customer_id'     => $customerId,
            'status'          => 'awaiting_signature',
            'code'            => 'ΕΝ-AFM-1',
            'supply_number'   => '12345678901',
            'energy_type'     => 'power',
        ]);

        $this->contractId = (int) $wpdb->insert_id;

        // Δημόσια σελίδα: κανείς συνδεδεμένος.
        wp_set_current_user(0);
    }

    public function testTheRightAfmGivesAProofForThisContract(): void
    {
        $response = $this->typeAfm('094 259 216');

        self::assertSame(200, $response->get_status());

        $proof = (string) $response->get_data()['proof'];
        $key   = (string) (new \ReflectionMethod(ECRM_Tracking::class, 'key_for'))->invoke(null, $this->contractId);

        self::assertNotNull(SignProof::readProof($proof, $this->contractId, SignatureRoles::MOBILE, $key, time()));
        self::assertNull(SignProof::readProof($proof, $this->contractId + 1, SignatureRoles::MOBILE, $key, time()));
    }

    public function testAWrongAfmSaysHowManyTriesAreLeftAndThenLocks(): void
    {
        $first = $this->typeAfm('123456789');

        self::assertSame(403, $first->get_status());
        self::assertSame(SignProof::MAX_FAILS - 1, $first->get_data()['left']);

        for ($i = 2; $i < SignProof::MAX_FAILS; $i++) {
            $this->typeAfm('123456789');
        }

        $last = $this->typeAfm('123456789');

        self::assertSame(429, $last->get_status());
        self::assertTrue($last->get_data()['locked']);

        // Κλειδωμένο σημαίνει κλειδωμένο: ούτε το σωστό περνά τώρα.
        self::assertSame(429, $this->typeAfm(self::AFM)->get_status());
    }

    public function testSigningWithoutAProofIsRefused(): void
    {
        $request = new WP_REST_Request('POST', '/ecrm/v1/track/' . ECRM_Tracking::token($this->contractId) . '/sign');
        $request->set_param('signature', 'data:image/png;base64,' . base64_encode('ό,τι να ναι'));
        $request->set_param('consent', true);

        $response = rest_do_request($request);

        self::assertSame(403, $response->get_status());
        self::assertTrue($response->get_data()['needs_afm']);
        self::assertSame('awaiting_signature', $this->storedRow('contracts', $this->contractId)['status']);
    }

    public function testEveryAttemptIsInTheHistory(): void
    {
        $this->typeAfm('123456789');
        $this->typeAfm(self::AFM);

        $types = array_column((new \EnergyCRM\Persistence\EventRepository())->forContract($this->contractId), 'type');

        self::assertContains('sign_afm_fail', $types);
        self::assertContains('sign_afm_ok', $types);
    }

    private function typeAfm(string $afm): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/ecrm/v1/track/' . ECRM_Tracking::token($this->contractId) . '/afm');
        $request->set_param('afm', $afm);

        return rest_do_request($request);
    }
}
