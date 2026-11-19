<?php

/**
 * (426) Η «Αίτηση/Σύμβαση» της ΔΕΗ γράφει την εγγύηση ολογράφως και σε αριθμό.
 *
 * Έλεγχος στο live 19/11: η αίτηση τυπώθηκε με το κουτί κενό, επειδή η
 * εγγύηση δεν είχε γραφτεί. Ο ιδιοκτήτης: «Υποχρεωτική στη ΔΕΗ». Το 0 είναι
 * ποσό («Μηδέν»), το κενό δεν είναι.
 *
 * Ίδιος κανόνας με το ΑΦΜ (DraftExitAfmTest): ελέγχεται στην έξοδο από το
 * πρόχειρο, σε κάθε πόρτα. Ό,τι έχει ήδη περάσει δεν ξανακρίνεται.
 *
 * @kanonas K4
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Domain\Contract\ContractStatus;
use EnergyCRM\Infrastructure\DraftExitGate;
use EnergyCRM\Persistence\Tables;
use EnergyCRM\Providers\Persistence\ProviderGrantRepository;
use WP_REST_Request;
use WP_REST_Response;

final class DeiGuaranteeIsRequiredTest extends IntegrationTestCase
{
    private const ROUTE = '/ecrm/v1/contracts';

    private const VALID_AFM = '090003373';

    private int $provider = 0;

    protected function setUp(): void
    {
        global $wpdb;

        parent::setUp();

        $user = $this->makeCrmUser(Roles::SELLER);

        $wpdb->insert(Tables::name(Tables::PROVIDERS), ['slug' => 'ecrm-dei-guarantee', 'name' => 'ΔΕΗ δοκιμή']);
        $this->provider = (int) $wpdb->insert_id;

        (new ProviderGrantRepository())->set($user, [$this->provider]);
        wp_set_current_user($user);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    /** Η «Οριστικοποίηση» χωρίς εγγύηση: 422 στο πεδίο της, και μένει πρόχειρο. */
    public function testFinalisingADeiDraftWithoutAGuaranteeIsRefused(): void
    {
        $contractId = $this->makeDraft($this->program('dei_myhome_entertwo'), []);

        $response = $this->save(['contract_id' => $contractId, 'status' => ContractStatus::Presale->value]);

        self::assertSame(422, $response->get_status(), 'Η ΔΕΗ χωρίς εγγύηση έπρεπε να σταματήσει.');
        self::assertSame('guarantee', $response->get_data()['field']);
        self::assertSame(DraftExitGate::MISSING_GUARANTEE, $response->get_data()['error']);
        self::assertSame(ContractStatus::Draft->value, $this->statusOf($contractId));
    }

    /** Το 0 είναι ποσό: τυπώνεται «Μηδέν», άρα περνάει. */
    public function testAZeroGuaranteeIsAnAmount(): void
    {
        $contractId = $this->makeDraft($this->program('dei_myhome_entertwo'), []);

        $response = $this->save([
            'contract_id' => $contractId,
            'status'      => ContractStatus::Presale->value,
            'extra'       => ['guarantee' => '0'],
        ]);

        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));
        self::assertSame(ContractStatus::Presale->value, $this->statusOf($contractId));
    }

    /** Η άλλη πόρτα (οθόνη κατάστασης) ρωτά το ίδιο, και η αποθηκευμένη εγγύηση μετράει. */
    public function testTheStatusScreenAsksTheSame(): void
    {
        $program = $this->program('dei_myhome_entertwo');
        $refused = $this->makeDraft($program, []);
        $stored  = $this->makeDraft($program, ['guarantee' => '120']);

        $response = $this->changeStatus($refused, ContractStatus::Presale->value);
        self::assertSame(422, $response->get_status());
        self::assertSame('guarantee', $response->get_data()['field']);
        self::assertSame(ContractStatus::Draft->value, $this->statusOf($refused));

        $response = $this->changeStatus($stored, ContractStatus::Presale->value);
        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));
    }

    /** Πρόγραμμα που δεν τυπώνει την «Αίτηση/Σύμβαση» (άλλος πάροχος, αέριο ΔΕΗ): χωρίς έλεγχο. */
    public function testOtherProgramsAreNotAsked(): void
    {
        foreach (['ecrm_not_dei', 'dei_myhome_gascontrol'] as $code) {
            $contractId = $this->makeDraft($this->program($code), []);

            $response = $this->save(['contract_id' => $contractId, 'status' => ContractStatus::Presale->value]);

            $error    = (string) ($response->get_data()['error'] ?? '');

            self::assertSame(200, $response->get_status(), $code . ': ' . $error);
        }
    }

    // --- fixtures and helpers ------------------------------------------------

    private function program(string $code): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::PROGRAMS), [
            'provider_id' => $this->provider,
            'name'        => $code,
            'code'        => $code,
            'energy_type' => str_contains($code, 'gas') ? 'gas' : 'power',
            'category'    => 'home',
            'price_type'  => 'fixed',
            'active'      => 1,
        ]);

        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string, string> $extra
     */
    private function makeDraft(int $programId, array $extra): int
    {
        $payload = [
            'first_name'  => 'Εγγύηση',
            'last_name'   => 'Δοκιμή',
            'afm'         => self::VALID_AFM,
            'provider_id' => $this->provider,
            'program_id'  => $programId,
            'energy_type' => 'power',
            'status'      => ContractStatus::Draft->value,
        ];

        if ($extra !== []) {
            $payload['extra'] = $extra;
        }

        $response = $this->save($payload);
        $data     = $response->get_data();

        self::assertSame(200, $response->get_status(), (string) ($data['error'] ?? ''));

        return (int) $data['contract_id'];
    }

    private function statusOf(int $contractId): string
    {
        return (string) $this->storedRow('contracts', $contractId)['status'];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function save(array $params): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', self::ROUTE);
        $request->set_body_params($params);

        return rest_do_request($request);
    }

    private function changeStatus(int $contractId, string $status): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', self::ROUTE . '/' . $contractId . '/status');
        $request->set_body_params(['status' => $status]);

        return rest_do_request($request);
    }
}
