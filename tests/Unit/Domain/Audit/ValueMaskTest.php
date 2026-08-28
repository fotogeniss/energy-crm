<?php

/**
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Audit;

use EnergyCRM\Domain\Audit\ValueMask;
use PHPUnit\Framework\TestCase;

final class ValueMaskTest extends TestCase
{
    public function testAfmKeepsOnlyTheLastThreeDigits(): void
    {
        self::assertSame('••••••373', ValueMask::apply('afm', '090003373'));
    }

    public function testAdtKeepsOnlyTheLastThreeCharacters(): void
    {
        self::assertSame('•••••111', ValueMask::apply('adt', 'ΑΚ111111'));
    }

    public function testPhoneKeepsOnlyTheLastThreeDigits(): void
    {
        self::assertSame('•••••••567', ValueMask::apply('phone', '2101234567'));
    }

    /**
     * Το ΤΚ είναι η εξαίρεση: κρατά τους ΠΡΩΤΟΥΣ τρεις, όχι τους τελευταίους.
     *
     * Στο ελληνικό ΤΚ τα πρώτα τρία ψηφία είναι η περιοχή -- χρήσιμη σε ένα
     * ιστορικό, ακίνδυνη. Τα δύο τελευταία είναι το τετράγωνο, το κομμάτι
     * που εντοπίζει σπίτι. Ο κανόνας του ΑΦΜ εφαρμοσμένος εδώ θα έκρυβε
     * ακριβώς το λάθος μισό.
     */
    public function testPostalCodeKeepsTheFirstThreeDigitsNotTheLast(): void
    {
        self::assertSame('106••', ValueMask::apply('postal_code', '10671'));
    }

    /**
     * Οδός και αριθμός δεν χωράνε μερική μάσκα -- κάθε τμήμα τους είτε λέει
     * πολλά (η κατάληξη μιας λέξης) είτε τίποτα (ένας αριθμός 1-3 ψηφίων).
     * Η ειλικρινής απάντηση είναι ότι άλλαξε, όχι μια ψευδαίσθηση απόκρυψης.
     */
    public function testStreetAndStreetNumberShowNoValueAtAll(): void
    {
        self::assertSame(ValueMask::CHANGED, ValueMask::apply('street', 'Ακαδημίας'));
        self::assertSame(ValueMask::CHANGED, ValueMask::apply('street_no', '42'));
        self::assertTrue(ValueMask::isOpaque('street'));
        self::assertTrue(ValueMask::isOpaque('street_no'));
    }

    /**
     * Δεν είναι κρυπτογραφημένη στήλη -- η μάσκα δεν έχει λόγο να την αγγίξει.
     * Μια μάσκα εδώ θα έκρυβε από τον πωλητή κάτι που μια απλή SELECT στη
     * βάση δίνει ούτως ή άλλως. Θόρυβος χωρίς κέρδος.
     */
    public function testAFieldOutsideTheListPassesThroughUnchanged(): void
    {
        self::assertSame('6941234567', ValueMask::apply('mobile', '6941234567'));
        self::assertSame('Παπαδόπουλος', ValueMask::apply('last_name', 'Παπαδόπουλος'));
        self::assertFalse(ValueMask::isOpaque('mobile'));
    }

    /**
     * Το κενό μένει κενό. Μια μάσκα πάνω στο τίποτα θα ισχυριζόταν τιμή που
     * δεν υπάρχει -- ο καλών (`ECRM_Audit::v()`) μετατρέπει το κενό σε «∅».
     */
    public function testAnEmptyValueStaysEmpty(): void
    {
        self::assertSame('', ValueMask::apply('afm', ''));
        self::assertSame('', ValueMask::apply('afm', '   '));
    }

    /**
     * Τιμή μέχρι KEEP χαρακτήρες καλύπτεται ΟΛΟΚΛΗΡΗ. «Κράτα τους τρεις
     * τελευταίους» ενός διψήφιου δεν είναι μάσκα -- είναι η ίδια η τιμή.
     */
    public function testAValueNoLongerThanKeepIsFullyMasked(): void
    {
        self::assertSame('••', ValueMask::apply('afm', '12'));
        self::assertSame('•••', ValueMask::apply('afm', '123'));
    }

    /**
     * Πολυβυτικοί χαρακτήρες (ελληνικά) μετριούνται σωστά, όχι byte-ανά-byte.
     *
     * Ένα `substr()` αντί για `mb_substr()` θα έκοβε μέσα σε πολυβυτικό
     * χαρακτήρα, δίνοντας κατεστραμμένα bytes αντί για τους τρεις σωστούς
     * χαρακτήρες. «ΑΒΓΔΕ» έχει 5 χαρακτήρες αλλά 10 bytes σε UTF-8.
     */
    public function testMultibyteCharactersCountAsOneEachNotAsBytes(): void
    {
        self::assertSame('••ΓΔΕ', ValueMask::apply('adt', 'ΑΒΓΔΕ'));
    }

    public function testFieldsListsExactlyTheSixEncryptedColumns(): void
    {
        self::assertSame(
            ['afm', 'adt', 'phone', 'postal_code', 'street', 'street_no'],
            ValueMask::fields()
        );
    }
}
