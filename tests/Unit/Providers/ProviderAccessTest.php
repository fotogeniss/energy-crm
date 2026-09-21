<?php

/**
 * Ο κανόνας «κανείς δεν βλέπει κάτι που δεν βλέπει ο από πάνω του» (278) --
 * καθαρή λογική, χωρίς βάση.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Providers;

use EnergyCRM\Providers\Domain\ProviderAccess;
use PHPUnit\Framework\TestCase;

final class ProviderAccessTest extends TestCase
{
    /** Η απάντηση είναι η τομή όλης της γραμμής, όχι η λίστα του ίδιου. */
    public function testTheAnswerIsWhatEveryoneAboveAlsoHas(): void
    {
        $access = ProviderAccess::along([[1, 2, 3, 4], [2, 3, 9], [3, 2]]);

        self::assertSame([2, 3], $access->ids());
        self::assertTrue($access->allows(2));
        self::assertFalse($access->allows(1), 'Πέρασε πάροχος που δεν έχει ο τελευταίος της γραμμής.');
        self::assertFalse($access->allows(9), 'Πέρασε πάροχος που δεν έχει ο πρώτος της γραμμής.');
    }

    /** Ενας άδειος κρίκος κλείνει τα πάντα από κάτω του. */
    public function testAnEmptyListAnywhereClosesEverything(): void
    {
        $access = ProviderAccess::along([[1, 2], [], [1, 2]]);

        self::assertSame([], $access->ids());
        self::assertFalse($access->isUnrestricted());
        self::assertFalse($access->allows(1));
    }

    /** Κανείς δεν περιορίζει (όλη η γραμμή admin): χωρίς περιορισμό -- ΟΧΙ «κανένας». */
    public function testNoListsMeansNobodyRestricts(): void
    {
        $access = ProviderAccess::along([]);

        self::assertTrue($access->isUnrestricted());
        self::assertNull($access->ids());
        self::assertTrue($access->allows(42));
    }

    public function testUnrestrictedAllowsEverythingReal(): void
    {
        $access = ProviderAccess::unrestricted();

        self::assertTrue($access->allows(1));
        self::assertFalse($access->allows(0), 'Το id 0 δεν είναι πάροχος.');
        self::assertSame([5, 1], $access->filter([5, 1, 0, -3]));
    }

    public function testCoversNeedsEveryOne(): void
    {
        $access = ProviderAccess::only([1, 2]);

        self::assertTrue($access->covers([2, 1]));
        self::assertTrue($access->covers([]), 'Τίποτα ζητημένο = τίποτα να αρνηθεί.');
        self::assertFalse($access->covers([1, 7]));
    }

    /** Ιδια απάντηση όπως κι αν γράφτηκε η λίστα: σειρά, διπλά, strings από meta. */
    public function testTheListIsNormalised(): void
    {
        self::assertSame([2, 5], ProviderAccess::only(['5', 2, 5, '0', -1])->ids());
        self::assertSame([3, 1], ProviderAccess::only([1, 2, 3])->filter([3, 9, 1]));
    }
}
