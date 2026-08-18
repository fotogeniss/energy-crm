<?php

/**
 * Ο κωδικός που λέει ο χρήστης στο τηλέφωνο.
 *
 * Πρέπει να διαβάζεται με τη φωνή και να μη δείχνει ποτέ σε ξένο συμβάν: αν ο
 * ECRM-7F32 βρει άλλο σφάλμα από αυτό που είδε ο χρήστης, ψάχνεις λάθος πράγμα.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Infrastructure;

use EnergyCRM\Infrastructure\ErrorLog;
use PHPUnit\Framework\TestCase;

final class ErrorCodeTest extends TestCase
{
    public function testItLooksLikeSomethingYouCanReadOverThePhone(): void
    {
        self::assertMatchesRegularExpression('/^ECRM-[0-9A-F]{4,6}$/', ErrorLog::newCode([]));
    }

    /** Δεν ξαναδίνει κωδικό που ήδη κρατάμε. */
    public function testItNeverRepeatsOneAlreadyInUse(): void
    {
        $taken = [];

        for ($i = 0; $i < 200; $i++) {
            $code = ErrorLog::newCode($taken);

            self::assertNotContains($code, $taken);

            $taken[] = $code;
        }
    }
}
