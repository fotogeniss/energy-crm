<?php

/**
 * (417) Ίδιος αριθμός παροχής, όπως κι αν γράφτηκε.
 *
 * @kanonas K11
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Contract;

use EnergyCRM\Domain\Contract\SupplyNumber;
use PHPUnit\Framework\TestCase;

final class SupplyNumberTest extends TestCase
{
    public function testSpacesDashesAndSlashesDoNotMatter(): void
    {
        self::assertSame('112345678018', SupplyNumber::key('1 12345678-01 8'));
        self::assertSame('112345678018', SupplyNumber::key(' 112345678018 '));
        self::assertSame('112345678018', SupplyNumber::key('1/12345678/01/8'));
    }

    public function testLettersStayAndAreUppercase(): void
    {
        self::assertSame('GR0123ABC', SupplyNumber::key('gr 0123-abc'));
    }

    public function testTheDotStaysSoAnExcelDecimalIsNotGlued(): void
    {
        self::assertNotSame(SupplyNumber::key('112345678018'), SupplyNumber::key('112345678018.0'));
    }

    public function testTheSqlSideDoesTheSameToTheColumn(): void
    {
        $sql = SupplyNumber::sql('c.supply_number');

        self::assertStringStartsWith('REPLACE(', $sql);
        self::assertStringContainsString('UPPER(c.supply_number)', $sql);
        foreach ([" ' '", " '-'", " '/'"] as $char) {
            self::assertStringContainsString(',' . $char . ", '')", $sql);
        }
    }
}
