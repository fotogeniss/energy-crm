<?php

/**
 * Ο AlfrAId διαβάζει αιτήσεις (305): μόνο όσες βλέπει ο χρήστης, και χωρίς
 * κανένα στοιχείο πελάτη στο κείμενο που φεύγει στο API.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\UserScope;
use EnergyCRM\Infrastructure\AssistantContractContext;
use EnergyCRM\Persistence\ContractQueries;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\CustomerRepository;
use EnergyCRM\Services;

final class AssistantContractContextTest extends IntegrationTestCase
{
    private ContractRepository $contracts;

    private CustomerRepository $customers;

    private AssistantContractContext $context;

    private int $alice;

    private int $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contracts = new ContractRepository();
        $this->customers = new CustomerRepository();
        $this->context   = new AssistantContractContext(new ContractQueries(), Services::events());

        $this->alice = $this->makePartner();
        $this->bob   = $this->makePartner();
    }

    /** Ο κανόνας του ιδιοκτήτη, πάνω σε πραγματική βάση. */
    public function testTheTextCarriesNoCustomerData(): void
    {
        $customerId = $this->customers->create([
            'first_name' => 'Ευτυχία',
            'last_name'  => 'Ζαχαρόπουλος',
            'afm'        => '094259216',
            'mobile'     => '6977000111',
        ]);

        $this->contracts->create(
            [
                'status'        => 'presale',
                'code'          => 'TEST-9001',
                'customer_id'   => $customerId,
                'supply_number' => '12345678901',
                'energy_type'   => 'power',
            ],
            UserScope::forSelf($this->alice)
        );

        $text = $this->context->forMessage(UserScope::forSelf($this->alice), 'τι γίνεται με την test-9001;');

        self::assertStringContainsString('TEST-9001', $text);
        self::assertStringContainsString('«Presale»', $text);

        foreach (['Ευτυχία', 'Ζαχαρόπουλος', '094259216', '6977000111', '12345678901'] as $secret) {
            self::assertStringNotContainsString($secret, $text);
        }
    }

    /** Αίτηση άλλου συνεργάτη δεν διαβάζεται, ούτε με τον κωδικό της. */
    public function testAnotherPartnersContractIsNotRead(): void
    {
        $this->contracts->create(
            ['status' => 'presale', 'code' => 'TEST-9002', 'energy_type' => 'power'],
            UserScope::forSelf($this->bob)
        );

        $text = $this->context->forMessage(UserScope::forSelf($this->alice), 'TEST-9002');

        self::assertStringContainsString('TEST-9002: δεν υπάρχει στις αιτήσεις που βλέπει ο χρήστης', $text);
        self::assertStringNotContainsString('«Presale»', $text);
    }
}
