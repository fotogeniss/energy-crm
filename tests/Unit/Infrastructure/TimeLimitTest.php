<?php

/**
 * TimeLimit::atLeast() must never shrink a budget it already granted.
 *
 * AUDIT 30/08: the method's own name and docblock promise "at least" -- but
 * the body called `set_time_limit($seconds)` unconditionally, which resets
 * PHP's clock to exactly `$seconds` from *this* call. A second call asking
 * for fewer seconds than an earlier call still had remaining silently cut
 * the budget instead of leaving it alone. Every real caller today passes 60,
 * so the shape never showed up in practice -- that is luck in the call
 * sites, not something this method guaranteed.
 *
 * `set_time_limit()` is exercised for real here rather than mocked: PHPUnit's
 * own CLI process has no enforced timeout to violate at the sub-second scale
 * these tests run at, and `ini_get('max_execution_time')` reports back
 * whatever the class just set, which is the only way to observe the
 * behaviour from outside without adding a testing seam to the class itself.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Infrastructure;

use EnergyCRM\Infrastructure\TimeLimit;
use PHPUnit\Framework\TestCase;

final class TimeLimitTest extends TestCase
{
    private string $originalLimit = '';

    protected function setUp(): void
    {
        parent::setUp();

        TimeLimit::resetForTests();

        $this->originalLimit = (string) ini_get('max_execution_time');
        ini_set('max_execution_time', '30');
    }

    protected function tearDown(): void
    {
        ini_set('max_execution_time', $this->originalLimit);
        TimeLimit::resetForTests();

        parent::tearDown();
    }

    public function testTheFirstCallExtendsAFiniteLimit(): void
    {
        TimeLimit::atLeast(60);

        self::assertSame(60, (int) ini_get('max_execution_time'));
    }

    /** The bug: a smaller follow-up request used to reset the clock backwards. */
    public function testASmallerFollowUpRequestDoesNotShrinkTheBudget(): void
    {
        TimeLimit::atLeast(60);
        TimeLimit::atLeast(20);

        self::assertSame(
            60,
            (int) ini_get('max_execution_time'),
            'A request for fewer seconds must not cut a larger budget already granted.'
        );
    }

    /** The other half: a genuinely larger request still has to extend. */
    public function testALargerFollowUpRequestDoesExtendTheBudget(): void
    {
        TimeLimit::atLeast(30);
        TimeLimit::atLeast(90);

        self::assertSame(90, (int) ini_get('max_execution_time'));
    }

    /** Unlimited (the CLI's normal state) is never turned into a finite budget. */
    public function testAGenuinelyUnlimitedBudgetIsNeverTouched(): void
    {
        ini_set('max_execution_time', '0');

        TimeLimit::atLeast(60);

        self::assertSame(0, (int) ini_get('max_execution_time'), 'Unlimited must stay unlimited.');
    }
}
