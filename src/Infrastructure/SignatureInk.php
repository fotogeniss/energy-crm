<?php

/**
 * Χοντραίνει την πινελιά μιας υπογραφής πριν τυπωθεί στο έντυπο.
 *
 * ## Γιατί (293)
 *
 * Το pad γράφει με 2,2px, και η εικόνα του (540-1100px πλάτος) μπαίνει σε
 * κουτί ~36mm: η πινελιά βγαίνει ~0,15mm στο χαρτί -- τρίχα, που σε
 * φωτοτυπία ή σκανάρισμα σχεδόν χάνεται. Το πάχος διορθώνεται ΕΔΩ, στην
 * εκτύπωση, και όχι στο pad: έτσι χοντραίνουν και οι υπογραφές που έχουν
 * ήδη δοθεί, και το αποτέλεσμα είναι ίδιο σε mm χαρτιού όποια κι αν ήταν η
 * συσκευή (το pad του κινητού έχει άλλη ανάλυση από του υπολογιστή).
 *
 * ## Πώς
 *
 * Διαστολή (dilation): η εικόνα αντιγράφεται πάνω στον εαυτό της
 * μετατοπισμένη σε κάθε σημείο ενός δίσκου ακτίνας r. Σε διάφανο φόντο κάθε
 * αντίγραφο προσθέτει μελάνι, οπότε η γραμμή φαρδαίνει κατά r από κάθε
 * πλευρά χωρίς να αλλάζουν οι διαστάσεις της εικόνας -- άρα ούτε η θέση
 * της στο κουτί (`SignatureBox`).
 *
 * Μόνο για PNG με διάφανο φόντο (αυτό βγάζει το canvas του pad). Εικόνα με
 * αδιαφανές φόντο μένει όπως είναι: εκεί κάθε αντίγραφο θα έσβηνε το
 * προηγούμενο αντί να προσθέτει μελάνι.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

final class SignatureInk
{
    /** Πόσο φαρδαίνει η πινελιά από κάθε πλευρά, σε mm χαρτιού. */
    public const GROW_MM = 0.15;

    /** Όριο ασφαλείας: πολύ μεγάλη ακτίνα = μουτζούρα και αργή εκτύπωση. */
    public const MAX_RADIUS = 8;

    private function __construct()
    {
    }

    /**
     * Η ακτίνα σε pixel της εικόνας ώστε η πινελιά να φαρδύνει GROW_MM στο
     * χαρτί, για εικόνα $pixelW pixel που τυπώνεται σε $placedWmm mm.
     */
    public static function radiusPx(int $pixelW, float $placedWmm): int
    {
        if ($pixelW <= 0 || $placedWmm <= 0) {
            return 0;
        }

        $r = (int) round($pixelW / $placedWmm * self::GROW_MM);

        return max(0, min(self::MAX_RADIUS, $r));
    }

    /**
     * Οι μετατοπίσεις ενός γεμάτου δίσκου ακτίνας $r, χωρίς το (0, 0).
     *
     * @return list<array{int, int}>
     */
    public static function offsets(int $r): array
    {
        $out = [];

        for ($dy = -$r; $dy <= $r; $dy++) {
            for ($dx = -$r; $dx <= $r; $dx++) {
                if (($dx !== 0 || $dy !== 0) && $dx * $dx + $dy * $dy <= $r * $r) {
                    $out[] = [$dx, $dy];
                }
            }
        }

        return $out;
    }

    /**
     * Γράφει χοντρότερο αντίγραφο της υπογραφής σε προσωρινό αρχείο και
     * επιστρέφει τη διαδρομή του -- ή null όταν δεν γίνεται (χωρίς GD, όχι
     * PNG, αδιαφανές φόντο, r < 1): τότε τυπώνεται το πρωτότυπο.
     *
     * Το αρχείο το σβήνει όποιος το ζήτησε, αφού το διαβάσει το PDF.
     */
    public static function thicken(string $path, int $r): ?string
    {
        if ($r < 1 || ! function_exists('imagecreatefrompng') || ! is_file($path)) {
            return null;
        }

        // Πρώτα το getimagesize(): ό,τι δεν είναι PNG σταματά εδώ, χωρίς
        // το warning που θα έβγαζε το imagecreatefrompng().
        $info = getimagesize($path);

        if ($info === false || $info[2] !== IMAGETYPE_PNG) {
            return null;
        }

        $src = imagecreatefrompng($path);

        if ($src === false) {
            return null;
        }

        $w = imagesx($src);
        $h = imagesy($src);

        if (! self::hasTransparentBackground($src, $w, $h)) {
            imagedestroy($src);

            return null;
        }

        $dst = imagecreatetruecolor($w, $h);

        if ($dst === false) {
            imagedestroy($src);

            return null;
        }

        imagealphablending($dst, false);
        imagefill($dst, 0, 0, (int) imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagealphablending($dst, true);

        foreach ([...self::offsets($r), [0, 0]] as [$dx, $dy]) {
            // Αρνητική μετατόπιση = κόβουμε από την πηγή, όχι γράφουμε έξω
            // από τον προορισμό.
            imagecopy(
                $dst,
                $src,
                max(0, $dx),
                max(0, $dy),
                max(0, -$dx),
                max(0, -$dy),
                $w - abs($dx),
                $h - abs($dy)
            );
        }

        imagedestroy($src);
        imagesavealpha($dst, true);

        // Με κατάληξη .png: το tFPDF καταλαβαίνει τον τύπο από αυτήν.
        $base = tempnam(sys_get_temp_dir(), 'ecrm-sig-');

        if ($base === false) {
            imagedestroy($dst);

            return null;
        }

        $tmp = $base . '.png';
        $ok  = imagepng($dst, $tmp);

        imagedestroy($dst);
        @unlink($base);

        if (! $ok) {
            @unlink($tmp);

            return null;
        }

        return $tmp;
    }

    /** Οι τέσσερις γωνίες σχεδόν πλήρως διάφανες = φόντο canvas. */
    private static function hasTransparentBackground(\GdImage $img, int $w, int $h): bool
    {
        foreach ([[0, 0], [$w - 1, 0], [0, $h - 1], [$w - 1, $h - 1]] as [$x, $y]) {
            $c = imagecolorsforindex($img, (int) imagecolorat($img, $x, $y));

            if ($c['alpha'] < 120) {
                return false;
            }
        }

        return true;
    }
}
