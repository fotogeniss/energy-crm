<?php

/**
 * (331) «Τι λέω στον πελάτη» -- οι γραμμές του mockup UI-SALES-SCRIPT.html.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Sales;

use EnergyCRM\Domain\Pricing\ProtergiaPrices2026;
use EnergyCRM\Domain\Sales\SalesScript;
use PHPUnit\Framework\TestCase;

final class SalesScriptTest extends TestCase
{
    public function testFixedWithConsistencyDiscount(): void
    {
        $out  = SalesScript::for($this->protergia('protergia_value_sure4_12m', 'fixed'), [
            'payment_method' => 'manual', 'guarantee' => 60.0, 'saving' => 121.6,
        ]);
        $text = $this->text($out['lines']);

        self::assertSame('Σταθερό · 12 μήνες', $out['label']);
        self::assertStringContainsString('Σταθερή τιμή 0,177 €/kWh για 12 μήνες', $text);
        self::assertStringContainsString('11,90 €/μήνα', $text);
        self::assertStringContainsString('0,247 €/kWh', $text);
        self::assertStringContainsString('γλιτώνεις περίπου 122 €', $text);
        self::assertStringContainsString('180 € τους 3 πρώτους μήνες', $text);
        self::assertStringContainsString('Εγγύηση **60 €**', $text);
        self::assertFalse($out['missing_price']);
    }

    public function testTieredExplainsTheFirstBlock(): void
    {
        $program = $this->protergia('protergia_value_balance_12m', 'fixed');
        $out     = SalesScript::for($program, ['payment_method' => 'standing_order']);
        $text = $this->text($out['lines']);

        self::assertSame('Σταθερό κλιμακωτό · 12 μήνες', $out['label']);
        self::assertStringContainsString('Οι πρώτες 150 kWh κάθε μήνα στα 0,139 €/kWh', $text);
        self::assertStringContainsString('οι υπόλοιπες στα 0,219', $text);
        self::assertStringContainsString('10,90 €/μήνα', $text);
        self::assertStringContainsString('εγγύηση είναι **0 €**', $text);
    }

    public function testPackageSaysTheMonthlyAmountIsKnownInAdvance(): void
    {
        $out  = SalesScript::for($this->protergia('protergia_pic_h_s1', 'fixed'));
        $text = $this->text($out['lines']);

        self::assertSame('Πακέτο · 12 μήνες', $out['label']);
        self::assertStringContainsString('1.325 kWh τον χρόνο', $text);
        self::assertStringContainsString('120 € τον 1ο μήνα', $text);
        self::assertStringNotContainsString('Πάγιο', $text);
    }

    public function testVariableWithoutPriceWarnsAndFlagsTheMissingPrice(): void
    {
        $out = SalesScript::for([
            'name' => 'Value Standard 2.0', 'price_type' => 'variable', 'price_kwh' => null, 'fixed_charge' => '5.00',
        ]);

        self::assertSame('Κυμαινόμενο', $out['label']);
        self::assertStringContainsString('Η τιμή αλλάζει κάθε μήνα', $this->text($out['lines']));
        self::assertStringContainsString('5,00 €/μήνα', $this->text($out['lines']));
        self::assertTrue($out['missing_price']);
    }

    public function testPlainTextDropsTheMarkup(): void
    {
        $plain = SalesScript::plain('Πρόγραμμα', [['tone' => 'ok', 'text' => 'Πάγιο **5 €**.']]);

        self::assertSame("Πρόγραμμα\n• Πάγιο 5 €.", $plain);
    }

    /** @return array<string, mixed> */
    private function protergia(string $code, string $type): array
    {
        $price = ProtergiaPrices2026::all()[$code];

        return [
            'name'         => $code,
            'price_type'   => $type,
            'price_kwh'    => $price['price_kwh'],
            'fixed_charge' => $price['fixed_charge'],
            'pricing'      => $price['pricing'] === null ? null : json_encode($price['pricing']),
            'exit_fees'    => json_encode(ProtergiaPrices2026::exitFees()[$code]),
        ];
    }

    /** @param list<array{tone: string, text: string}> $lines */
    private function text(array $lines): string
    {
        return implode("\n", array_column($lines, 'text'));
    }
}
