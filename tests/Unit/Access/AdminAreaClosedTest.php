<?php

/**
 * (414) Ποιος μένει έξω από το wp-admin: όποιος δουλεύει στο CRM και δεν
 * είναι διαχειριστής.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Access;

use EnergyCRM\Access\AdminAreaClosed;
use PHPUnit\Framework\TestCase;

final class AdminAreaClosedTest extends TestCase
{
    public function testOnlyCrmUsersWhoAreNotAdministratorsAreSentToTheCrm(): void
    {
        self::assertTrue(AdminAreaClosed::keepsOut(true, false), 'Πωλητής/συνεργάτης → CRM.');
        self::assertFalse(AdminAreaClosed::keepsOut(true, true), 'Ο διαχειριστής μένει.');
        self::assertFalse(AdminAreaClosed::keepsOut(false, false), 'Όποιος δεν είναι του CRM δεν μας αφορά.');
        self::assertFalse(AdminAreaClosed::keepsOut(false, true));
    }
}
