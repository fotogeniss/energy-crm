<?php

/**
 * What the customer pays now against what they would pay on the offer.
 *
 * This is the number the agent puts in front of the customer, on paper, with
 * the company's logo above it. It is four multiplications, which is exactly why
 * it belongs in its own tested class rather than inline in a controller: an
 * arithmetic slip here is a promise the company cannot keep.
 *
 * Both sides are annual. A monthly standing charge is multiplied by twelve, not
 * added once — that was the mistake worth guarding against.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Quote;

final class SavingsEstimate
{
    private const MONTHS_PER_YEAR = 12;

    private function __construct(
        public readonly float $currentAnnual,
        public readonly float $offeredAnnual,
        public readonly float $savings,
        public readonly float $percentage,
    ) {
    }

    /**
     * @param float $consumption  Annual kWh.
     * @param float $currentUnit  €/kWh on the current supply.
     * @param float $currentFixed €/month standing charge on the current supply.
     * @param float $offeredUnit  €/kWh on the offer.
     * @param float $offeredFixed €/month standing charge on the offer.
     */
    public static function compare(
        float $consumption,
        float $currentUnit,
        float $currentFixed,
        float $offeredUnit,
        float $offeredFixed,
    ): self {
        // Negative inputs are a typo, never a real tariff, and a negative unit
        // price would invent savings out of nothing.
        $consumption  = max(0.0, $consumption);
        $currentUnit  = max(0.0, $currentUnit);
        $currentFixed = max(0.0, $currentFixed);
        $offeredUnit  = max(0.0, $offeredUnit);
        $offeredFixed = max(0.0, $offeredFixed);

        $current = $consumption * $currentUnit + self::MONTHS_PER_YEAR * $currentFixed;
        $offered = $consumption * $offeredUnit + self::MONTHS_PER_YEAR * $offeredFixed;
        $savings = $current - $offered;

        return new self(
            $current,
            $offered,
            $savings,
            // Against the current bill, so "20% cheaper" means what a customer
            // assumes it means. Zero when there is no current bill to beat —
            // any other answer would be a percentage of nothing.
            $current > 0.0 ? 100 * $savings / $current : 0.0,
        );
    }

    /** True when the offer costs the customer more than they pay today. */
    public function isWorseOff(): bool
    {
        return $this->savings < 0.0;
    }
}
