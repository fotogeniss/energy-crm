<?php

/**
 * (336) Ποια κυμαινόμενα θέλουν νέα τιμή, και πότε να το πει το καμπανάκι.
 *
 * Η «Πρόταση από λογαριασμό» (328) και η «Ανανέωση με πρόταση» (335)
 * συγκρίνουν ό,τι τιμή γράφει η σελίδα Παρόχων. Τα κυμαινόμενα αλλάζουν κάθε
 * μήνα· τιμή παλιότερη από 45 μέρες -- ή καμία -- σημαίνει σύγκριση που
 * παλιώνει αθόρυβα. Ο διαχειριστής ειδοποιείται, όχι κάθε μέρα: μία φορά
 * την εβδομάδα, ή αμέσως αν μεγάλωσε η λίστα.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Pricing;

final class StalePrices
{
    public const EVERY_DAYS = 7;

    private function __construct()
    {
    }

    /**
     * Τα ονόματα των ενεργών προγραμμάτων ρεύματος/αερίου με παλιά ή καμία τιμή.
     *
     * @param list<array<string, mixed>> $programs Ενεργά προγράμματα.
     * @return list<string>
     */
    public static function names(array $programs, string $today): array
    {
        $out = [];

        foreach ($programs as $program) {
            if (! in_array((string) ($program['energy_type'] ?? ''), ['power', 'gas'], true)) {
                continue;
            }

            if (ProgramPricing::isStale($program, $today)) {
                $out[] = (string) ($program['name'] ?? '');
            }
        }

        sort($out);

        return $out;
    }

    /** Να σταλεί ειδοποίηση σήμερα; */
    public static function shouldNotify(?string $lastSent, int $lastCount, int $count, string $today): bool
    {
        if ($count === 0) {
            return false;
        }

        if ($lastSent === null || $count > $lastCount) {
            return true;
        }

        $from = strtotime($lastSent . ' 00:00:00 UTC');
        $to   = strtotime($today . ' 00:00:00 UTC');

        return $from === false || $to === false || ($to - $from) / 86400 >= self::EVERY_DAYS;
    }

    /**
     * Ο τίτλος και το κείμενο της ειδοποίησης.
     *
     * @param list<string> $names
     * @return array{title: string, body: string}
     */
    public static function message(array $names): array
    {
        $count = count($names);
        $shown = array_slice($names, 0, 5);
        $more  = $count - count($shown);

        return [
            'title' => $count === 1
                ? '1 πρόγραμμα θέλει νέα τιμή'
                : $count . ' προγράμματα θέλουν νέα τιμή',
            'body'  => implode(', ', $shown) . ($more > 0 ? ' και ' . $more . ' ακόμα' : '')
                . ' — Πάροχοι & Προγράμματα',
        ];
    }
}
