<?php

/**
 * (335) «Ανανέωση με πρόταση».
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Pricing;

use EnergyCRM\Domain\Pricing\RenewalOffer;
use PHPUnit\Framework\TestCase;

final class RenewalOfferTest extends TestCase
{
    public function testACheaperProgramIsOfferedWithTheSaving(): void
    {
        $current = $this->program(1, '0.165', 'variable');
        $out     = RenewalOffer::for($current, $this->programs(), 3770, 'power', 'home', '2026-10-30');

        self::assertSame('cheaper', $out['state']);
        self::assertSame(2, $out['best']['id']);
        self::assertSame(round((0.165 - 0.13) * 3770, 2), $out['saving']);
    }

    public function testTheCurrentProgramIsAlreadyTheCheapest(): void
    {
        $out = RenewalOffer::for($this->program(2, '0.130'), $this->programs(), 3770, 'power', 'home', '2026-10-30');

        self::assertSame('best', $out['state']);
    }

    public function testAStalePriceIsNeverOffered(): void
    {
        $stale = [$this->program(9, '0.010', 'variable', '2026-01-01')];

        self::assertSame('none', RenewalOffer::for(null, $stale, 3770, 'power', 'home', '2026-10-30')['state']);
    }

    public function testUnknownCurrentPriceStillOffersButWithoutSaving(): void
    {
        $current = $this->program(1, null, 'variable');
        $out     = RenewalOffer::for($current, $this->programs(), 3770, 'power', 'home', '2026-10-30');

        self::assertSame('cheaper', $out['state']);
        self::assertNull($out['saving']);
    }

    public function testTheConsumptionComesFromTheExtras(): void
    {
        self::assertSame(3770.0, RenewalOffer::kwhFrom('{"annual_kwh":"3770","guarantee":"60"}'));
        self::assertNull(RenewalOffer::kwhFrom('{"guarantee":"60"}'));
        self::assertNull(RenewalOffer::kwhFrom(null));
        self::assertSame('none', RenewalOffer::for(null, $this->programs(), 0, 'power', 'home', '2026-10-30')['state']);
    }

    /** @return list<array<string, mixed>> */
    private function programs(): array
    {
        return [$this->program(1, '0.165', 'variable'), $this->program(2, '0.130')];
    }

    /** @return array<string, mixed> */
    private function program(int $id, ?string $price, string $type = 'fixed', string $updated = '2026-10-20'): array
    {
        return [
            'id' => $id, 'provider_id' => 1, 'name' => 'P' . $id, 'energy_type' => 'power', 'category' => 'home',
            'price_type' => $type, 'price_kwh' => $price, 'fixed_charge' => '5', 'pricing' => null,
            'price_updated' => $updated,
        ];
    }
}
