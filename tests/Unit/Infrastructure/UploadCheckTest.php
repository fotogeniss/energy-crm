<?php

/**
 * Τι δέχεται και τι όχι το ανέβασμα εγγράφων.
 *
 * Μέχρι τις 2026-08-18 ο τύπος του αρχείου ήταν ό,τι δήλωνε ο browser. Ένα
 * `curl -F 'files[]=@shell.php;type=image/jpeg'` περνούσε τον έλεγχο, και η
 * κατάληξη έβγαινε από το όνομα που έστελνε ο ίδιος. Αυτά τα tests κρατούν
 * την απόφαση εκεί που ανήκει: στα bytes.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Infrastructure;

use EnergyCRM\Infrastructure\UploadCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UploadCheckTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function realFiles(): array
    {
        return [
            'JPEG'      => ["\xFF\xD8\xFF\xE0" . str_repeat("\x00", 12), 'image/jpeg'],
            'PNG'       => ["\x89PNG\r\n\x1A\n" . str_repeat("\x00", 8), 'image/png'],
            'PDF'       => ['%PDF-1.7' . str_repeat(' ', 8), 'application/pdf'],
            'WEBP'      => ['RIFF' . "\x24\x00\x00\x00" . 'WEBPVP8 ', 'image/webp'],
            'HEIC'      => ["\x00\x00\x00\x18" . 'ftyp' . 'heic' . "\x00\x00\x00\x00", 'image/heic'],
        ];
    }

    #[DataProvider('realFiles')]
    public function testItReadsTheTypeFromTheBytes(string $head, string $expected): void
    {
        self::assertSame($expected, UploadCheck::sniff($head));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function impostors(): array
    {
        return [
            'PHP με όνομα εικόνας'  => ["<?php @eval(\$_POST['x']); ?>" . str_repeat(' ', 8)],
            'SVG (φέρνει script)'   => ['<svg xmlns="http://www.w3.org/2000/svg"><script/></svg>'],
            'HTML'                  => ['<!DOCTYPE html><html><body>γεια</body></html>'],
            'ZIP μεταμφιεσμένο'     => ["PK\x03\x04" . str_repeat("\x00", 12)],
            'WAV, όχι WEBP'         => ['RIFF' . "\x24\x00\x00\x00" . 'WAVEfmt '],
            'MP4, όχι HEIC'         => ["\x00\x00\x00\x18" . 'ftyp' . 'isom' . "\x00\x00\x00\x00"],
            'άδειο'                 => [''],
            'μισή υπογραφή JPEG'    => ["\xFF\xD8"],
        ];
    }

    #[DataProvider('impostors')]
    public function testItRefusesWhatItCannotRecognise(string $head): void
    {
        self::assertSame('', UploadCheck::sniff($head));
    }

    /** Η κατάληξη βγαίνει από τον τύπο, ποτέ από το όνομα του πελάτη. */
    public function testTheExtensionComesFromTheVerifiedType(): void
    {
        self::assertSame('jpg', UploadCheck::extensionFor('image/jpeg'));
        self::assertSame('pdf', UploadCheck::extensionFor('application/pdf'));
        self::assertSame('jpg', UploadCheck::extensionFor('IMAGE/JPEG'));
    }

    /** Ό,τι δεν ξέρουμε δεν παίρνει εκτελέσιμη κατάληξη. */
    public function testAnUnknownTypeLandsAsBin(): void
    {
        self::assertSame('bin', UploadCheck::extensionFor('application/x-httpd-php'));
        self::assertSame('bin', UploadCheck::extensionFor(''));
        self::assertFalse(UploadCheck::knows('image/svg+xml'));
    }

    public function testItRejectsTheEmptyAndTheEnormous(): void
    {
        self::assertSame('empty', UploadCheck::sizeProblem(0));
        self::assertSame('empty', UploadCheck::sizeProblem(UploadCheck::MIN_BYTES - 1));
        self::assertSame('', UploadCheck::sizeProblem(UploadCheck::MIN_BYTES));
        self::assertSame('', UploadCheck::sizeProblem(UploadCheck::MAX_BYTES));
        self::assertSame('too_large', UploadCheck::sizeProblem(UploadCheck::MAX_BYTES + 1));
    }

    /**
     * Τα HEAD_BYTES πρέπει να φτάνουν για κάθε τύπο που αναγνωρίζουμε —
     * αλλιώς το store() διαβάζει λίγα και απορρίπτει σωστά αρχεία.
     */
    #[DataProvider('realFiles')]
    public function testTheHeadWeReadIsLongEnough(string $head, string $expected): void
    {
        self::assertSame($expected, UploadCheck::sniff(substr($head, 0, UploadCheck::HEAD_BYTES)));
    }
}
