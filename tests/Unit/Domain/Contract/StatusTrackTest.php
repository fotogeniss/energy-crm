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

    /** (403) Η κατάσταση άλλου είδους απορρίπτεται με μήνυμα, όχι σιωπηλά. */
    public function testAStatusOfAnotherTrackIsRefusedWithAReason(): void
    {
        self::assertSame(
            'Η κατάσταση «Αναμονή παράδοσης SIM» δεν ισχύει για αιτήσεις ρεύματος.',
            StatusTrack::refusal(ContractStatus::AwaitingSim, StatusTrack::POWER)
        );
        self::assertSame(
            'Η κατάσταση «Επιβεβαίωση ΘΑΛΗΣ» δεν ισχύει για αιτήσεις κινητής τηλεφωνίας.',
            StatusTrack::refusal(ContractStatus::ThalisConfirmed, StatusTrack::MOBILE)
        );
    }

    /** (403) Ό,τι ισχύει για το είδος περνά, και οι κοινές καταστάσεις παντού. */
    public function testAStatusOfTheSameTrackPasses(): void
    {
        self::assertNull(StatusTrack::refusal(ContractStatus::AwaitingSim, StatusTrack::MOBILE));
        self::assertNull(StatusTrack::refusal(ContractStatus::ThalisConfirmed, StatusTrack::POWER));

        foreach ([StatusTrack::POWER, StatusTrack::GAS, StatusTrack::MOBILE] as $track) {
            self::assertNull(StatusTrack::refusal(ContractStatus::Presale, $track));
            self::assertNull(StatusTrack::refusal(ContractStatus::Cancelled, $track));
        }
    }
}
