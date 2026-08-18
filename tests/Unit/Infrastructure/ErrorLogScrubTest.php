<?php

/**
 * Ό,τι μοιάζει με προσωπικό δεδομένο δεν φτάνει στην οθόνη σφαλμάτων.
 *
 * Η οθόνη «Υγεία» υπάρχει για να αντιγράφεται και να στέλνεται. Αν ένα μήνυμα
 * εξαίρεσης κουβαλά ΑΦΜ, το εργαλείο υποστήριξης γίνεται τρόπος διαρροής.
 *
 * Δεν είναι απόδειξη ότι δεν περνά τίποτα — δεν ξέρουμε τι γράφει κάθε
 * βιβλιοθήκη στα μηνύματά της. Είναι ό,τι μπορεί να γίνει, δηλωμένο.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Infrastructure;

use EnergyCRM\Infrastructure\ErrorLog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ErrorLogScrubTest extends TestCase
{
    #[DataProvider('sensitive')]
    public function testSensitiveValuesAreReplaced(string $message, string $mustNotContain): void
    {
        self::assertStringNotContainsString($mustNotContain, ErrorLog::scrub($message));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function sensitive(): array
    {
        return [
            'ΑΦΜ'             => ['Ο πελάτης 123456789 δεν βρέθηκε', '123456789'],
            'κινητό'          => ['Αποτυχία SMS προς 6912345678', '6912345678'],
            'κινητό με χώρα'  => ['Αποτυχία SMS προς +306912345678', '306912345678'],
            'email'           => ['Άκυρο email: kostas@example.gr', 'kostas@example.gr'],
            'ΑΔΤ ελληνικό'    => ['Ταυτότητα ΑΒ123456 απορρίφθηκε', 'ΑΒ123456'],
            'ΑΔΤ λατινικό'    => ['Ταυτότητα AB123456 απορρίφθηκε', 'AB123456'],
            'IBAN'            => ['Λάθος GR1601101250000000012300695', 'GR1601101250000000012300695'],
            'κρυπτογράφημα'   => ['Δεν άνοιξε το ecrm1:YWJjZGVmZ2hpamts', 'ecrm1:YWJjZGVmZ2hpamts'],
        ];
    }

    /** Το χρήσιμο κείμενο μένει, αλλιώς το μήνυμα δεν λέει τίποτα. */
    public function testTheActualErrorSurvives(): void
    {
        $scrubbed = ErrorLog::scrub('Call to a member function fromStorage() on null');

        self::assertSame('Call to a member function fromStorage() on null', $scrubbed);
    }

    /** Κωδικοί σφαλμάτων και γραμμές δεν είναι ΑΦΜ. */
    public function testShortNumbersAreLeftAlone(): void
    {
        $scrubbed = ErrorLog::scrub('HTTP 500 στη γραμμή 142 του αρχείου, μετά από 3 προσπάθειες');

        self::assertStringContainsString('500', $scrubbed);
        self::assertStringContainsString('142', $scrubbed);
    }

    public function testLongMessagesAreTruncated(): void
    {
        self::assertLessThanOrEqual(500, mb_strlen(ErrorLog::scrub(str_repeat('χ', 900))));
    }
}
