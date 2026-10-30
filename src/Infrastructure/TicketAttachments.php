<?php

/**
 * (339) Η εικόνα μιας αναφοράς: αποθήκευση, σβήσιμο.
 *
 * Μένει στον ίδιο προστατευμένο φάκελο με τα έγγραφα (`ECRM_Files::dir()`,
 * .htaccess + web.config), με όνομα που δεν μαντεύεται, και σερβίρεται μόνο
 * στον διαχειριστή από το `GET /tickets/{id}/attachment`. Σβήνεται μόλις
 * κλείσει η αναφορά (TicketsController::status()).
 *
 * Μόνο εικόνες (JPG, PNG, WebP), έως 5 MB, με τύπο από τα bytes και όχι από
 * το όνομα (UploadCheck).
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use EnergyCRM\Persistence\DocumentStorage;

final class TicketAttachments
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    private const ALLOWED = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private readonly DocumentStorage $storage)
    {
    }

    /**
     * @param array<string, mixed> $upload Μία εγγραφή του `$_FILES`.
     * @return array{path: string, mime: string}|string Η θέση, ή ο λόγος απόρριψης.
     */
    public function store(array $upload): array|string
    {
        $tmp  = (string) ($upload['tmp_name'] ?? '');
        $size = (int) ($upload['size'] ?? 0);

        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $tmp === '' || ! is_uploaded_file($tmp)) {
            return 'Η εικόνα δεν ανέβηκε.';
        }

        if ($size > self::MAX_BYTES || UploadCheck::sizeProblem($size) !== '') {
            return 'Η εικόνα πρέπει να είναι έως 5 MB.';
        }

        $head = (string) file_get_contents($tmp, false, null, 0, UploadCheck::HEAD_BYTES);
        $mime = UploadCheck::sniff($head);

        if (! in_array($mime, self::ALLOWED, true)) {
            return 'Μόνο εικόνα JPG, PNG ή WebP.';
        }

        $path = $this->storage->newPath(UploadCheck::extensionFor($mime));

        if (! move_uploaded_file($tmp, $path)) {
            return 'Η εικόνα δεν αποθηκεύτηκε.';
        }

        return ['path' => $path, 'mime' => $mime];
    }

    public function delete(?string $path): void
    {
        if ($path !== null && $path !== '' && $this->storage->contains($path)) {
            wp_delete_file($path);
        }
    }

    public function readable(?string $path): bool
    {
        return $path !== null && $path !== '' && $this->storage->contains($path) && is_readable($path);
    }
}
