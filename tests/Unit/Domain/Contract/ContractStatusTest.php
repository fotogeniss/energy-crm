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
        self::assertSame('pending_signature', ContractStatus::PendingSignature->value);
        self::assertSame('new', ContractStatus::Submitted->value);
    }

    public function testEveryStatusHasALabel(): void
    {
        foreach (ContractStatus::cases() as $status) {
            self::assertNotSame('', $status->label(), $status->value . ' has no label');
        }
    }

    /**
     * The whole point of the exercise: a cancelled contract is finished, and
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
            'cancelled'  => [ContractStatus::Cancelled],
            'terminated' => [ContractStatus::Terminated],
        ];
    }

    public function testASignedContractCannotGoBackBeforeItsSignature(): void
    {
        $signed = ContractStatus::Signed;

        self::assertFalse($signed->canMoveTo(ContractStatus::Draft));
        self::assertFalse($signed->canMoveTo(ContractStatus::Submitted));
        self::assertFalse($signed->canMoveTo(ContractStatus::PendingSignature));
        self::assertFalse($signed->canMoveTo(ContractStatus::AwaitingSignature));
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

    public function testAnActiveSupplyCannotBeRewoundIntoProcessing(): void
    {
        self::assertFalse(ContractStatus::Active->canMoveTo(ContractStatus::Processing));
        self::assertTrue(ContractStatus::Active->canMoveTo(ContractStatus::Terminated));
    }

    /** The cron that advances signed contracts depends on this edge existing. */
    public function testSignedAdvancesToProcessing(): void
    {
        self::assertTrue(ContractStatus::Signed->canMoveTo(ContractStatus::Processing));
    }

    public function testStayingPutIsAllowed(): void
    {
        foreach (ContractStatus::cases() as $status) {
            self::assertTrue($status->canMoveTo($status), $status->value . ' cannot stay put');
        }
    }

    public function testEveryNonTerminalStatusCanBeCancelled(): void
    {
        foreach (ContractStatus::cases() as $status) {
            if ($status->isTerminal() || $status === ContractStatus::Active) {
                continue;
            }

            self::assertTrue(
                $status->canMoveTo(ContractStatus::Cancelled),
                $status->value . ' cannot be cancelled'
            );
        }
    }

    public function testPayableStatusesAreTheOnesCommissionIsOwedOn(): void
    {
        self::assertTrue(ContractStatus::Routed->isPayable());
        self::assertTrue(ContractStatus::Active->isPayable());
        self::assertTrue(ContractStatus::Resolved->isPayable());

        self::assertFalse(ContractStatus::Draft->isPayable());
        self::assertFalse(ContractStatus::Cancelled->isPayable());
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
