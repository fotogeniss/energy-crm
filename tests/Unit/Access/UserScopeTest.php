<?php

/**
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Access;

use EnergyCRM\Access\UserScope;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class UserScopeTest extends TestCase
{
    public function testSelfScopeCoversOnlyTheActor(): void
    {
        $scope = UserScope::forSelf(7);

        self::assertSame([7], $scope->userIds());
        self::assertTrue($scope->includes(7));
        self::assertFalse($scope->includes(8));
        self::assertFalse($scope->isTeamWide());
    }

    public function testTeamScopeAlwaysContainsTheActor(): void
    {
        $scope = UserScope::forTeam(7, [12, 15]);

        self::assertContains(7, $scope->userIds());
        self::assertTrue($scope->isTeamWide());
    }

    public function testTeamScopeDeduplicatesAndDropsInvalidIds(): void
    {
        $scope = UserScope::forTeam(7, [12, 12, 7, 0, -3]);

        self::assertSame([7, 12], $scope->userIds());
    }

    public function testAnOutsiderIsNeverIncluded(): void
    {
        $scope = UserScope::forTeam(7, [12, 15]);

        self::assertFalse($scope->includes(99));
    }

    public function testAdministratorScopeIncludesEveryone(): void
    {
        $scope = UserScope::forAdministrator(1);

        self::assertTrue($scope->isAdministrator());
        self::assertTrue($scope->includes(4242));
    }

    public function testPlaceholdersMatchTheNumberOfIds(): void
    {
        $scope = UserScope::forTeam(7, [12, 15]);

        self::assertSame('%d,%d,%d', $scope->placeholders());
        self::assertCount(
            substr_count($scope->placeholders(), '%d'),
            $scope->userIds()
        );
    }

    public function testPlaceholdersAreNeverEmpty(): void
    {
        self::assertSame('%d', UserScope::forSelf(7)->placeholders());
        self::assertSame('%d', UserScope::forTeam(7, [])->placeholders());
    }

    public function testNarrowingDropsTheDownline(): void
    {
        $scope = UserScope::forTeam(7, [12, 15])->toSelfOnly();

        self::assertSame([7], $scope->userIds());
    }

    public function testAScopeRequiresARealActor(): void
    {
        $this->expectException(InvalidArgumentException::class);

        UserScope::forSelf(0);
    }
}
