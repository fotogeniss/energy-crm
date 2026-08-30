<?php

/**
 * `GET /network` -- οι sub-partners που τρέχουν δικές τους ομάδες.
 *
 * AUDIT εύρημα §2.5 (EKKREMI-29-08.html): 19 από τα 54 route paths χωρίς
 * integration test, τέσσερα ρητά αναφερόμενα -- αυτό είναι το τρίτο.
 * `TeamController::network()` φιλτράρει `directReportsOf($actor)` σε όσους
 * έχουν `Roles::PARTNER` -- ΕΝΑ επίπεδο, όχι όλο το υποδέντρο. Κανένα test
 * δεν είχε αποδείξει ότι αυτό το όριο βάθους πράγματι κρατάει: ένας
 * εγγονός-Συνεργάτης (report του παιδιού, όχι του ίδιου) δεν πρέπει να
 * εμφανίζεται εδώ.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Persistence\TeamRepository;
use WP_REST_Request;
use WP_REST_Response;

final class NetworkScopeTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    public function testOnlyDirectReportPartnersAppearNotSellers(): void
    {
        $actor = $this->makeCrmUser(Roles::PARTNER);

        $subPartner = $this->makeCrmUser(Roles::PARTNER);
        update_user_meta($subPartner, TeamRepository::PARENT_META, $actor);

        $seller = $this->makeCrmUser(Roles::SELLER);
        update_user_meta($seller, TeamRepository::PARENT_META, $actor);

        wp_set_current_user($actor);
        $ids = array_column($this->network()->get_data()['partners'], 'id');

        self::assertContains($subPartner, $ids);
        self::assertNotContains($seller, $ids, 'A direct-report Seller must not appear under /network.');
    }

    /** The one-level limit that distinguishes /network from a full-depth downline dump. */
    public function testAGrandchildPartnerDoesNotAppear(): void
    {
        $actor = $this->makeCrmUser(Roles::PARTNER);

        $child = $this->makeCrmUser(Roles::PARTNER);
        update_user_meta($child, TeamRepository::PARENT_META, $actor);

        $grandchild = $this->makeCrmUser(Roles::PARTNER);
        update_user_meta($grandchild, TeamRepository::PARENT_META, $child);

        wp_set_current_user($actor);
        $ids = array_column($this->network()->get_data()['partners'], 'id');

        self::assertContains($child, $ids);
        self::assertNotContains(
            $grandchild,
            $ids,
            'Only one level down -- the grandchild belongs to the child, not the actor.'
        );
    }

    public function testAnUnrelatedPartnerDoesNotAppear(): void
    {
        $actor    = $this->makeCrmUser(Roles::PARTNER);
        $stranger = $this->makeCrmUser(Roles::PARTNER);

        wp_set_current_user($actor);
        $ids = array_column($this->network()->get_data()['partners'], 'id');

        self::assertNotContains($stranger, $ids);
    }

    /** team_size on each row is the sub-partner's own direct-report count, not the actor's. */
    public function testTeamSizeReflectsTheSubPartnersOwnDirectReports(): void
    {
        $actor      = $this->makeCrmUser(Roles::PARTNER);
        $subPartner = $this->makeCrmUser(Roles::PARTNER);
        update_user_meta($subPartner, TeamRepository::PARENT_META, $actor);

        $subPartnersOwnReport = $this->makeCrmUser(Roles::SELLER);
        update_user_meta($subPartnersOwnReport, TeamRepository::PARENT_META, $subPartner);

        wp_set_current_user($actor);
        $row = null;

        foreach ($this->network()->get_data()['partners'] as $partner) {
            if ($partner['id'] === $subPartner) {
                $row = $partner;
            }
        }

        self::assertNotNull($row);
        self::assertSame(1, $row['team_size']);
    }

    private function network(): WP_REST_Response
    {
        return rest_do_request(new WP_REST_Request('GET', '/ecrm/v1/network'));
    }
}
