<?php

/**
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Contract;

use EnergyCRM\Domain\Contract\ContractCode;
use PHPUnit\Framework\TestCase;

final class ContractCodeTest extends TestCase
{
    public function testSmallIdsArePaddedToFourDigits(): void
    {
        self::assertSame('APP-0001', ContractCode::forId(1));
        self::assertSame('APP-0042', ContractCode::forId(42));
        self::assertSame('APP-9999', ContractCode::forId(9999));
    }

    /** Past four digits the code grows rather than truncating. */
    public function testLargeIdsKeepEveryDigit(): void
    {
        self::assertSame('APP-10000', ContractCode::forId(10000));
        self::assertSame('APP-1234567', ContractCode::forId(1234567));
    }

    /** A failed insert returns zero; the code must still be well formed. */
    public function testZeroAndNegativeIdsDoNotProduceAMalformedCode(): void
    {
        self::assertSame('APP-0000', ContractCode::forId(0));
        self::assertSame('APP-0000', ContractCode::forId(-5));
    }

    /**
     * Five agents reading codes to each other on the phone need to know which
     * provider a code belongs to from the code alone — a flat APP- sequence
     * shared by every provider does not tell them that.
     */
    public function testTheProviderPrefixReplacesTheGenericOne(): void
    {
        self::assertSame('ORIZON-0035', ContractCode::forId(35, 'orizon'));
        self::assertSame('PROTERGIA-0012', ContractCode::forId(12, 'protergia'));
    }

    /** No provider on the row (e.g. a bare lead) falls back to the old scheme. */
    public function testNoProviderFallsBackToTheGenericPrefix(): void
    {
        self::assertSame('APP-0007', ContractCode::forId(7, ''));
    }

    /** The slug's own casing must not leak into the printed code. */
    public function testThePrefixIsAlwaysUppercase(): void
    {
        self::assertSame('VOLTON-0001', ContractCode::forId(1, 'volton'));
    }
}
