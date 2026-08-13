<?php

/**
 * What POST /contracts refuses, and what it must not refuse.
 *
 * Both guards in ContractSaveController::save() were unexercised until now: the
 * ΑΦΜ check had no test, and the email had no check. The email one was found on
 * a printed application carrying `fotogeniss#gmail.com` — a string with no @,
 * accepted and stored.
 *
 * Storing it is worse than it sounds, because both places it travels to are
 * silent. It prints onto the provider's form as though it were an address, and
 * SignLinkController answers `emailed: false` when is_email() rejects it — so
 * the agent sees a saved contract and the customer never receives the signature
 * link. Nobody is told.
 *
 * The half of this that matters just as much is the last test: an empty address
 * still saves. Email is not a required field, and a guard that turned it into
 * one would block every contract taken over the phone.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use WP_REST_Request;

final class ContractSaveValidationTest extends IntegrationTestCase
{
    /** Passes the check-digit test, so it never masks an email failure. */
    private const VALID_AFM = '090003373';

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
            'first_name'  => 'Κωνσταντίνος',
            'last_name'   => 'Νίκας',
        ]);

        return rest_do_request($request);
    }

    public function testAnAddressWithoutAnAtSignIsRefusedWithAMessage(): void
    {
        $response = $this->save(['afm' => self::VALID_AFM, 'email' => 'fotogeniss#gmail.com']);
        $body     = $response->get_data();

        self::assertSame(422, $response->get_status());
        self::assertFalse($body['ok']);
        self::assertSame('email', $body['field'], 'Η οθόνη χρειάζεται το πεδίο για να δείξει πού είναι το λάθος.');
        self::assertNotSame('', (string) $body['error']);
    }

    public function testNothingIsWrittenWhenTheAddressIsRefused(): void
    {
        global $wpdb;

        $before = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'ecrm_contracts');

        $this->save(['afm' => self::VALID_AFM, 'email' => 'χωρίς-παπάκι']);

        self::assertSame(
            $before,
            (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'ecrm_contracts'),
            'Η σύμβαση γράφτηκε παρότι η απάντηση ήταν 422.'
        );
    }

    /** The guard next door, which had no test of its own. */
    public function testAnAfmThatFailsItsCheckDigitIsRefused(): void
    {
        $response = $this->save(['afm' => '123456789']);

        self::assertSame(422, $response->get_status());
        self::assertSame('afm', $response->get_data()['field']);
    }

    /**
     * The other half, and the reason this is a guard and not a required field.
     *
     * Plenty of contracts are taken over the phone from someone who has no
     * email. Refusing those would be a worse defect than the one being fixed.
     */
    public function testAContractWithNoEmailAtAllStillSaves(): void
    {
        $response = $this->save(['afm' => self::VALID_AFM]);

        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));
        self::assertTrue($response->get_data()['ok']);
    }

    public function testAValidAddressSaves(): void
    {
        $response = $this->save(['afm' => self::VALID_AFM, 'email' => 'fotogeniss@gmail.com']);

        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));
        self::assertTrue($response->get_data()['ok']);
    }
}
