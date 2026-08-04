<?php

/**
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Access;

use EnergyCRM\Access\NetworkPath;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NetworkPathTest extends TestCase
{
    public function testARootPartnerIsWrappedInDelimiters(): void
    {
        self::assertSame('/1/', NetworkPath::root(1));
    }

    public function testAChildExtendsItsParentPath(): void
    {
        self::assertSame('/1/7/', NetworkPath::child('/1/', 7));
        self::assertSame('/1/7/23/', NetworkPath::child('/1/7/', 23));
    }

    /**
     * The delimiter is what keeps user 70 out of user 7's team. Without the
     * trailing slash, '/1/7' would prefix-match '/1/70/'.
     */
    public function testTheSubtreePatternDoesNotLeakIntoNeighbouringIds(): void
    {
        $prefix = '/1/7/';

        self::assertSame($prefix . '%', NetworkPath::subtreePattern($prefix));

        // What SQL's LIKE 'prefix%' resolves to, spelled out.
        self::assertTrue(str_starts_with('/1/7/', $prefix), 'the holder matches itself');
        self::assertTrue(str_starts_with('/1/7/23/', $prefix), 'a descendant matches');
        self::assertFalse(str_starts_with('/1/70/', $prefix), 'user 70 is not below user 7');
    }

    public function testIdsAreReturnedAncestorsFirst(): void
    {
        self::assertSame([1, 7, 23], NetworkPath::ids('/1/7/23/'));
    }

    public function testTheSubjectIsTheLastIdOnThePath(): void
    {
        self::assertSame(23, NetworkPath::subjectId('/1/7/23/'));
        self::assertSame(0, NetworkPath::subjectId('rubbish'));
    }

    public function testMembershipLooksAtTheWholeChain(): void
    {
        self::assertTrue(NetworkPath::contains('/1/7/23/', 7));
        self::assertFalse(NetworkPath::contains('/1/7/23/', 70));
    }

    /**
     * A repeated id means the parent chain loops, which would otherwise send
     * the rebuild into an endless walk.
     */
    public function testACyclicPathIsRejected(): void
    {
        self::assertFalse(NetworkPath::isValid('/1/7/1/'));
    }

    #[DataProvider('malformedPaths')]
    public function testMalformedPathsAreRejected(string $path): void
    {
        self::assertFalse(NetworkPath::isValid($path));
    }

    /** @return array<string, array{0: string}> */
    public static function malformedPaths(): array
    {
        return [
            'empty'            => [''],
            'no delimiters'    => ['17'],
            'missing trailing' => ['/1/7'],
            'missing leading'  => ['1/7/'],
            'non numeric'      => ['/1/abc/'],
            'zero id'          => ['/0/'],
            'double slash'     => ['/1//7/'],
        ];
    }

    public function testAChildOfAnInvalidPathIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        NetworkPath::child('nonsense', 7);
    }

    public function testARootNeedsARealUserId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        NetworkPath::root(0);
    }
}
