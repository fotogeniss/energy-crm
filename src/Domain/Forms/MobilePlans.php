<?php

/**
 * The four Orizon plans and what each one costs.
 *
 * The prices are printed on the forms, in a table the agent cannot edit, so
 * they belong in code rather than in a database row somebody could change
 * without changing the paper. If Orizon reprints its forms, this file and the
 * background images change together.
 *
 * ## Why one plan has two prices
 *
 * The same plan is cheaper when it qualifies for a combined offer, and the two
 * routes to that discount — Συνδυαστική (mobile with mobile) and COMBO (mobile
 * with electricity) — give exactly the same figure. So the price is a function
 * of the plan *and* whether a combined offer applies, which is why it cannot
 * be a single column on the plan.
 *
 * Verified against the provider's own tables: the plain contract prints
 * 15/18/23/26, the Family and COMBO sheets both print 13/16/19/23.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Forms;

final class MobilePlans
{
    public const P_5GB       = 'orizon_5gb';
    public const P_10GB_5GB  = 'orizon_10gb_5gb';
    public const P_40GB      = 'orizon_40gb';
    public const P_UNLIMITED = 'orizon_unlimited';

    /**
     * plan => [label, tick field, list price, offer, offer with a combined
     *          deal, price after 24 months, the same after a combined deal,
     *          the fixed-line discount the contract prints].
     *
     * @var array<string, array{
     *   label: string, tick: string, list: int, offer: int, offerCombined: int,
     *   after: int, afterCombined: int, discount: int
     * }>
     */
    private const PLANS = [
        self::P_5GB => [
            'label' => 'orizon 5GB',           'tick' => 'programma_5gb',
            'list' => 20, 'offer' => 15, 'offerCombined' => 13,
            'after' => 20, 'afterCombined' => 18, 'discount' => 120,
        ],
        self::P_10GB_5GB => [
            'label' => 'orizon 10GB + 5GB',    'tick' => 'programma_10gb_5gb',
            'list' => 25, 'offer' => 18, 'offerCombined' => 16,
            'after' => 25, 'afterCombined' => 23, 'discount' => 168,
        ],
        self::P_40GB => [
            'label' => 'orizon 40GB',          'tick' => 'programma_40gb',
            'list' => 30, 'offer' => 23, 'offerCombined' => 19,
            'after' => 30, 'afterCombined' => 26, 'discount' => 168,
        ],
        self::P_UNLIMITED => [
            'label' => 'orizon unlimited',     'tick' => 'programma_unlimited',
            'list' => 35, 'offer' => 26, 'offerCombined' => 23,
            'after' => 35, 'afterCombined' => 32, 'discount' => 216,
        ],
    ];

    private function __construct()
    {
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::PLANS);
    }

    /**
     * What the dropdown shows, in the order the provider's table prints them.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_map(static fn (array $p): string => $p['label'], self::PLANS);
    }

    public static function exists(string $plan): bool
    {
        return isset(self::PLANS[$plan]);
    }

    public static function label(string $plan): string
    {
        return self::PLANS[$plan]['label'] ?? '';
    }

    /**
     * The tick field this plan's box lives under, for a template that only
     * prints "which one" and never the name/price -- orizon_combo's own
     * page 1 has no label field at all, only the four checkboxes (measured
     * against assets/forms/orizon_combo.json, Στάδιο 4, 05/09/2026). Empty
     * for an unknown plan, same "don't guess" rule as fillValues().
     */
    public static function tickField(string $plan): string
    {
        return self::PLANS[$plan]['tick'] ?? '';
    }

    /**
     * The values the forms print for this plan.
     *
     * @param bool $combined Whether a Συνδυαστική or COMBO offer applies.
     *
     * @return array<string, string> fill key => printed value
     */
    public static function fillValues(string $plan, bool $combined): array
    {
        if (! self::exists($plan)) {
            return [];
        }

        $p = self::PLANS[$plan];

        return [
            $p['tick']               => 'X',
            'onoma_programmatos'     => $p['label'],
            'arxiki_timi_pagiou'     => self::euro($p['list']),
            'timi_prosforas'         => self::euro($combined ? $p['offerCombined'] : $p['offer']),
            'pagio_meta_ti_prosfora' => self::euro($combined ? $p['afterCombined'] : $p['after']),
            'ekptosi_pagiou'         => self::euro($p['discount']),
        ];
    }

    /** The monthly price the customer actually pays for the first 24 months. */
    public static function monthlyPrice(string $plan, bool $combined): int
    {
        if (! self::exists($plan)) {
            return 0;
        }

        return $combined ? self::PLANS[$plan]['offerCombined'] : self::PLANS[$plan]['offer'];
    }

    /**
     * The published price table, keyed by plan code — for the screen to show
     * what will actually print, instead of leaving an editable box next to a
     * figure the paper form fixes in advance.
     *
     * @return array<string, array{
     *   list: string, offer: string, offerCombined: string, after: string, afterCombined: string
     * }>
     */
    public static function pricingTable(): array
    {
        return array_map(
            static fn (array $p): array => [
                'list'          => self::euro($p['list']),
                'offer'         => self::euro($p['offer']),
                'offerCombined' => self::euro($p['offerCombined']),
                'after'         => self::euro($p['after']),
                'afterCombined' => self::euro($p['afterCombined']),
            ],
            self::PLANS
        );
    }

    /** Written the way the form writes it, so the sheet reads consistently. */
    private static function euro(int $amount): string
    {
        return $amount . ' €';
    }
}
