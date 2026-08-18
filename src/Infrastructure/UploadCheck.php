<?php

/**
 * Τι είναι πραγματικά ένα ανεβασμένο αρχείο.
 *
 * Ο browser δηλώνει τύπο, αλλά τον δηλώνει ο πελάτης — αλλάζει με ένα
 * curl. Εδώ η απάντηση βγαίνει από τα πρώτα bytes του ίδιου του αρχείου,
 * που δεν ψεύδονται το ίδιο εύκολα.
 *
 * Δεν υπάρχει WordPress μέσα, ώστε να ελέγχεται με σκέτα unit tests.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

final class UploadCheck
{
    /** Πάνω από αυτό δεν είναι σαρωμένη ταυτότητα, είναι κάτι άλλο. */
    public const MAX_BYTES = 12 * 1024 * 1024;

    /** Κάτω από αυτό δεν χωράει ούτε η κεφαλίδα ενός αρχείου. */
    public const MIN_BYTES = 64;

    /** Όσα bytes χρειάζονται για να αναγνωριστούν όλοι οι τύποι παρακάτω. */
    public const HEAD_BYTES = 16;

    /**
     * Η κατάληξη βγαίνει από τον τύπο που επιβεβαιώθηκε, ποτέ από το όνομα
     * που έστειλε ο πελάτης.
     *
     * @var array<string, string>
     */
    private const EXT = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'image/heic'      => 'heic',
        'application/pdf' => 'pdf',
    ];

    /** Τα brands του ISO-BMFF που σημαίνουν «φωτογραφία HEIF». */
    private const HEIF_BRANDS = ['heic', 'heix', 'hevc', 'heim', 'heis', 'hevm', 'hevs', 'mif1', 'msf1'];

    /**
     * Ο τύπος του αρχείου από τα πρώτα του bytes.
     *
     * @param string $head Τα πρώτα bytes — αρκούν HEAD_BYTES.
     *
     * @return string Ο τύπος, ή κενό όταν δεν αναγνωρίζεται.
     */
    public static function sniff(string $head): string
    {
        if (strncmp($head, "\xFF\xD8\xFF", 3) === 0) {
            return 'image/jpeg';
        }

        if (strncmp($head, "\x89PNG\r\n\x1A\n", 8) === 0) {
            return 'image/png';
        }

        if (strncmp($head, '%PDF-', 5) === 0) {
            return 'application/pdf';
        }

        // RIFF....WEBP — τα τέσσερα bytes στη μέση είναι το μέγεθος.
        if (strncmp($head, 'RIFF', 4) === 0 && substr($head, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        // HEIC: κουτί ftyp στη θέση 4, και το brand αμέσως μετά.
        if (substr($head, 4, 4) === 'ftyp' && in_array(substr($head, 8, 4), self::HEIF_BRANDS, true)) {
            return 'image/heic';
        }

        return '';
    }

    /** Η κατάληξη για έναν τύπο που έχει ήδη επιβεβαιωθεί. */
    public static function extensionFor(string $mime): string
    {
        return self::EXT[strtolower($mime)] ?? 'bin';
    }

    /** Είναι τύπος που ξέρουμε να αναγνωρίζουμε από τα bytes του; */
    public static function knows(string $mime): bool
    {
        return isset(self::EXT[strtolower($mime)]);
    }

    /**
     * Το μέγεθος βγάζει νόημα;
     *
     * @return string Κενό όταν είναι εντάξει, αλλιώς ο λόγος απόρριψης.
     */
    public static function sizeProblem(int $bytes): string
    {
        if ($bytes < self::MIN_BYTES) {
            return 'empty';
        }

        if ($bytes > self::MAX_BYTES) {
            return 'too_large';
        }

        return '';
    }
}
