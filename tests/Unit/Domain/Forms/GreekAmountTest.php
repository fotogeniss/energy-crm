<?php

/**
 * (424) Η εγγύηση ολογράφως στην «Αίτηση/Σύμβαση» της ΔΕΗ. Ό,τι δεν
 * διαβάζεται ως ποσό μένει κενό, ποτέ λάθος ποσό στο χαρτί.
 *
 * @kanonas K7
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Forms;

use EnergyCRM\Domain\Forms\GreekAmount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GreekAmountTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function amounts(): iterable
    {
        yield '120'       => ['120', 'Εκατόν είκοσι ευρώ'];
        yield '100'       => ['100', 'Εκατό ευρώ'];
        yield '101'       => ['101', 'Εκατόν ένα ευρώ'];
        yield '300 €'     => ['300 €', 'Τριακόσια ευρώ'];
        yield '120,50'    => ['120,50', 'Εκατόν είκοσι ευρώ και πενήντα λεπτά'];
        yield '1.200,00'  => ['1.200,00', 'Χίλια διακόσια ευρώ'];
        yield '3500'      => ['3500', 'Τρεις χιλιάδες πεντακόσια ευρώ'];
        yield '14000'     => ['14000', 'Δεκατέσσερις χιλιάδες ευρώ'];
        yield '200000'    => ['200000', 'Διακόσιες χιλιάδες ευρώ'];
        yield '0'         => ['0', 'Μηδέν ευρώ'];
        yield 'κείμενο'   => ['θα δοθεί', ''];
        yield 'κενό'      => ['', ''];
        yield 'τεράστιο'  => ['12345678', ''];
    }

    #[DataProvider('amounts')]
    public function testWords(string $typed, string $expected): void
    {
        self::assertSame($expected, GreekAmount::words($typed));
    }

    public function testWithoutTheCurrencyWhenTheFormPrintsIt(): void
    {
        self::assertSame('Εκατόν είκοσι', GreekAmount::words('120', false));
        // Με λεπτά, ολόκληρο: αλλιώς θα διαβαζόταν «… πενήντα λεπτά ευρώ».
        self::assertSame('Εκατόν είκοσι ευρώ και πενήντα λεπτά', GreekAmount::words('120,50', false));
    }

    public function testTheFigureAndItsParts(): void
    {
        self::assertSame('120,00', GreekAmount::figure('120'));
        self::assertSame('1.200,50', GreekAmount::figure('1200.50'));
        self::assertSame('120', GreekAmount::euros('120,5'));
        self::assertSame('50', GreekAmount::centsPart('120,5'));
        self::assertSame('00', GreekAmount::centsPart('120'));
        self::assertSame('', GreekAmount::figure('—'));
    }
}
