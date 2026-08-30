<?php

/**
 * `GET /team/live` -- ο πίνακας δραστηριότητας ομάδας σε πραγματικό χρόνο.
 *
 * AUDIT εύρημα §2.5 (EKKREMI-29-08.html): 19 από τα 54 route paths χωρίς
 * integration test, τέσσερα ρητά αναφερόμενα -- αυτό είναι το δεύτερο. Το
 * `TeamActivityRepository::memberIds()` λέει ρητά στο δικό του docblock ότι
 * λύνει τα ids ο ίδιος από το scope "ώστε ένα αυθαίρετο id να μην μπορεί να
 * μπει στο WHERE" -- αλλά κανένα test δεν το είχε αποδείξει μέσω της ίδιας
 * της διαδρομής.
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

final class TeamActivityScopeTest extends IntegrationTestCase
{
    private ContractRepository $contracts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contracts = new ContractRepository();
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    /** The exact scenario the repository's own docblock exists to prevent. */
    public function testManagerSeesOwnDownlineButNotAStranger(): void
    {
        $manager = $this->makeCrmUser(Roles::PARTNER);
        $partner = $this->makeCrmUser(Roles::SELLER);

        update_user_meta($partner, NetworkRepository::PARENT_META, $manager);
        (new NetworkRepository())->rebuild($partner);

        $stranger = $this->makeCrmUser(Roles::SELLER);

        wp_set_current_user($manager);
        $ids = array_column($this->live()->get_data()['members'], 'id');

        self::assertContains($partner, $ids);
        self::assertContains($manager, $ids, 'The manager sees their own row too.');
        self::assertNotContains($stranger, $ids, 'A stranger outside the downline must not appear.');
    }

    /** The row totals must actually be the sum of the per-member figures shown. */
    public function testTotalsAreTheSumOfThePerMemberFigures(): void
    {
        $manager = $this->makeCrmUser(Roles::PARTNER);
        $partner = $this->makeCrmUser(Roles::SELLER);

        update_user_meta($partner, NetworkRepository::PARENT_META, $manager);
        (new NetworkRepository())->rebuild($partner);

        $this->activeContractFor($manager, '55500000001');
        $this->activeContractFor($partner, '55500000002');
        $this->activeContractFor($partner, '55500000003');

        wp_set_current_user($manager);
        $data = $this->live()->get_data();

        $sum = array_sum(array_column($data['members'], 'active'));

        self::assertSame($sum, $data['totals']['active']);
        self::assertSame(3, $data['totals']['active']);
    }

    public function testTheActingManagerIsFlaggedAsSelf(): void
    {
        $manager = $this->makeCrmUser(Roles::PARTNER);
        wp_set_current_user($manager);

        $row = null;

        foreach ($this->live()->get_data()['members'] as $member) {
            if ($member['id'] === $manager) {
                $row = $member;
            }
        }

        self::assertNotNull($row, 'The manager must see their own row.');
        self::assertTrue($row['is_self']);
    }

    private function activeContractFor(int $partnerId, string $supply): int
    {
        $id = $this->contracts->create(
            ['status' => 'active', 'supply_number' => $supply, 'energy_type' => 'power'],
            UserScope::forSelf($partnerId)
        );

        self::assertGreaterThan(0, $id);

        return $id;
    }

    private function live(): WP_REST_Response
    {
        return rest_do_request(new WP_REST_Request('GET', '/ecrm/v1/team/live'));
    }
}
