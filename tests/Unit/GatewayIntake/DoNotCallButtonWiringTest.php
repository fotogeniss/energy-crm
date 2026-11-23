<?php

/**
 * Το κουμπί «Δεν θέλει κλήσεις» (452) στην κάρτα του lead.
 *
 * Το σημείο που μπορεί να σπάσει σιωπηλά δεν είναι ο κανόνας (τον φυλάει το
 * DoNotCallDecisionTest) αλλά η σύνδεση: ότι το κουμπί καλεί τη σωστή διαδρομή
 * και ότι η λίστα δηλώνει ποια leads είναι ήδη σημειωμένα, με τη ΜΙΑ σταθερά.
 *
 * @package EnergyCRM
 * @kanonas K10
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\GatewayIntake;

use PHPUnit\Framework\TestCase;

final class DoNotCallButtonWiringTest extends TestCase
{
    private function source(string $relative): string
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/' . $relative);
        self::assertIsString($source);

        return $source;
    }

    public function testButtonCallsTheDoNotCallRouteAfterConfirmation(): void
    {
        $js = $this->source('public/assets/ecrm-view-leads.js');

        self::assertStringContainsString("'/do-not-call'", $js);
        // Η κλήση ζει μέσα στο onConfirm του διαλόγου, όχι στο click του κουμπιού.
        $dialog = strpos($js, 'function confirmDnc');
        $call   = strpos($js, "'/do-not-call'");
        self::assertNotFalse($dialog);
        self::assertGreaterThan($dialog, $call);
        self::assertStringContainsString('onConfirm', $js);
    }

    public function testListMarksLeadsUsingTheSingleReasonConstant(): void
    {
        $controller = $this->source('src/Http/LeadsController.php');

        self::assertStringContainsString("'do_not_call'", $controller);
        self::assertStringContainsString('DoNotCallDecision::LOST_REASON', $controller);
    }

    public function testButtonIsHiddenForLeadsAlreadyMarked(): void
    {
        $js = $this->source('public/assets/ecrm-view-leads.js');

        self::assertStringContainsString('if (l.do_not_call) return', $js);
    }
}
