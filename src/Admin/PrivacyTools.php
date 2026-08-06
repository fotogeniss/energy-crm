<?php

/**
 * Hooks the CRM into WordPress' own privacy screens.
 *
 * The plugin has had a working GDPR screen for a while, under Energy CRM →
 * GDPR. WordPress has its own, under Tools → Export/Erase Personal Data, and
 * that is the one a site administrator reaches for — it is where the platform
 * documentation sends them, and it is what a WordPress-literate DPO will check.
 * Until now it answered "no personal data found" for a customer whose ΑΦΜ,
 * address and signature we were holding.
 *
 * A confidently wrong answer is worse than a missing feature: it is the kind
 * of thing that gets reported to a regulator as a complete response.
 *
 * Nothing here decides *what* personal data is. Both callbacks delegate to the
 * same PersonalDataExporter and PersonalDataEraser the plugin's own screen
 * uses, so the two entry points can never disagree.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Admin;

use EnergyCRM\Persistence\PersonalDataEraser;
use EnergyCRM\Persistence\PersonalDataExporter;
use EnergyCRM\Persistence\Tables;

final class PrivacyTools
{
    /** Identifies our callbacks in WordPress' own list. */
    private const SLUG = 'energy-crm';

    /**
     * Section headings on the exported report, keyed by what export() returns.
     *
     * Written for the person receiving the file, not after the table names:
     * "signatures" means nothing to a customer asking what we hold about them.
     *
     * @var array<string, string>
     */
    private const GROUP_LABELS = [
        'customer'            => 'Στοιχεία πελάτη',
        'contracts'           => 'Συμβάσεις',
        Tables::FILES         => 'Έγγραφα',
        Tables::SIGNATURES    => 'Υπογραφές',
        Tables::EVENTS        => 'Ιστορικό ενεργειών',
        Tables::NOTIFICATIONS => 'Ειδοποιήσεις',
        Tables::LEADS         => 'Αρχική επικοινωνία',
        Tables::TASKS         => 'Εργασίες',
    ];

    public function __construct(
        private readonly PersonalDataExporter $exporter,
        private readonly PersonalDataEraser $eraser,
    ) {
    }

    public function register(): void
    {
        add_filter('wp_privacy_personal_data_exporters', [$this, 'registerExporter']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'registerEraser']);
    }

    /**
     * @param array<string, mixed> $exporters
     *
     * @return array<string, mixed>
     */
    public function registerExporter(array $exporters): array
    {
        $exporters[self::SLUG] = [
            'exporter_friendly_name' => 'Energy CRM — δεδομένα πελάτη',
            'callback'               => [$this, 'exportPage'],
        ];

        return $exporters;
    }

    /**
     * @param array<string, mixed> $erasers
     *
     * @return array<string, mixed>
     */
    public function registerEraser(array $erasers): array
    {
        $erasers[self::SLUG] = [
            'eraser_friendly_name' => 'Energy CRM — δεδομένα πελάτη',
            'callback'             => [$this, 'erasePage'],
        ];

        return $erasers;
    }

    /**
     * One customer per page.
     *
     * WordPress pages these callbacks so a request cannot run away with the
     * server, and an email address can legitimately belong to more than one
     * customer row. One row per call keeps each page small and predictable
     * regardless of how many contracts sit behind it.
     *
     * @return array{data: list<array<string, mixed>>, done: bool}
     */
    public function exportPage(string $email, int $page = 1): array
    {
        $ids        = $this->exporter->subjectIdsByEmail($email);
        $customerId = $ids[$page - 1] ?? null;

        if ($customerId === null) {
            return ['data' => [], 'done' => true];
        }

        $subject = $this->exporter->export($customerId);
        $done    = $page >= count($ids);

        if ($subject === null) {
            return ['data' => [], 'done' => $done];
        }

        return ['data' => $this->toReport($customerId, $subject), 'done' => $done];
    }

    /**
     * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
     */
    public function erasePage(string $email, int $page = 1): array
    {
        $ids        = $this->exporter->subjectIdsByEmail($email);
        $customerId = $ids[$page - 1] ?? null;

        if ($customerId === null) {
            return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        }

        $this->eraser->erase($customerId);

        return [
            'items_removed'  => true,
            // Said out loud because "retained" here is a decision, not a
            // leftover: contract rows survive stripped of anything personal.
            'items_retained' => true,
            'messages'       => [
                'Οι συμβάσεις διατηρήθηκαν ανωνυμοποιημένες, χωρίς στοιχεία ταυτοποίησης, '
                . 'για λογιστικούς και στατιστικούς σκοπούς.',
            ],
            'done'           => $page >= count($ids),
        ];
    }

    /**
     * Turn the exporter's nested result into the flat groups WordPress renders.
     *
     * @param array<string, mixed> $subject
     *
     * @return list<array<string, mixed>>
     */
    private function toReport(int $customerId, array $subject): array
    {
        $report = [];

        foreach ($subject as $section => $content) {
            if (! is_array($content) || $content === []) {
                continue;
            }

            // The customer is a single row; everything else is a list of them.
            $rows = $section === 'customer' ? [$content] : $content;

            foreach (array_values($rows) as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }

                $report[] = [
                    'group_id'    => self::SLUG . '-' . $section,
                    'group_label' => self::GROUP_LABELS[$section] ?? $section,
                    'item_id'     => self::SLUG . '-' . $section . '-' . $customerId . '-' . $index,
                    'data'        => $this->toFields($row),
                ];
            }
        }

        return $report;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<array{name: string, value: string}>
     */
    private function toFields(array $row): array
    {
        $fields = [];

        foreach ($row as $column => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $fields[] = [
                'name'  => (string) $column,
                'value' => is_scalar($value) ? (string) $value : (string) wp_json_encode($value),
            ];
        }

        return $fields;
    }
}
