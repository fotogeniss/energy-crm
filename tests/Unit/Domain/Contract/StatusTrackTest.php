<?php

/**
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Contract;

use EnergyCRM\Domain\Contract\MobileLine;
use EnergyCRM\Domain\Contract\StatusTrack;
use EnergyCRM\Domain\Contract\ContractStatus;
use PHPUnit\Framework\TestCase;

final class StatusTrackTest extends TestCase
{
    public function testEachEnergyTypeGetsItsList(): void
    {
        self::assertSame(StatusTrack::POWER, StatusTrack::of(['energy_type' => 'power', 'extra_json' => '']));
        self::assertSame(StatusTrack::GAS, StatusTrack::of(['energy_type' => 'gas', 'extra_json' => '']));
        self::assertSame(StatusTrack::MOBILE, StatusTrack::of(['energy_type' => 'mobile', 'extra_json' => '']));
    }

    /** COMBO με κινητή περνάει από SIM, οπότε παίρνει τη λίστα της κινητής. */
    public function testAComboWithAMobileLineFollowsTheMobileList(): void
    {
        $combo = ['energy_type' => 'power', 'extra_json' => '{"combo_mobile_offer":"single"}'];

        self::assertSame(StatusTrack::MOBILE, StatusTrack::of($combo));
    }

    public function testSigningMovesEachListToItsOwnNextStep(): void
    {
        self::assertSame(
            ContractStatus::ToFinalisation,
            MobileLine::stageAfterSignature(['energy_type' => 'power', 'extra_json' => ''])
        );
        self::assertSame(
            ContractStatus::SignatureComplete,
            MobileLine::stageAfterSignature(['energy_type' => 'mobile', 'extra_json' => ''])
        );
    }
}
