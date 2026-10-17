<?php

/**
 * (318) Ο πίνακας του Back office, ως δεδομένο.
 *
 * Το integration test ελέγχει τι βγαίνει από τις διαδρομές. Αυτό εδώ φυλάει
 * το ίδιο το matrix(), που είναι το «ένα σημείο που αλλάζει»: αν κάποιος
 * δώσει αργότερα στο Back office VIEW_COMMISSIONS ή MANAGE_TEAM, ή δώσει το
 * VIEW_ALL σε συνεργάτη, να σπάσει εδώ με τον λόγο γραμμένο.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Access;

use EnergyCRM\Access\Capability;
use EnergyCRM\Access\Roles;
use PHPUnit\Framework\TestCase;

final class BackOfficeRoleTest extends TestCase
{
    public function testBackOfficeSeesEverythingButNoMoney(): void
    {
        $caps = Roles::capabilitiesFor(Roles::BACKOFFICE);

        self::assertContains(Capability::USE_CRM, $caps);
        self::assertContains(Capability::VIEW_ALL, $caps);
        self::assertNotContains(Capability::VIEW_COMMISSIONS, $caps, 'Το Back office δεν βλέπει προμήθειες/πληρωμές.');
        self::assertNotContains(
            Capability::MANAGE_TEAM,
            $caps,
            'Μέλος που φτιάχνει το Back office θα γινόταν δικό του δίκτυο και θα του πλήρωνε προμήθεια.'
        );
    }

    public function testNoOtherCrmRoleSeesTheWholeCompany(): void
    {
        foreach (Roles::matrix() as $slug => $definition) {
            if ($slug === Roles::BACKOFFICE) {
                continue;
            }

            self::assertNotContains(
                Capability::VIEW_ALL,
                $definition['capabilities'],
                "Ο ρόλος {$slug} πήρε VIEW_ALL."
            );
        }
    }

    public function testTheCapabilityIsRegisteredSoSyncGrantsAndRevokesIt(): void
    {
        self::assertContains(Capability::VIEW_ALL, Capability::all());
        self::assertSame('Back office', Roles::matrix()[Roles::BACKOFFICE]['label']);
    }
}
