<?php

/**
 * (382) Η φωτογραφία προφίλ ενός χρήστη. docs/UI-PROFILE-PHOTO.html.
 *
 * - Το κόψιμο σε κύκλο γίνεται στη συσκευή· εδώ φτάνει μια εικόνα (JPG, PNG
 *   ή WebP, έως 8 MB, τύπος από τα bytes). Ο server την ξαναφτιάχνει ο ίδιος:
 *   τετράγωνο από το κέντρο, 256×256, JPEG. Έτσι φεύγουν και τα μεταδεδομένα
 *   (EXIF, π.χ. τοποθεσία GPS), αφού γράφονται μόνο τα pixels.
 * - Μένει στον προστατευμένο φάκελο των εγγράφων (`ECRM_Files::dir()`), όχι
 *   στη Βιβλιοθήκη Πολυμέσων. Η θέση της σε ένα user meta.
 * - Σερβίρεται από το `GET /photos/{id}` μόνο σε συνδεδεμένο χρήστη του CRM.
 *   Μια εικόνα `<img>` δεν στέλνει το nonce του REST στις κεφαλίδες, οπότε ο
 *   σύνδεσμος το κουβαλά στο `_wpnonce` (`url()`), όπως τα έγγραφα
 *   (`ECRM_Files::url`). Χωρίς σύνδεση, ο σύνδεσμος δεν ανοίγει.
 * - «Αφαίρεση» και διαγραφή χρήστη σβήνουν το αρχείο.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use EnergyCRM\Persistence\DocumentStorage;

final class ProfilePhoto
{
    public const META_KEY = 'ecrm_photo';

    public const MAX_BYTES = 8 * 1024 * 1024;

    /** Μικρότερη από αυτό δεν βγάζει καθαρό avatar. */
    public const MIN_SIDE = 128;

    public const SIDE = 256;

    private const ALLOWED = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private readonly DocumentStorage $storage)
    {
    }

    public function register(): void
    {
        add_action('delete_user', [$this, 'remove']);
    }

    /**
     * @param array<string, mixed> $upload Μία εγγραφή του `$_FILES`.
     * @return string Κενό όταν αποθηκεύτηκε, αλλιώς ο λόγος.
     */
    public function save(int $userId, array $upload): string
    {
        $tmp  = (string) ($upload['tmp_name'] ?? '');
        $size = (int) ($upload['size'] ?? 0);

        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $tmp === '' || ! is_uploaded_file($tmp)) {
            return 'Η φωτογραφία δεν ανέβηκε.';
        }

        if ($size > self::MAX_BYTES || UploadCheck::sizeProblem($size) !== '') {
            return 'Η φωτογραφία πρέπει να είναι έως 8 MB.';
        }

        $bytes = (string) file_get_contents($tmp);

        if (! in_array(UploadCheck::sniff(substr($bytes, 0, UploadCheck::HEAD_BYTES)), self::ALLOWED, true)) {
            return 'Μόνο φωτογραφία JPG, PNG ή WebP.';
        }

        if (! function_exists('imagecreatefromstring')) {
            return 'Ο server δεν μπορεί να επεξεργαστεί εικόνες (λείπει το GD).';
        }

        $src = @imagecreatefromstring($bytes);

        if ($src === false) {
            return 'Η φωτογραφία δεν διαβάζεται.';
        }

        $w = imagesx($src);
        $h = imagesy($src);

        if (min($w, $h) < self::MIN_SIDE) {
            imagedestroy($src);

            return sprintf('Η εικόνα είναι πολύ μικρή (κάτω από %1$d×%1$d). Διάλεξε μεγαλύτερη.', self::MIN_SIDE);
        }

        $side = min($w, $h);
        $out  = imagecreatetruecolor(self::SIDE, self::SIDE);

        if ($out === false) {
            imagedestroy($src);

            return 'Η φωτογραφία δεν αποθηκεύτηκε.';
        }

        imagefill($out, 0, 0, (int) imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled(
            $out,
            $src,
            0,
            0,
            intdiv($w - $side, 2),
            intdiv($h - $side, 2),
            self::SIDE,
            self::SIDE,
            $side,
            $side
        );
        imagedestroy($src);

        $path = $this->storage->newPath('jpg');
        $ok   = imagejpeg($out, $path, 86);
        imagedestroy($out);

        if (! $ok) {
            return 'Η φωτογραφία δεν αποθηκεύτηκε.';
        }

        $this->deleteFile($this->storedPath($userId));
        $this->remember($userId, $path, time());

        return '';
    }

    /**
     * Γράφει πού είναι η φωτογραφία του χρήστη.
     *
     * Κάθετοι «/» και όχι «\»: το update_user_meta() περνά την τιμή από
     * wp_unslash(), που στα Windows έσβηνε τις «\» της διαδρομής
     * («C:\Users\…» → «C:Users…») και η φωτογραφία δεν βρισκόταν ποτέ.
     */
    public function remember(int $userId, string $path, int $version): void
    {
        update_user_meta($userId, self::META_KEY, ['path' => wp_normalize_path($path), 'v' => $version]);
    }

    public function remove(int $userId): void
    {
        $this->deleteFile($this->storedPath($userId));
        delete_user_meta($userId, self::META_KEY);
    }

    /** Το αρχείο, αν υπάρχει και είναι μέσα στον φάκελο. */
    public function path(int $userId): ?string
    {
        $path = $this->storedPath($userId);

        return $path !== '' && $this->storage->contains($path) && is_readable($path) ? $path : null;
    }

    /**
     * Στρογγυλή εκδοχή για το PDF (λευκές γωνίες), σε προσωρινό αρχείο που
     * σβήνει όποιος την καλεί. null όταν δεν υπάρχει φωτογραφία ή GD.
     */
    public function roundCopy(int $userId): ?string
    {
        $path = $this->path($userId);

        if ($path === null || ! function_exists('imagecreatefromstring')) {
            return null;
        }

        // Όπως στο save(): μια χαλασμένη εικόνα δίνει false, όχι warning στη σελίδα.
        $img = @imagecreatefromstring((string) file_get_contents($path));

        if ($img === false) {
            return null;
        }

        $s     = imagesx($img);
        $white = (int) imagecolorallocate($img, 255, 255, 255);
        $r     = $s / 2;

        for ($y = 0; $y < $s; $y++) {
            for ($x = 0; $x < $s; $x++) {
                if (($x - $r + .5) ** 2 + ($y - $r + .5) ** 2 > $r * $r) {
                    imagesetpixel($img, $x, $y, $white);
                }
            }
        }

        $tmp = wp_tempnam('ecrm-photo') . '.jpg';
        $ok  = imagejpeg($img, $tmp, 90);
        imagedestroy($img);

        return $ok ? $tmp : null;
    }

    /**
     * Ο σύνδεσμος της εικόνας για τη σελίδα του CRM, ή '' όταν δεν έχει
     * φωτογραφία. Το `v` αλλάζει σε κάθε νέα φωτογραφία (cache).
     */
    public static function url(int $userId): string
    {
        $meta = get_user_meta($userId, self::META_KEY, true);

        if (! is_array($meta) || empty($meta['path'])) {
            return '';
        }

        return add_query_arg(
            ['v' => (int) ($meta['v'] ?? 0), '_wpnonce' => wp_create_nonce('wp_rest')],
            rest_url('ecrm/v1/photos/' . $userId)
        );
    }

    private function storedPath(int $userId): string
    {
        $meta = get_user_meta($userId, self::META_KEY, true);

        return is_array($meta) ? (string) ($meta['path'] ?? '') : '';
    }

    private function deleteFile(string $path): void
    {
        if ($path !== '' && $this->storage->contains($path)) {
            wp_delete_file($path);
        }
    }
}
