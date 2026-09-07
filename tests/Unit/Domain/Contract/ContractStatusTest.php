<?php

/**
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Contract;

use EnergyCRM\Domain\Contract\ContractStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContractStatusTest extends TestCase
{
    public function testTheSlugsMatchWhatIsStoredInTheDatabase(): void
    {
        self::assertSame('awaiting_signature', ContractStatus::AwaitingSignature->value);
        self::assertSame('presale', ContractStatus::Presale->value);
        self::assertSame('terminated', ContractStatus::Terminated->value);
    }

    public function testEveryStatusHasALabel(): void
    {
        foreach (ContractStatus::cases() as $status) {
            self::assertNotSame('', $status->label(), $status->value . ' has no label');
        }
    }

    /**
     * The whole point of the exercise: a terminal contract is finished, and
     * reviving one would rewrite commercial history that has already been
     * reported and paid on.
     */
    #[DataProvider('terminalStatuses')]
    public function testNothingLeavesATerminalStatus(ContractStatus $terminal): void
    {
        self::assertTrue($terminal->isTerminal());
        self::assertSame([], $terminal->allowedNext());

        foreach (ContractStatus::cases() as $target) {
            if ($target === $terminal) {
                continue;
            }

            self::assertFalse(
                $terminal->canMoveTo($target),
                $terminal->value . ' must not move to ' . $target->value
            );
        }
    }

    /** @return array<string, array{0: ContractStatus}> */
    public static function terminalStatuses(): array
    {
        return [
            'terminated'             => [ContractStatus::Terminated],
            'cancelled_by_us'        => [ContractStatus::CancelledByUs],
            'cancelled_by_customer'  => [ContractStatus::CancelledByCustomer],
        ];
    }

    /**
     * 2026-08-24: όχι πια απαγορευμένο, σκόπιμα — η δεύτερη υπογραφή είναι
     * γνήσια ανάγκη (λάθος που φάνηκε μετά, ή ο πάροχος γύρισε πίσω την
     * αίτηση). Ο ίδιος ο πίνακας πλέον επιτρέπει Finalisation ->
     * AwaitingSignature και AwaitingSim -> AwaitingSignature (κανόνας Κ7,
     * "Πίσω" στο ContractStatus::allowedNext()) — η μόνη προστασία από ένα
     * τυχαίο κλικ είναι στο SignLinkController::create() (confirm_resend),
     * όχι εδώ. Ο γράφος λέει μόνο ποια μετάβαση είναι δομικά νόμιμη, όχι
     * πότε επιτρέπεται να συμβεί.
     */
    public function testFinalisationCanReturnForANewSignature(): void
    {
        self::assertTrue(ContractStatus::Finalisation->canMoveTo(ContractStatus::AwaitingSignature));
    }

    /** @see testFinalisationCanReturnForANewSignature */
    public function testAwaitingSimCanReturnForANewSignature(): void
    {
        self::assertTrue(ContractStatus::AwaitingSim->canMoveTo(ContractStatus::AwaitingSignature));
    }

    public function testNoStatusEverReturnsToDraft(): void
    {
        foreach (ContractStatus::cases() as $status) {
            if ($status === ContractStatus::Draft) {
                continue;
            }

            self::assertFalse(
                $status->canMoveTo(ContractStatus::Draft),
                $status->value . ' must not return to draft'
            );
        }
    }

    public function testAnActiveSupplyCannotBeRewoundIntoRegistration(): void
    {
        self::assertFalse(ContractStatus::Active->canMoveTo(ContractStatus::Registration));
        self::assertTrue(ContractStatus::Active->canMoveTo(ContractStatus::Terminated));
    }

    public function testEveryNonTerminalStatusExceptActiveCanBeCancelled(): void
    {
        foreach (ContractStatus::cases() as $status) {
            if ($status->isTerminal() || $status === ContractStatus::Active) {
                continue;
            }

            self::assertTrue(
                $status->canMoveTo(ContractStatus::CancelledByUs),
                $status->value . ' cannot be cancelled by us'
            );
            self::assertTrue(
                $status->canMoveTo(ContractStatus::CancelledByCustomer),
                $status->value . ' cannot be cancelled by the customer'
            );
        }
    }

    /**
     * Η ΕΝΕΡΓΟΣ δεν ακυρώνεται -- διακόπτεται. Το allowedNext() το κλείνει
     * ήδη ρητά (καμία ακύρωση στη λίστα), εδώ επιβεβαιώνεται ονομαστικά
     * γιατί είναι ο κανόνας που φυλάει χρήματα ήδη κερδισμένα.
     */
    public function testActiveCannotBeCancelledOnlyTerminated(): void
    {
        self::assertFalse(ContractStatus::Active->canMoveTo(ContractStatus::CancelledByUs));
        self::assertFalse(ContractStatus::Active->canMoveTo(ContractStatus::CancelledByCustomer));
        self::assertTrue(ContractStatus::Active->canMoveTo(ContractStatus::Terminated));
    }

    public function testPayableStatusesAreTheOnesCommissionIsOwedOn(): void
    {
        self::assertTrue(ContractStatus::Active->isPayable());

        self::assertFalse(ContractStatus::Draft->isPayable());
        self::assertFalse(ContractStatus::Finalisation->isPayable());
        self::assertFalse(ContractStatus::Terminated->isPayable());
        self::assertFalse(ContractStatus::CancelledByUs->isPayable());
        self::assertFalse(ContractStatus::CancelledByCustomer->isPayable());
    }

    public function testIsCancellationCoversBothCancellationsOnly(): void
    {
        foreach (ContractStatus::cases() as $status) {
            $expected = $status === ContractStatus::CancelledByUs
                || $status === ContractStatus::CancelledByCustomer;

            self::assertSame($expected, $status->isCancellation(), $status->value);
        }
    }

    public function testExitsActiveIsTrueOnlyWhenLeavingActive(): void
    {
        self::assertTrue(ContractStatus::exitsActive(ContractStatus::Active, ContractStatus::Terminated));
        self::assertTrue(ContractStatus::exitsActive(ContractStatus::Active, ContractStatus::Finalisation));
        self::assertFalse(ContractStatus::exitsActive(ContractStatus::Active, ContractStatus::Active));
        self::assertFalse(ContractStatus::exitsActive(ContractStatus::Finalisation, ContractStatus::Active));
    }

    public function testAnUnknownSlugResolvesToNothing(): void
    {
        self::assertNull(ContractStatus::tryFromSlug('nonsense'));
        self::assertNull(ContractStatus::tryFromSlug(null));
        self::assertSame(ContractStatus::Active, ContractStatus::tryFromSlug('active'));
    }

    public function testLabelsCoverEveryCase(): void
    {
        self::assertCount(count(ContractStatus::cases()), ContractStatus::labels());
    }
}
