<?php

/**
 * (349) «Αριθμός Αίτησης»: από το βήμα 1 στη στήλη provider_ref, στο πεδίο
 * arithmos_aitisis του εντύπου και στην αναζήτηση. Σβήνεται κιόλας.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use ECRM_FormFill;
use EnergyCRM\Access\Roles;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractQueries;
use EnergyCRM\Persistence\Tables;
use WP_REST_Request;
use WP_REST_Response;

final class ApplicationNumberTest extends IntegrationTestCase
{
    private const VALID_AFM = '090003373';

    private int $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seller = $this->makeCrmUser(Roles::SELLER);
        wp_set_current_user($this->seller);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    public function testTheNumberIsSavedAndPrinted(): void
    {
        $id = $this->created(['provider_ref' => ' 1853-1-0046485 ']);

        self::assertSame('1853-1-0046485', $this->storedRow(Tables::CONTRACTS, $id)['provider_ref']);
        self::assertSame(
            '1853-1-0046485',
            ECRM_FormFill::values($this->storedRow(Tables::CONTRACTS, $id))['arithmos_aitisis']
        );
    }

    public function testAnEmptyFieldClearsIt(): void
    {
        $id = $this->created(['provider_ref' => '1853-1-0046485']);

        $this->save(['contract_id' => $id, 'provider_ref' => '']);

        self::assertNull($this->storedRow(Tables::CONTRACTS, $id)['provider_ref']);
    }

    /** Άλλη αποθήκευση χωρίς το πεδίο (π.χ. από άλλη οθόνη) δεν το σβήνει. */
    public function testASaveWithoutTheFieldKeepsIt(): void
    {
        $id = $this->created(['provider_ref' => '1853-1-0046485']);

        $this->save(['contract_id' => $id, 'notes' => 'κάτι']);

        self::assertSame('1853-1-0046485', $this->storedRow(Tables::CONTRACTS, $id)['provider_ref']);
    }

    public function testTheContractsSearchFindsIt(): void
    {
        $id = $this->created(['provider_ref' => '1853-1-0046485']);

        $rows = (new ContractQueries())->search(UserScope::forSelf($this->seller), '', '0046485');

        self::assertContains($id, array_map('intval', array_column($rows, 'id')));
    }

    /** @param array<string, mixed> $extra */
    private function created(array $extra): int
    {
        $response = $this->save($extra);
        $id       = (int) ($response->get_data()['contract_id'] ?? 0);

        self::assertGreaterThan(0, $id, (string) ($response->get_data()['error'] ?? ''));

        return $id;
    }

    /** @param array<string, mixed> $params */
    private function save(array $params): WP_REST_Response
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
}
