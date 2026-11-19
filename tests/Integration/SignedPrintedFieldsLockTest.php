<?php

/**
 * (428) Μετά την υπογραφή, ό,τι τυπώνεται στο έντυπο δεν αλλάζει από μη-admin.
 *
 * Έλεγχος 19/11: η διεύθυνση, το τηλέφωνο, η εγγύηση ΔΕΗ και τα υπόλοιπα
 * τυπωμένα στοιχεία άλλαζαν μετά την υπογραφή, και το έντυπο ξαναφτιαχνόταν με
 * τα νέα στοιχεία ΚΑΙ την υπογραφή του πελάτη. Απόφαση ιδιοκτήτη: «κλειδώνουν
 * όλα τα τυπωμένα πεδία». Τρεις πόρτες: η φόρμα, η καρτέλα πελάτη, και μια
 * άλλη αίτηση του ίδιου πελάτη (ο πελάτης είναι μία γραμμή).
 *
 * @kanonas K3
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Domain\Contract\ContractStatus;
use EnergyCRM\Persistence\Tables;
use EnergyCRM\Providers\Persistence\ProviderGrantRepository;
use WP_REST_Request;
use WP_REST_Response;

final class SignedPrintedFieldsLockTest extends IntegrationTestCase
{
    private const AFM = '090003373';

    private int $provider = 0;

    private int $program = 0;

    private int $contractId = 0;

    private int $customerId = 0;

    protected function setUp(): void
    {
        global $wpdb;

        parent::setUp();

        $seller = $this->makeCrmUser(Roles::SELLER);

        $wpdb->insert(Tables::name(Tables::PROVIDERS), ['slug' => 'ecrm-dei-printed', 'name' => 'ΔΕΗ']);
        $this->provider = (int) $wpdb->insert_id;

        $wpdb->insert(Tables::name(Tables::PROGRAMS), [
            'provider_id' => $this->provider,
            'name'        => 'myHome EnterTwo',
            'code'        => 'dei_myhome_entertwo',
            'energy_type' => 'power',
            'category'    => 'home',
            'price_type'  => 'fixed',
            'active'      => 1,
        ]);
        $this->program = (int) $wpdb->insert_id;

        (new ProviderGrantRepository())->set($seller, [$this->provider]);
        wp_set_current_user($seller);

        $response = $this->save([
            'first_name'    => 'Τυπωμένη',
            'last_name'     => 'Αίτηση',
            'afm'           => self::AFM,
            'street'        => 'Ερμού',
            'provider_id'   => $this->provider,
            'program_id'    => $this->program,
            'energy_type'   => 'power',
            'customer_type' => 'individual',
            'status'        => ContractStatus::Draft->value,
            'extra'         => ['guarantee' => '120'],
        ]);
        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));

        $this->contractId = (int) $response->get_data()['contract_id'];
        $this->customerId = (int) $response->get_data()['customer_id'];

        $wpdb->update(
            Tables::name(Tables::CONTRACTS),
            ['signed_at' => current_time('mysql')],
            ['id' => $this->contractId]
        );
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    /** Η διεύθυνση και η εγγύηση τυπώνονται: μετά την υπογραφή δεν αλλάζουν. */
    public function testPrintedFieldsAreLockedInTheForm(): void
    {
        $street = $this->save(['contract_id' => $this->contractId, 'street' => 'Σταδίου']);
        self::assertSame(422, $street->get_status());
        self::assertSame('street', $street->get_data()['field']);

        $guarantee = $this->save(['contract_id' => $this->contractId, 'extra' => ['guarantee' => '300']]);
        self::assertSame(422, $guarantee->get_status());
        self::assertSame('guarantee', $guarantee->get_data()['field']);

        // Και το σβήσιμο είναι αλλαγή.
        $emptied = $this->save(['contract_id' => $this->contractId, 'extra' => ['guarantee' => '']]);
        self::assertSame(422, $emptied->get_status());
    }

    /** Η φόρμα ξαναστέλνει τα πάντα σε κάθε αποθήκευση: η ίδια τιμή περνά. */
    public function testResendingTheSameValuesStillSaves(): void
    {
        $response = $this->save([
            'contract_id' => $this->contractId,
            'street'      => 'Ερμού',
            'afm'         => self::AFM,
            'extra'       => ['guarantee' => '120'],
        ]);

        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));
    }

    /** Ο αριθμός αίτησης του παρόχου έρχεται μετά την υπογραφή: τον γράφει το γραφείο. */
    public function testTheProviderReferenceIsStillWritten(): void
    {
        $response = $this->save(['contract_id' => $this->contractId, 'provider_ref' => 'ΔΕΗ-12345']);

        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));
    }

    /** Η καρτέλα πελάτη και μια άλλη αίτηση του ίδιου πελάτη είναι πόρτες προς το ίδιο χαρτί. */
    public function testTheCustomerCardAndAnotherContractAreLockedToo(): void
    {
        $card = $this->call('PATCH', '/customers/' . $this->customerId, ['street' => 'Σταδίου']);
        self::assertSame(422, $card->get_status());
        self::assertSame('street', $card->get_data()['field']);

        $other = $this->save([
            'customer_id' => $this->customerId,
            'street'      => 'Σταδίου',
            'energy_type' => 'gas',
            'status'      => ContractStatus::Draft->value,
        ]);
        self::assertSame(422, $other->get_status());
        self::assertSame('street', $other->get_data()['field']);
    }

    /** Ο διαχειριστής αλλάζει κανονικά (και μένει στο ιστορικό). */
    public function testTheAdministratorCanStillChangeThem(): void
    {
        wp_set_current_user($this->makeCrmUser('administrator'));

        $response = $this->save(['contract_id' => $this->contractId, 'street' => 'Σταδίου']);

        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));
    }

    /** @param array<string, mixed> $params */
    private function save(array $params): WP_REST_Response
    {
        return $this->call('POST', '/contracts', $params);
    }

    /** @param array<string, mixed> $params */
    private function call(string $method, string $route, array $params): WP_REST_Response
    {
        $request = new WP_REST_Request($method, '/ecrm/v1' . $route);
        $request->set_body_params($params);

        return rest_do_request($request);
    }
}
