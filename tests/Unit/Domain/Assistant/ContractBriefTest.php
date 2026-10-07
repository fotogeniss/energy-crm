<?php

/**
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Assistant;

use EnergyCRM\Domain\Assistant\ContractBrief;
use PHPUnit\Framework\TestCase;

final class ContractBriefTest extends TestCase
{
    /**
     * Ο κανόνας του ιδιοκτήτη: τίποτα προσωπικό δεν φεύγει στο API, ακόμα κι
     * αν κάποιος το περάσει κατά λάθος.
     */
    public function testPersonalDataNeverReachesTheLine(): void
    {
        $line = ContractBrief::line([
            'code'          => 'ORIZON-0007166',
            'status'        => 'Οριστικοποίηση',
            'first_name'    => 'Γιώργος',
            'last_name'     => 'Παπαδόπουλος',
            'company_name'  => 'ΑΦΟΙ ΠΑΠΑΔΟΠΟΥΛΟΙ ΟΕ',
            'afm'           => '123456789',
            'adt'           => 'ΑΚ123456',
            'phone'         => '2101234567',
            'mobile'        => '6971234567',
            'email'         => 'x@example.com',
            'supply_number' => '11223344556',
            'signed_ip'     => '10.0.0.1',
            'extra_json'    => '{"combo_mobile_afm":"987654321"}',
        ]);

        $secrets = [
            'Γιώργος', 'Παπαδόπουλος', 'ΑΦΟΙ', '123456789', 'ΑΚ123456', '2101234567',
            '6971234567', 'example.com', '11223344556', '10.0.0.1', '987654321',
        ];

        foreach ($secrets as $secret) {
            self::assertStringNotContainsString($secret, $line);
        }

        self::assertStringContainsString('ORIZON-0007166', $line);
        self::assertStringContainsString('«Οριστικοποίηση»', $line);
    }

    public function testALineSaysWhatTheAssistantNeeds(): void
    {
        $line = ContractBrief::line([
            'code'      => 'PROTERGIA-0000042',
            'kind'      => 'Ρεύμα',
            'provider'  => 'Protergia',
            'program'   => 'Simple 2',
            'status'    => 'Presale',
            'idle_days' => 1,
            'created'   => '01/10/2026',
            'signed'    => false,
            'missing'   => ['Ταυτότητα', 'Λογαριασμός παρόχου'],
            'history'   => ['01/10 Πρόχειρο → Presale'],
        ]);

        self::assertSame(
            '- PROTERGIA-0000042 · Ρεύμα · Protergia Simple 2 · κατάσταση «Presale» χωρίς αλλαγή εδώ και 1 μέρα'
                . ' · καταχωρήθηκε 01/10/2026 · χωρίς υπογραφή πελάτη · λείπουν: Ταυτότητα, Λογαριασμός παρόχου'
                . ' · ιστορικό: 01/10 Πρόχειρο → Presale',
            $line
        );
    }

    public function testCodesAreFoundInWhateverCaseTheyAreTyped(): void
    {
        self::assertSame(
            ['ORIZON-7166', 'PROTERGIA-0000042'],
            ContractBrief::codesIn('τι γίνεται με την orizon-7166 και την PROTERGIA-0000042;')
        );
    }

    public function testAtMostThreeCodesAndNoDuplicates(): void
    {
        self::assertSame(['A-1', 'B-2', 'C-3'], ContractBrief::codesIn('A-1 a-1 B-2 C-3 D-4'));
    }

    public function testAQuestionWithoutCodesFindsNone(): void
    {
        self::assertSame([], ContractBrief::codesIn('πόσες αιτήσεις έχω σε εκκρεμότητα;'));
    }
}
