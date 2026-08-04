<?php

/**
 * Prints a provider template with a millimetre grid over it.
 *
 * Field positions in assets/forms/*.json are millimetres from the top-left of
 * the page. Working them out by editing a number, regenerating a contract and
 * squinting at the result costs minutes per field — and there are fifteen
 * templates with roughly thirty fields each, revised whenever a provider
 * reissues a form.
 *
 * The sheet this produces shows the grid and, in red, every field the map
 * already places. Missing fields are obvious by their absence; misplaced ones
 * are obvious by where their label sits.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Admin;

use EnergyCRM\Plugin;
use tFPDF;

final class FormCalibrator
{
    private const ACTION = 'ecrm_form_calibrate';

    private const PAGE = 'energy-crm-forms';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addPage']);
        add_action('admin_post_' . self::ACTION, [$this, 'stream']);
    }

    public function addPage(): void
    {
        add_submenu_page(
            'energy-crm',
            'Έντυπα παρόχων',
            'Έντυπα παρόχων',
            'manage_options',
            self::PAGE,
            [$this, 'renderPage']
        );
    }

    public function renderPage(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Δεν επιτρέπεται.');
        }

        echo '<div class="wrap"><h1>Έντυπα παρόχων</h1>';
        echo '<p>Το φύλλο βαθμονόμησης δείχνει πλέγμα χιλιοστών πάνω στο έντυπο και,'
            . ' με κόκκινο, κάθε πεδίο που ήδη τοποθετεί ο χάρτης. Οι συντεταγμένες στο'
            . ' <code>assets/forms/{key}.json</code> είναι χιλιοστά από την πάνω αριστερή γωνία.</p>';

        echo '<table class="widefat striped"><thead><tr>'
            . '<th>Πρότυπο</th><th>Πεδία</th><th>Σελίδες</th><th></th>'
            . '</tr></thead><tbody>';

        foreach ($this->templates() as $key => $info) {
            $url = wp_nonce_url(
                admin_url('admin-post.php?action=' . self::ACTION . '&key=' . rawurlencode($key)),
                self::ACTION
            );

            printf(
                '<tr><td><code>%s</code></td><td>%d</td><td>%d</td>'
                . '<td><a class="button" href="%s" target="_blank">Φύλλο βαθμονόμησης</a></td></tr>',
                esc_html($key),
                (int) $info['fields'],
                (int) $info['pages'],
                esc_url($url)
            );
        }

        echo '</tbody></table></div>';
    }

    /**
     * @return array<string, array{fields: int, pages: int}>
     */
    private function templates(): array
    {
        $found = [];

        foreach (glob($this->formsDir() . '*.json') ?: [] as $path) {
            $key = basename($path, '.json');
            $map = $this->map($key);

            if ($map === null) {
                continue;
            }

            $pages = 0;
            while (file_exists($this->formsDir() . $key . '-' . ($pages + 1) . '.jpg')) {
                $pages++;
            }

            $found[$key] = ['fields' => count($map['fields'] ?? []), 'pages' => $pages];
        }

        ksort($found);

        return $found;
    }

    public function stream(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Δεν επιτρέπεται.');
        }

        check_admin_referer(self::ACTION);

        $key = sanitize_key((string) ($_GET['key'] ?? ''));
        $map = $this->map($key);

        if ($map === null) {
            wp_die('Άγνωστο πρότυπο.');
        }

        require_once Plugin::instance()?->dir() . 'includes/lib/tfpdf/tfpdf.php';

        $pdf = $this->draw($key, $map);

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $key . '-calibration.pdf"');

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF
        echo $pdf->Output('S');
        exit;
    }

    /**
     * @param array{page_w?: float, page_h?: float, fields?: array<string, array<string, mixed>>} $map
     */
    private function draw(string $key, array $map): tFPDF
    {
        $width  = (float) ($map['page_w'] ?? 210);
        $height = (float) ($map['page_h'] ?? 297);
        $orient = $width > $height ? 'L' : 'P';

        $pdf = new tFPDF($orient, 'mm', [$width, $height]);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetFont('Arial', '', 5);

        $page = 1;

        while (file_exists($this->formsDir() . $key . '-' . $page . '.jpg')) {
            $pdf->AddPage($orient, [$width, $height]);
            $pdf->Image($this->formsDir() . $key . '-' . $page . '.jpg', 0, 0, $width, $height);

            $this->drawGrid($pdf, $width, $height);
            $this->drawFields($pdf, $map['fields'] ?? [], $page);

            $page++;
        }

        return $pdf;
    }

    private function drawGrid(tFPDF $pdf, float $width, float $height): void
    {
        $pdf->SetTextColor(0, 120, 200);

        for ($x = 0; $x <= $width; $x += 5) {
            $major = $x % 10 === 0;
            $pdf->SetDrawColor(0, 120, 200);
            $pdf->SetLineWidth($major ? 0.12 : 0.05);
            $pdf->Line($x, 0, $x, $height);

            if ($major && $x > 0) {
                $pdf->Text($x + 0.4, 3.2, (string) $x);
            }
        }

        for ($y = 0; $y <= $height; $y += 5) {
            $major = $y % 10 === 0;
            $pdf->SetLineWidth($major ? 0.12 : 0.05);
            $pdf->Line(0, $y, $width, $y);

            if ($major && $y > 0) {
                $pdf->Text(0.6, $y - 0.6, (string) $y);
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     */
    private function drawFields(tFPDF $pdf, array $fields, int $page): void
    {
        $pdf->SetDrawColor(200, 0, 0);
        $pdf->SetTextColor(200, 0, 0);
        $pdf->SetLineWidth(0.3);

        foreach ($fields as $name => $position) {
            if ((int) ($position['page'] ?? 1) !== $page) {
                continue;
            }

            $x = (float) ($position['x'] ?? 0);
            $y = (float) ($position['y'] ?? 0);

            // A cross on the anchor, so the exact point is unambiguous.
            $pdf->Line($x - 1.5, $y, $x + 1.5, $y);
            $pdf->Line($x, $y - 1.5, $x, $y + 1.5);
            $pdf->Text($x + 2, $y + 1, $name);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function map(string $key): ?array
    {
        $path = $this->formsDir() . $key . '.json';

        if ($key === '' || ! is_readable($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function formsDir(): string
    {
        return Plugin::instance()?->dir() . 'assets/forms/';
    }
}
