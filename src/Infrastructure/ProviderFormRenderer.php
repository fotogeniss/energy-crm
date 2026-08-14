<?php

/**
 * The real renderer: the provider's own forms, with the CRM's summary as a
 * fallback.
 *
 * The provider's form is preferred because it is what the customer signs and
 * what the provider accepts. The internal summary exists for a provider we
 * have no template for yet, so a contract always has something attached.
 *
 * Everything here is the awkward part of PDF generation, kept in one place:
 * two legacy static classes, libraries that write to stdout while they work,
 * and limits that the defaults on a shared host do not meet.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use ECRM_FormFill;
use ECRM_PDF;
use Throwable;

final class ProviderFormRenderer implements SheetRenderer
{
    /**
     * The Orizon contract alone loads nine background images, 3.2 MB of them,
     * and renders eleven pages. The defaults on a shared host are not enough,
     * and the failure is a blank document rather than an error.
     */
    private const MEMORY = '256M';

    private const SECONDS = 60;

    /**
     * @param array<string, mixed> $contract
     *
     * @return list<array{key: string, bytes: string}>
     */
    public function render(array $contract, ?string $signaturePath): array
    {
        ini_set('memory_limit', self::MEMORY);
        TimeLimit::atLeast(self::SECONDS);

        $sheets = [];

        foreach ($this->providerForms($contract, $signaturePath) as $sheet) {
            $bytes = self::fromPdfHeader((string) ($sheet['bytes'] ?? ''));

            // A sheet that failed is dropped rather than fatal: an agent who
            // can print three of four forms is better off than one who gets
            // nothing at all.
            if (empty($sheet['ok']) || $bytes === null) {
                continue;
            }

            $sheets[] = ['key' => (string) $sheet['key'], 'bytes' => $bytes];
        }

        if ($sheets !== []) {
            return $sheets;
        }

        $summary = $this->summary($contract, $signaturePath);

        return $summary === null ? [] : [['key' => ContractDocuments::KIND, 'bytes' => $summary]];
    }

    /**
     * @param array<string, mixed> $contract
     *
     * @return list<array{key: string, ok: bool, error?: string, bytes?: string, filename?: string}>
     */
    private function providerForms(array $contract, ?string $signaturePath): array
    {
        if (! class_exists(ECRM_FormFill::class)) {
            return [];
        }

        $reporting = error_reporting(0);
        ob_start();

        try {
            $sheets = ECRM_FormFill::fill_all($contract, $signaturePath);
        } catch (Throwable) {
            $sheets = [];
        } finally {
            ob_end_clean();
            error_reporting($reporting);
        }

        return $sheets;
    }

    /**
     * @param array<string, mixed> $contract
     */
    private function summary(array $contract, ?string $signaturePath): ?string
    {
        $reporting = error_reporting(0);
        ob_start();

        try {
            $bytes = ECRM_PDF::build($contract, $signaturePath);
        } catch (Throwable) {
            $bytes = '';
        } finally {
            ob_end_clean();
            error_reporting($reporting);
        }

        return self::fromPdfHeader($bytes);
    }

    /**
     * The bytes from the PDF header on, or null when there is no header.
     *
     * These libraries write to stdout as they go. Buffering catches that, but
     * a notice raised before the buffer opened still lands in front of the
     * header and corrupts the file, so anything preceding "%PDF-" is discarded
     * rather than trusted — and a payload with no header at all is not a
     * document, it is an error message.
     */
    private static function fromPdfHeader(string $bytes): ?string
    {
        $start = strpos($bytes, '%PDF-');

        // substr from zero returns the string unchanged, so no branch is needed.
        return $start === false ? null : substr($bytes, $start);
    }
}
