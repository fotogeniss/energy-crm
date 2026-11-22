<?php

/**
 * (443) Έλεγχος λογικής 22/11, φόρμα αίτησης.
 *
 * - Αλλαγή ΑΦΜ σε επεξεργασία: η αίτηση δένει στον πελάτη που έχει ήδη αυτό
 *   το ΑΦΜ, ή παίρνει νέο πελάτη αν ο παλιός έχει κι άλλες αιτήσεις, ή
 *   διορθώνει τον παλιό αν είναι η μόνη του (απόφαση ιδιοκτήτη 22/11).
 * - Η συναίνεση GDPR καταγράφεται μία φορά: μια επόμενη αποθήκευση δεν
 *   ξαναγράφει ώρα και IP.
 *
 * @kanonas K11
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\CustomerRepository;
use EnergyCRM\Persistence\Tables;
use WP_REST_Request;
use WP_REST_Response;

final class FormSaveAudit2211Test extends IntegrationTestCase
{
    private const AFM_A = '090003373';

    private const AFM_B = '123456783';

    private const AFM_C = '094014201';

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

    public function testAnAfmThatBelongsToAnotherCustomerMovesTheContractToHim(): void
    {
        $b     = $this->draft('Βασίλης', self::AFM_B);
        $a     = $this->draft('Άννα', self::AFM_A);
        $bCust = $this->customerOf($b);
        $aCust = $this->customerOf($a);

        $response = $this->save(['contract_id' => $a, 'afm' => self::AFM_B, 'first_name' => 'Βασίλης']);

        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));
        self::assertSame($bCust, $this->customerOf($a), 'Η αίτηση έπρεπε να πάει στον πελάτη με αυτό το ΑΦΜ.');
        self::assertSame(self::AFM_A, $this->afmOf($aCust), 'Ο παλιός πελάτης δεν έπρεπε να αλλάξει ΑΦΜ.');
    }

    public function testACustomerWithOtherContractsIsNotRewrittenForOneOfThem(): void
    {
        $first  = $this->draft('Άννα', self::AFM_A);
        $aCust  = $this->customerOf($first);
        $second = $this->save([
            'customer_id' => $aCust,
            'first_name'  => 'Άννα',
            'afm'         => self::AFM_A,
            'status'      => 'draft',
        ]);
        $secondId = (int) $second->get_data()['contract_id'];
        self::assertSame($aCust, $this->customerOf($secondId));

        $response = $this->save(['contract_id' => $secondId, 'afm' => self::AFM_C, 'first_name' => 'Γιώργος']);

        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));
        self::assertNotSame($aCust, $this->customerOf($secondId), 'Χρειαζόταν νέος πελάτης.');
        self::assertSame(self::AFM_C, $this->afmOf($this->customerOf($secondId)));
        self::assertSame(self::AFM_A, $this->afmOf($aCust), 'Η άλλη αίτηση της Άννας άλλαξε πρόσωπο.');
    }

    public function testTheOnlyContractOfACustomerCorrectsHim(): void
    {
        $a     = $this->draft('Άννα', self::AFM_A);
        $aCust = $this->customerOf($a);

        $response = $this->save(['contract_id' => $a, 'afm' => self::AFM_C]);

        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));
        self::assertSame($aCust, $this->customerOf($a));
        self::assertSame(self::AFM_C, $this->afmOf($aCust));
    }

    public function testConsentIsRecordedOnce(): void
    {
        global $wpdb;

        $a = $this->draft('Άννα', self::AFM_A, ['consent' => 1]);
        $wpdb->update(
            Tables::name(Tables::CONTRACTS),
            ['consent_at' => '2026-01-02 10:00:00', 'consent_ip' => '10.0.0.1'],
            ['id' => $a]
        );

        $response = $this->save(['contract_id' => $a, 'consent' => 1, 'notes' => 'διόρθωση']);

        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));
        $row = $this->storedRow('contracts', $a);
        self::assertSame('2026-01-02 10:00:00', (string) $row['consent_at']);
        self::assertSame('10.0.0.1', (string) $row['consent_ip']);
    }

    /** @param array<string, mixed> $more */
    private function draft(string $name, string $afm, array $more = []): int
    {
        $params   = ['first_name' => $name, 'last_name' => 'Δοκιμή', 'afm' => $afm, 'status' => 'draft'];
        $response = $this->save($params + $more);
        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));

        return (int) $response->get_data()['contract_id'];
    }

    private function customerOf(int $contractId): int
    {
        return (int) $this->storedRow('contracts', $contractId)['customer_id'];
    }

    private function afmOf(int $customerId): string
    {
        $row = (new CustomerRepository())->find($customerId, UserScope::forAdministrator(1));

        return (string) ($row['afm'] ?? '');
    }

    /** @param array<string, mixed> $params */
    private function save(array $params): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/ecrm/v1/contracts');
        $request->set_body_params($params);

        return rest_do_request($request);
    }
}
