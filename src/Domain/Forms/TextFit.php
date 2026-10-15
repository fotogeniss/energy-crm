<?php

/**
 * (317c) Μέγεθος γραμματοσειράς που χωράει σε `"maxw"` χιλιοστά.
 *
 * Μερικά κουτιά εντύπων είναι στενά (π.χ. EMAIL πριν από το «ΚΑΔ:» στα
 * «Στοιχεία Εταιρείας» της Volton). Με `maxw` το κείμενο μικραίνει ως ότου
 * χωρέσει, όχι κάτω από MIN pt· αν ούτε τότε χωράει, τυπώνεται στο MIN.
 * Χωρίς `maxw` το μέγεθος μένει ακριβώς όπως ως τώρα.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Forms;

final class TextFit
{
    public const MIN = 5.5;

    /**
     * @param array<string, mixed>   $pos
     * @param callable(float): float $widthAt πλάτος (mm) του κειμένου σε pt
     */
    public static function size(array $pos, float $size, callable $widthAt): float
    {
        $maxw = (float) ($pos['maxw'] ?? 0);

        if ($maxw <= 0 || $size <= self::MIN) {
            return $size;
        }

        $w = $widthAt($size);
        if ($w <= $maxw) {
            return $size;
        }

        // Το πλάτος είναι ανάλογο του μεγέθους· στρογγυλεύουμε προς τα κάτω
        // στο 0,1 pt ώστε να μη ξεπεράσει ποτέ το όριο.
        $fit = floor(($size * $maxw / $w) * 10) / 10;

        return max(self::MIN, $fit);
    }
}
