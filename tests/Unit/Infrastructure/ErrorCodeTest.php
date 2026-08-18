<?php

/**
 * Ο κωδικός αναφοράς που συνδέει το «δεν δούλεψε» με τη γραμμή που έσπασε.
 *
 * Ο χρήστης βλέπει ECRM-7F32 και τίποτα άλλο. Αν ο κωδικός δεν είναι μοναδικός
 * μέσα σε όσα κρατάμε, τον στέλνει σε λάθος συμβάν — που είναι χειρότερο από το
 * να μην υπάρχει κωδικός καθόλου.
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
        self::assertMatchesRegularExpression('/^ECRM-[0-9A-F]{4}$/', ErrorLog::newCode([]));
    }

    public function testItAvoidsCodesAlreadyInUse(): void
    {
        // Όλος ο χώρος πιασμένος εκτός από έναν.
        $taken = [];

        for ($i = 0; $i <= 0xFFFF; $i++) {
            $taken[] = sprintf('ECRM-%04X', $i);
        }

        array_splice($taken, 0x1234, 1);

        self::assertSame('ECRM-1234', ErrorLog::newCode($taken));
    }

    /**
     * Με τον χώρο εξαντλημένο δεν επιστρέφει διπλό: πάει σε μακρύτερο.
     *
     * Δεν συμβαίνει με 50 εγγραφές. Είναι εδώ επειδή η εναλλακτική — να
     * επιστρέψει κωδικό που ήδη δείχνει αλλού — είναι σιωπηλή και επιβλαβής.
     */
    public function testItNeverReturnsADuplicate(): void
    {
        $taken = [];

        for ($i = 0; $i <= 0xFFFF; $i++) {
            $taken[] = sprintf('ECRM-%04X', $i);
        }

        $code = ErrorLog::newCode($taken);

        self::assertNotContains($code, $taken);
        self::assertMatchesRegularExpression('/^ECRM-[0-9A-F]{6}$/', $code);
    }

    public function testDifferentCallsGiveDifferentCodes(): void
    {
        $codes = [];

        for ($i = 0; $i < 200; $i++) {
            $codes[] = ErrorLog::newCode($codes);
        }

        self::assertCount(200, array_unique($codes));
    }
}
