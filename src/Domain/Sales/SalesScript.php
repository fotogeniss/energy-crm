<?php

/**
 * (331) «Τι λέω στον πελάτη»: 3-5 γραμμές για το πρόγραμμα που διάλεξε ο πωλητής.
 *
 * Τι πληρώνει, τι κερδίζει, τι να προσέξει. Βγαίνουν μόνο από τα στοιχεία
 * του προγράμματος (τιμή, πάγιο, κανόνας, ρήτρα αποχώρησης) και της αίτησης
 * (τρόπος πληρωμής, εγγύηση, εξοικονόμηση από την πρόταση). ΔΕΝ τα γράφει
 * AI τη στιγμή της πώλησης: ό,τι λέει ο πωλητής πρέπει να είναι πάντα το ίδιο
 * και πάντα αυτό που γράφει η αίτηση. Mockup: docs/UI-SALES-SCRIPT.html.
 *
 * Κείμενο με `**έντονα**`· το HTML το φτιάχνει η οθόνη αφού κάνει escape.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Sales;

use EnergyCRM\Domain\Pricing\ProgramPricing;

final class SalesScript
{
    private const TYPE_LABEL = [
        'fixed'    => 'Σταθερό',
        'variable' => 'Κυμαινόμενο',
        'dynamic'  => 'Δυναμικό',
        'special'  => 'Ειδικό',
    ];

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $program
     * @param array{payment_method?: string, guarantee?: float|null, saving?: float|null} $context
     * @return array{label: string, lines: list<array{tone: string, text: string}>, missing_price: bool}
     */
    public static function for(array $program, array $context = []): array
    {
        $rule  = ProgramPricing::rule($program);
        $type  = (string) ($program['price_type'] ?? '');
        $lines = [];
        $fees  = self::fees($program['exit_fees'] ?? null);

        $label = self::TYPE_LABEL[$type] ?? '';

        if ($rule['kind'] === 'tiered') {
            $label = trim($label . ' κλιμακωτό');
        } elseif ($rule['kind'] === 'package') {
            $label = 'Πακέτο';
        }

        if ($fees !== [] || $type === 'fixed') {
            $label .= ' · 12 μήνες';
        }

        foreach (self::priceLines($rule, $type) as $line) {
            $lines[] = $line;
        }

        $fixed = $rule['fixed']
            ?? (is_numeric($program['fixed_charge'] ?? null) ? (float) $program['fixed_charge'] : null);

        if ($rule['kind'] !== 'package' && $fixed !== null) {
            $lines[] = self::ok($fixed > 0 ? 'Πάγιο **' . self::eur($fixed) . ' €/μήνα**.' : '**Χωρίς πάγιο**.');
        }

        if (isset($rule['note']) && str_contains((string) $rule['note'], 'έκπτωση συνέπειας')) {
            $without = preg_match('/χωρίς: ([\d,]+)/u', (string) $rule['note'], $m) === 1 ? $m[1] : '';
            $lines[] = self::warn(
                'Η τιμή ισχύει **με εμπρόθεσμη εξόφληση**'
                . ($without !== '' ? '· αν αργήσει, ο λογαριασμός βγαίνει με ' . $without . ' €/kWh.' : '.')
            );
        }

        $saving = $context['saving'] ?? null;

        if ($saving !== null && $saving > 0) {
            $lines[] = self::ok(
                'Με τον λογαριασμό που μου έδειξες: **γλιτώνεις περίπου '
                . self::eur($saving, 0) . ' € τον χρόνο**.'
            );
        }

        if ($fees !== []) {
            $lines[] = self::warn(self::exitLine($fees));
        }

        $payment   = (string) ($context['payment_method'] ?? '');
        $guarantee = $context['guarantee'] ?? null;

        if ($payment === 'standing_order') {
            $lines[] = self::ok('Με πάγια εντολή η εγγύηση είναι **0 €**.');
        } elseif ($guarantee !== null && $guarantee > 0) {
            $lines[] = self::warn(
                'Εγγύηση **' . self::eur($guarantee, 0) . ' €** με τον πρώτο λογαριασμό· με πάγια εντολή 0 €.'
            );
        }

        return [
            'label'         => $label,
            'lines'         => $lines,
            'missing_price' => $rule['kind'] === 'none' && in_array($type, ['variable', 'dynamic', 'special'], true),
        ];
    }

    /**
     * Το ίδιο, ως απλό κείμενο για Viber / SMS.
     *
     * @param list<array{tone: string, text: string}> $lines
     */
    public static function plain(string $programName, array $lines): string
    {
        $out = [$programName];

        foreach ($lines as $line) {
            $out[] = '• ' . str_replace('**', '', $line['text']);
        }

        return implode("\n", $out);
    }

    /**
     * @param array<string, mixed> $rule
     * @return list<array{tone: string, text: string}>
     */
    private static function priceLines(array $rule, string $type): array
    {
        if ($rule['kind'] === 'tiered') {
            /** @var list<array{0: float|null, 1: float}> $tiers */
            $tiers = $rule['tiers'];
            $first = $tiers[0];
            $rest  = $tiers[count($tiers) - 1];

            return [self::ok(
                '**Οι πρώτες ' . (int) $first[0] . ' kWh κάθε μήνα στα ' . self::kwh($first[1])
                . ' €/kWh**, οι υπόλοιπες στα '
                . self::kwh($rest[1]) . ': συμφέρει όποιον καταναλώνει λίγο.'
            )];
        }

        if ($rule['kind'] === 'package') {
            return [
                self::ok(
                    '**Σταθερό ποσό κάθε μήνα** για έως **' . number_format((float) $rule['package_kwh'], 0, ',', '.')
                    . ' kWh τον χρόνο**: ξέρει από πριν τι θα πληρώσει.'
                ),
                self::warn(
                    'Πάνω από το πακέτο, κάθε επιπλέον kWh χρεώνεται '
                    . self::kwh((float) $rule['excess_kwh']) . ' € (προμήθεια).'
                ),
            ];
        }

        if ($type === 'variable' || $type === 'dynamic' || $type === 'special') {
            $lines = [self::warn(
                '**Η τιμή αλλάζει κάθε μήνα** με την αγορά: πες το καθαρά, '
                . 'για να μη γίνει παράπονο στον πρώτο λογαριασμό.'
            )];

            if ($rule['kind'] === 'flat') {
                $lines[] = self::ok('Τρέχουσα τιμή περίπου **' . self::kwh((float) $rule['price_kwh']) . ' €/kWh**.');
            }

            return $lines;
        }

        if ($rule['kind'] === 'flat') {
            return [self::ok(
                '**Σταθερή τιμή ' . self::kwh((float) $rule['price_kwh']) . ' €/kWh για 12 μήνες**: '
                . 'δεν αλλάζει όσο κι αν ανέβει η χονδρική.'
            )];
        }

        return [];
    }

    /**
     * @param list<int> $fees
     */
    private static function exitLine(array $fees): string
    {
        $first = $fees[0];
        $same  = 1;

        while ($same < count($fees) && $fees[$same] === $first) {
            $same++;
        }

        $months = $same === 1 ? 'τον 1ο μήνα' : 'τους ' . $same . ' πρώτους μήνες';

        return 'Αν φύγει νωρίτερα: **' . $first . ' € ' . $months . '**, λιγότερο μετά, '
            . end($fees) . ' € τον ' . count($fees) . 'ο.';
    }

    /**
     * @return list<int>
     */
    private static function fees(mixed $json): array
    {
        $list = is_string($json) && $json !== '' ? json_decode($json, true) : (is_array($json) ? $json : null);

        if (! is_array($list) || $list === []) {
            return [];
        }

        $out = [];

        foreach ($list as $fee) {
            if (! is_numeric($fee)) {
                return [];
            }

            $out[] = (int) round((float) $fee);
        }

        return max($out) > 0 ? $out : [];
    }

    /** @return array{tone: string, text: string} */
    private static function ok(string $text): array
    {
        return ['tone' => 'ok', 'text' => $text];
    }

    /** @return array{tone: string, text: string} */
    private static function warn(string $text): array
    {
        return ['tone' => 'warn', 'text' => $text];
    }

    /** 0,177 · 0,1999 · 0,139 -- τρία δεκαδικά τουλάχιστον, τέσσερα όταν χρειάζονται. */
    private static function kwh(float $value): string
    {
        $text = number_format($value, 4, ',', '');

        return str_ends_with($text, '0') ? substr($text, 0, -1) : $text;
    }

    private static function eur(float $value, int $decimals = 2): string
    {
        return number_format($value, $decimals, ',', '.');
    }
}
