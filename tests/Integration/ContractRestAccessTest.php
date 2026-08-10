<?php

/**
 * One partner's contract, asked for by another — over HTTP this time.
 *
 * ContractScopeTest already proves the repository refuses. This proves the
 * refusal survives the trip through the route: the permission_callback, the
 * args schema, the ScopeResolver reading the current user, and the controller
 * turning a null row into an answer. Those four are what an attacker actually
 * meets, and until now none of them had been exercised.
 *
 * Everything here goes through rest_do_request, so the request is dispatched by
 * WP_REST_Server exactly as it would be for a browser.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\NetworkRepository;
use WP_REST_Request;
use WP_REST_Response;

final class ContractRestAccessTest extends IntegrationTestCase
{
    private const SUPPLY_NUMBER = '12345678901';

    private ContractRepository $contracts;

    private int $alice;

    private int $bob;

    private int $contractId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contracts = new ContractRepository();
        $this->alice     = $this->makeCrmUser(Roles::SELLER);
        $this->bob       = $this->makeCrmUser(Roles::SELLER);

        $this->contractId = $this->contracts->create(
            ['status' => 'new', 'supply_number' => self::SUPPLY_NUMBER, 'energy_type' => 'power'],
            UserScope::forSelf($this->alice)
        );

        self::assertGreaterThan(0, $this->contractId);
    }

    protected function tearDown(): void
    {
        // The current user is global; left set, it would decide the next test.
        wp_set_current_user(0);

        parent::tearDown();
    }

    public function testTheOwnerReadsTheirOwnContract(): void
    {
        wp_set_current_user($this->alice);

        $response = $this->get('/ecrm/v1/contracts/' . $this->contractId);
        $body     = $response->get_data();

        self::assertSame(200, $response->get_status());
        self::assertTrue($body['ok']);
        self::assertSame(self::SUPPLY_NUMBER, $body['contract']['supply_number']);
    }

    public function testNobodyReadsAContractWithoutLoggingIn(): void
    {
        wp_set_current_user(0);

        self::assertSame(401, $this->get('/ecrm/v1/contracts/' . $this->contractId)->get_status());
    }

    /**
     * A WordPress account is not a CRM account.
     *
     * 403 rather than 401: they are logged in, they simply may not be here.
     */
    public function testALoggedInUserWithoutACrmRoleIsRefused(): void
    {
        wp_set_current_user($this->makePartner());

        self::assertSame(403, $this->get('/ecrm/v1/contracts/' . $this->contractId)->get_status());
    }

    /**
     * The answer another partner gets must be indistinguishable from the answer
     * for a contract that was never created.
     *
     * A 403 here would confirm the row exists, which is half of what an
     * enumeration attack is after. So both the status and the body are compared
     * against a genuinely absent id, not merely asserted to be 404.
     */
    public function testAnotherPartnerCannotTellTheContractApartFromOneThatDoesNotExist(): void
    {
        wp_set_current_user($this->bob);

        $somebodyElses = $this->get('/ecrm/v1/contracts/' . $this->contractId);
        $neverExisted  = $this->get('/ecrm/v1/contracts/999999999');

        self::assertSame(404, $somebodyElses->get_status());
        self::assertSame($neverExisted->get_status(), $somebodyElses->get_status());
        self::assertSame($neverExisted->get_data(), $somebodyElses->get_data());
    }

    /**
     * The control that makes every refusal above mean something.
     *
     * A test that has only ever seen a 404 cannot tell "the scope refused" from
     * "this route is broken and refuses everyone" — and the second one passes
     * just as green. So here is the same request, over the same route, with one
     * thing changed: the caller now has the owner in their downline. It must
     * succeed.
     *
     * Delete this and the whole file would still pass with the endpoint
     * removed. That is the failure mode this method exists to prevent.
     */
    public function testAManagerWithTheOwnerInTheirDownlineDoesGetThrough(): void
    {
        $manager = $this->makeCrmUser(Roles::PARTNER);

        update_user_meta($this->alice, NetworkRepository::PARENT_META, $manager);
        (new NetworkRepository())->rebuild($this->alice);

        wp_set_current_user($manager);

        $response = $this->get('/ecrm/v1/contracts/' . $this->contractId);

        self::assertSame(
            200,
            $response->get_status(),
            'A manager cannot reach their own downline — the refusals in this file prove nothing.'
        );
        self::assertSame(self::SUPPLY_NUMBER, $response->get_data()['contract']['supply_number']);
    }

    public function testTheListNeverCarriesAnotherPartnersContract(): void
    {
        wp_set_current_user($this->bob);

        $body = $this->get('/ecrm/v1/contracts')->get_data();

        self::assertTrue($body['ok']);
        self::assertSame([], $this->idsIn($body['rows']));
        self::assertSame(0, $body['counts']['all']);
    }

    /**
     * Asking for the team view does not grant one.
     *
     * `scope=team` is accepted from anyone, because the widening is decided by
     * the resolver from the user's capabilities and not by the parameter. A
     * seller has no downline, so the parameter has to change nothing.
     */
    public function testAskingForTheTeamScopeDoesNotWidenASellersReach(): void
    {
        wp_set_current_user($this->bob);

        $body = $this->get('/ecrm/v1/contracts', ['scope' => 'team'])->get_data();

        self::assertSame([], $this->idsIn($body['rows']));
    }

    /**
     * The schema refuses before the handler runs.
     *
     * Worth its own test because the enum is the only thing standing between a
     * caller and whatever `scope` would mean if it reached the controller as
     * free text.
     */
    public function testAnUnknownScopeIsRejectedBySchema(): void
    {
        wp_set_current_user($this->alice);

        $response = $this->get('/ecrm/v1/contracts', ['scope' => 'everything']);

        self::assertSame(400, $response->get_status());
        self::assertSame('rest_invalid_param', $response->get_data()['code']);
    }

    /**
     * The export dialog's query string, exactly as the browser builds it.
     *
     * Every field the user leaves blank is still sent, as an empty string —
     * `from=`, `to=`, `partner=`. The schema rejected all three, and since
     * WordPress validates args before it calls the permission callback, the
     * endpoint answered 400 to everyone. No amount of scope testing would have
     * noticed: the request never got as far as the scope.
     */
    public function testTheExportDialogsQueryStringIsAcceptedBySchema(): void
    {
        wp_set_current_user($this->alice);

        self::assertTrue(
            current_user_can('ecrm_export_data'),
            'A Πωλητής is supposed to be allowed to export.'
        );

        $response = $this->get('/ecrm/v1/contracts/export', [
            'status'  => '',
            'from'    => '',
            'to'      => '',
            'scope'   => 'own',
            'partner' => '',
            'q'       => '',
        ]);

        self::assertNotSame(
            400,
            $response->get_status(),
            'The export schema rejected its own dialog: ' . wp_json_encode($response->get_data())
        );
    }

    /** A seller may sell, not delete: the capability gate answers first. */
    public function testASellerCannotDeleteEvenTheirOwnContract(): void
    {
        wp_set_current_user($this->alice);

        $response = rest_do_request(
            new WP_REST_Request('DELETE', '/ecrm/v1/contracts/' . $this->contractId)
        );

        self::assertSame(403, $response->get_status());
        self::assertTrue($this->contractStillExists());
    }

    /**
     * The capability and the scope are two different gates, and this is the one
     * that only the second can stop.
     *
     * A Συνεργάτης genuinely holds ecrm_delete_contract, so the permission
     * callback lets them through. What must refuse them is the scope inside the
     * handler — and a 404, not a 403, for the same reason as above.
     */
    public function testAPartnerWithTheCapabilityStillCannotDeleteOutsideTheirScope(): void
    {
        wp_set_current_user($this->makeCrmUser(Roles::PARTNER));

        self::assertTrue(
            current_user_can('ecrm_delete_contract'),
            'This test is meaningless unless the capability gate is passed.'
        );

        $response = rest_do_request(
            new WP_REST_Request('DELETE', '/ecrm/v1/contracts/' . $this->contractId)
        );

        self::assertSame(404, $response->get_status());
        self::assertTrue($this->contractStillExists(), 'The contract was deleted from outside its scope.');
    }

    /** Read straight through the repository, so the check does not lean on the layer under test. */
    private function contractStillExists(): bool
    {
        return $this->contracts->exists($this->contractId, UserScope::forSelf($this->alice));
    }

    /**
     * @param array<string, string> $query
     */
    private function get(string $path, array $query = []): WP_REST_Response
    {
        $request = new WP_REST_Request('GET', $path);

        foreach ($query as $key => $value) {
            $request->set_param($key, $value);
        }

        return rest_do_request($request);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<int>
     */
    private function idsIn(array $rows): array
    {
        return array_values(array_map(static fn (array $row): int => (int) $row['id'], $rows));
    }
}
