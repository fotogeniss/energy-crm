<?php

/**
 * (344) Το κείμενο της «Κλήσης ανανέωσης».
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Contract;

use EnergyCRM\Domain\Contract\RenewalCall;
use PHPUnit\Framework\TestCase;

final class RenewalCallTest extends TestCase
{
    public function testTheTitleNamesTheCustomerAndTheDay(): void
    {
        self::assertSame(
            'Κλήση ανανέωσης: Παπαδόπουλος Γ. · λήγει 30/11',
            RenewalCall::title('Παπαδόπουλος Γ.', 'EC-0412', '2026-11-30')
        );
    }

    /** Χωρίς όνομα πελάτη, ο κωδικός: κάτι να ψάξει ο πωλητής. */
    public function testWithoutACustomerTheCodeIsUsed(): void
    {
        self::assertSame('Κλήση ανανέωσης: EC-0412 · λήγει 30/11', RenewalCall::title('  ', 'EC-0412', '2026-11-30'));
    }

    public function testACheaperOfferIsSpelledOut(): void
    {
        $offer = ['state' => 'cheaper', 'best' => ['id' => 9, 'name' => 'ΗΡΩΝ Basic'], 'saving' => 84.4];

        self::assertSame(
            'Τώρα: Protergia Home Fix · Πρόταση: ΗΡΩΝ Basic, −€84/έτος',
            RenewalCall::note('Protergia Home Fix', $offer)
        );
    }

    public function testThousandsUseAGreekSeparator(): void
    {
        $offer = ['state' => 'cheaper', 'best' => ['id' => 9, 'name' => 'Χ'], 'saving' => 1234.0];

        self::assertStringContainsString('−€1.234/έτος', RenewalCall::note('', $offer));
    }

    public function testAlreadyTheCheapest(): void
    {
        self::assertStringContainsString('ήδη το φθηνότερο', RenewalCall::note('Χ', ['state' => 'best']));
    }

    /** Χωρίς κατανάλωση δεν υπάρχει υπολογισμός· ο πωλητής ξέρει πού να πάει. */
    public function testWithoutConsumptionItPointsToRenewals(): void
    {
        self::assertSame('Χωρίς πρόταση: λείπει η κατανάλωση — δες Λήξεις', RenewalCall::note('', null));
    }

    public function testTheLastWeekIsUrgent(): void
    {
        self::assertSame('high', RenewalCall::priority(7));
        self::assertSame('normal', RenewalCall::priority(8));
    }
}
