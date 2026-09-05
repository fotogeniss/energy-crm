<?php

/**
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Access;

use EnergyCRM\Domain\Access\LoginAttempts;
use PHPUnit\Framework\TestCase;

final class LoginAttemptsTest extends TestCase
{
    public function testTheLimitLocksOnTheAttemptThatReachesIt(): void
    {
        self::assertFalse(LoginAttempts::locked(7, 8));
        self::assertTrue(LoginAttempts::locked(8, 8));
        self::assertTrue(LoginAttempts::locked(9, 8));
    }

    /** Οριο μηδεν σημαινει «χωρις οριο», οχι «κλειδωμενο για παντα». */
    public function testAnAbsentLimitNeverLocks(): void
    {
        self::assertFalse(LoginAttempts::locked(1000, 0));
    }

    public function testAnExpiredWindowOwesNoTime(): void
    {
        self::assertSame(0, LoginAttempts::secondsLeft(100, 500));
        self::assertSame(400, LoginAttempts::secondsLeft(900, 500));
    }

    public function testABucketBelowItsLimitDoesNotMakeAnyoneWait(): void
    {
        $wait = LoginAttempts::waitSeconds([[7, LoginAttempts::PER_USER, 1000]], 500);

        self::assertSame(0, $wait);
    }

    /**
     * Οταν κλειδωνουν και οι δυο, μετραει ο μεγαλυτερος -- μια υποσχεση «σε 2
     * λεπτα» ενω ο αλλος μετρητης κρατα 10 ακομα, δεν τηρειται.
     */
    public function testTheLongerLockWins(): void
    {
        $wait = LoginAttempts::waitSeconds([
            [LoginAttempts::PER_USER, LoginAttempts::PER_USER, 620],
            [LoginAttempts::PER_IP, LoginAttempts::PER_IP, 1100],
        ], 500);

        self::assertSame(600, $wait);
    }

    /** Ενας κλειδωμενος μετρητης αρκει, ακομα κι αν ο αλλος ειναι καθαρος. */
    public function testOneLockedBucketIsEnough(): void
    {
        $wait = LoginAttempts::waitSeconds([
            [1, LoginAttempts::PER_USER, 900],
            [LoginAttempts::PER_IP, LoginAttempts::PER_IP, 800],
        ], 500);

        self::assertSame(300, $wait);
    }

    public function testNoBucketsMeansNothingToWaitFor(): void
    {
        self::assertSame(0, LoginAttempts::waitSeconds([], 500));
    }

    public function testTheWaitIsToldInWholeMinutesRoundedUp(): void
    {
        self::assertSame('σε λίγο', LoginAttempts::waitPhrase(0));
        self::assertSame('σε 1 λεπτό', LoginAttempts::waitPhrase(1));
        self::assertSame('σε 1 λεπτό', LoginAttempts::waitPhrase(60));
        self::assertSame('σε 2 λεπτά', LoginAttempts::waitPhrase(61));
        self::assertSame('σε 15 λεπτά', LoginAttempts::waitPhrase(LoginAttempts::WINDOW));
    }

    /**
     * Το ονομα χρηστη ειναι το στενο μονοπατι, η IP το πλατυ -- αν καποτε
     * αντιστραφει, το κλειδωμα ανα ονομα γινεται αχρηστο πισω απο NAT.
     */
    public function testTheUsernameLimitStaysStricterThanTheAddressOne(): void
    {
        self::assertLessThan(LoginAttempts::PER_IP, LoginAttempts::PER_USER);
    }
}
