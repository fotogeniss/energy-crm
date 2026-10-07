<?php

/**
 * Το μοντέλο καταστάσεων της 05/10/2026: δύο λίστες (ρεύμα/αέριο, κινητή),
 * ελεύθερη κίνηση στις ενδιάμεσες, μία «Ακυρώθηκε».
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Contract;

use EnergyCRM\Domain\Contract\ContractStatus;
use EnergyCRM\Domain\Contract\StatusTrack;
use PHPUnit\Framework\TestCase;

final class ContractStatusTest extends TestCase
{
    public function testTheSlugsMatchWhatIsStoredInTheDatabase(): void
    {
        self::assertSame('awaiting_signature', ContractStatus::AwaitingSignature->value);
        self::assertSame('presale', ContractStatus::Presale->value);
        self::assertSame('awaiting_sim', ContractStatus::AwaitingSim->value);
        self::assertSame('cancelled', ContractStatus::Cancelled->value);
    }

    public function testEveryStatusHasALabel(): void
    {
        foreach (ContractStatus::cases() as $status) {
            self::assertNotSame('', $status->label(), $status->value . ' has no label');
        }
    }

    /** Η λίστα του ρεύματος, με τη σειρά του χαρτιού του ιδιοκτήτη. */
    public function testThePowerListIsThePaperOne(): void
    {
        self::assertSame(
            [
                'Πρόχειρο', 'Presale', 'Καταχώρηση', 'Αναμονή υπογραφής', 'Προς οριστικοποίηση',
                'Οριστικοποίηση', 'Επιβεβαίωση ΘΑΛΗΣ', 'Απόρριψη ΘΑΛΗΣ', 'Εκκρεμότητα',
                'Επανέλεγχος', 'Ενεργός', 'Ακυρώθηκε',
            ],
            self::labelsOf(StatusTrack::POWER)
        );
    }

    public function testGasIsPowerWithoutThalis(): void
    {
        $power = self::labelsOf(StatusTrack::POWER);
        $gas   = self::labelsOf(StatusTrack::GAS);

        self::assertSame(
            array_values(array_diff($power, ['Επιβεβαίωση ΘΑΛΗΣ', 'Απόρριψη ΘΑΛΗΣ'])),
            $gas
        );
    }

    /** Η λίστα της κινητής Orizon, με τη σειρά του χαρτιού. */
    public function testTheMobileListIsThePaperOne(): void
    {
        self::assertSame(
            [
                'Πρόχειρο', 'Presale', 'Καταχώρηση', 'Αναμονή υπογραφής', 'Ολοκλήρωση υπογραφής',
                'Αναμονή παράδοσης SIM', 'Παράδοση SIM', 'Οριστικοποίηση', 'Εκκρεμότητα',
                'Οφειλή', 'Ενεργός', 'For review', 'MP reject', 'Ακυρώθηκε',
            ],
            self::labelsOf(StatusTrack::MOBILE)
        );
    }

    public function testNothingLeavesCancelled(): void
    {
        self::assertTrue(ContractStatus::Cancelled->isTerminal());
        self::assertSame([], ContractStatus::Cancelled->allowedNext());
    }

    public function testCancelledIsTheOnlyTerminalAndTheOnlyCancellation(): void
    {
        foreach (ContractStatus::cases() as $status) {
            $expected = $status === ContractStatus::Cancelled;

            self::assertSame($expected, $status->isTerminal(), $status->value);
            self::assertSame($expected, $status->isCancellation(), $status->value);
        }
    }

    public function testNoStatusEverReturnsToDraft(): void
    {
        foreach (ContractStatus::cases() as $status) {
            self::assertFalse(
                $status->canMoveTo(ContractStatus::Draft),
                $status->value . ' must not return to draft'
            );
        }
    }

    public function testADraftCanOnlyBeSubmittedOrCancelled(): void
    {
        self::assertSame(
            [ContractStatus::Presale, ContractStatus::Cancelled],
            ContractStatus::Draft->allowedNext()
        );
    }

    /** Επιλογή του ιδιοκτήτη: ελεύθερα ανάμεσα στις ενδιάμεσες. */
    public function testEveryIntermediateReachesEveryOtherAndActiveAndCancelled(): void
    {
        foreach (ContractStatus::cases() as $from) {
            if (! $from->isIntermediate()) {
                continue;
            }

            foreach (ContractStatus::cases() as $to) {
                if ($to === $from || $to === ContractStatus::Draft) {
                    continue;
                }

                self::assertTrue($from->canMoveTo($to), $from->value . ' -> ' . $to->value);
            }
        }
    }

    /** Η παλιά Διακοπή: μια Ενεργός ακυρώνεται, και διορθώνεται προς τα πίσω. */
    public function testActiveCanBeCancelledOrCorrected(): void
    {
        self::assertTrue(ContractStatus::Active->canMoveTo(ContractStatus::Cancelled));
        self::assertTrue(ContractStatus::Active->canMoveTo(ContractStatus::Finalisation));
        self::assertTrue(ContractStatus::exitsActive(ContractStatus::Active, ContractStatus::Cancelled));
    }

    public function testTheTrackFiltersTheNextSteps(): void
    {
        $power = ContractStatus::Finalisation->allowedNextFor(StatusTrack::POWER);

        self::assertContains(ContractStatus::ThalisConfirmed, $power);
        self::assertNotContains(ContractStatus::SimDelivered, $power);

        $mobile = ContractStatus::Finalisation->allowedNextFor(StatusTrack::MOBILE);

        self::assertContains(ContractStatus::SimDelivered, $mobile);
        self::assertNotContains(ContractStatus::ThalisConfirmed, $mobile);
    }

    public function testOnlyActiveIsPayable(): void
    {
        foreach (ContractStatus::cases() as $status) {
            self::assertSame($status === ContractStatus::Active, $status->isPayable(), $status->value);
        }
    }

    public function testExitsActiveIsTrueOnlyWhenLeavingActive(): void
    {
        self::assertTrue(ContractStatus::exitsActive(ContractStatus::Active, ContractStatus::Finalisation));
        self::assertFalse(ContractStatus::exitsActive(ContractStatus::Active, ContractStatus::Active));
        self::assertFalse(ContractStatus::exitsActive(ContractStatus::Finalisation, ContractStatus::Active));
    }

    public function testAnUnknownSlugResolvesToNothing(): void
    {
        self::assertNull(ContractStatus::tryFromSlug('nonsense'));
        self::assertNull(ContractStatus::tryFromSlug('terminated'));
        self::assertNull(ContractStatus::tryFromSlug(null));
        self::assertSame(ContractStatus::Active, ContractStatus::tryFromSlug('active'));
    }

    public function testLabelsCoverEveryCase(): void
    {
        self::assertCount(count(ContractStatus::cases()), ContractStatus::labels());
    }

    /** @return list<string> */
    private static function labelsOf(string $track): array
    {
        return array_map(
            static fn (ContractStatus $s): string => $s->label(),
            ContractStatus::forTrack($track)
        );
    }
}
