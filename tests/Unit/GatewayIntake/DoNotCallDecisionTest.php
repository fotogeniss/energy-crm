<?php

/**
 * «Δεν θέλει κλήσεις» (449): τι γίνεται με την κατάσταση του lead.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\GatewayIntake;

use EnergyCRM\GatewayIntake\Domain\DoNotCallDecision;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DoNotCallDecisionTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function openStages(): array
    {
        return [
            'νέο'         => ['new'],
            'επικοινωνία' => ['contacted'],
            'επανάκληση'  => ['callback'],
            'έτοιμο'      => ['qualified'],
            'χαμένο'      => ['lost'],
            'άγνωστο'     => [''],
        ];
    }

    #[DataProvider('openStages')]
    public function testAnyOpenLeadIsClosedAsLostWithAReason(string $stage): void
    {
        self::assertSame(
            ['stage' => 'lost', 'lost_reason' => 'Δεν θέλει κλήσεις'],
            DoNotCallDecision::leadChanges($stage)
        );
    }

    public function testAWonLeadIsLeftAlone(): void
    {
        self::assertSame([], DoNotCallDecision::leadChanges('won'));
    }
}
