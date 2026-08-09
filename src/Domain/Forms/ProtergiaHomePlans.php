<?php

/**
 * The four residential Protergia tariffs and the form each one prints on.
 *
 * Protergia used to hand out one residential application with the tariff baked
 * into it (Picasso 2.0). It now hands out four, one per tariff, and the tariff
 * table on page 2 is pre-printed by Protergia — nothing the CRM fills in. So
 * "which tariff did the customer pick" stops being a detail inside one form and
 * becomes the question of *which form to print at all*.
 *
 * That is why this maps a plan to a template key rather than to prices the way
 * MobilePlans does: for mobile we print the price, here Protergia already did.
 * The prices are recorded anyway, because the dropdown an agent picks from is
 * built out of `programs` rows and an agent choosing between four names wants
 * to see what each one costs.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Forms;

final class ProtergiaHomePlans
{
    public const SURE_12 = 'protergia_oik_sure12';
    public const SURE_18 = 'protergia_oik_sure18';
    public const LITE_2  = 'protergia_oik_lite2';
    public const BRIGHT  = 'protergia_oik_bright';

    /**
     * plan code => [what the dropdown shows, the tariff type as the CRM names
     *               it, the monthly standing charge in €, the supply charge in
     *               €/kWh where the tariff has a fixed one].
     *
     * The plan code doubles as the template key: one tariff, one sheet, and a
     * second name for the same thing would only be a second thing to keep in
     * step. Figures read off the forms in this order: Sure 12 (9,90 €/μήνα,
     * 269 €/MWh), Sure 18 (11,90, 259), Lite 2.0 (0,00, formula), Bright
     * (5,00, formula). The two floating tariffs price off the day-ahead market
     * (1,21 × ΤΕΑ Αναφοράς + 55 or + 40 €/MWh), so they have no fixed rate to
     * record — a made-up number here would print nowhere but would show in the
     * dropdown as if it were real.
     *
     * @var array<string, array{
     *   label: string, priceType: string, fixedCharge: float, priceKwh: float|null
     * }>
     */
    private const PLANS = [
        self::SURE_12 => [
            'label'     => 'Protergia Οικιακό — Value Sure 12 Μήνες 3.0',
            'priceType' => 'fixed',
            'fixedCharge' => 9.90,
            'priceKwh'    => 0.269,
        ],
        self::SURE_18 => [
            'label'     => 'Protergia Οικιακό — Value Sure 18 Μήνες 3.0',
            'priceType' => 'fixed',
            'fixedCharge' => 11.90,
            'priceKwh'    => 0.259,
        ],
        self::LITE_2 => [
            'label'     => 'Protergia Οικιακό — Value Lite 2.0',
            'priceType' => 'variable',
            'fixedCharge' => 0.00,
            'priceKwh'    => null,
        ],
        self::BRIGHT => [
            'label'     => 'Protergia Οικιακό — Value Bright',
            'priceType' => 'variable',
            'fixedCharge' => 5.00,
            'priceKwh'    => null,
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

    public static function exists(string $plan): bool
    {
        return isset(self::PLANS[$plan]);
    }

    /**
     * The template key for a plan, or '' when the plan is not one of ours.
     *
     * Returning '' rather than a default is deliberate: a contract sold on a
     * tariff we have no sheet for must fall back to the caller's own choice,
     * not print on a sheet that names a different tariff than the one signed.
     */
    public static function templateKey(string $plan): string
    {
        return self::exists($plan) ? $plan : '';
    }

    /**
     * Every plan as a `programs` row, ready to seed.
     *
     * @return array<string, array{
     *   label: string, priceType: string, fixedCharge: float, priceKwh: float|null
     * }>
     */
    public static function all(): array
    {
        return self::PLANS;
    }
}
