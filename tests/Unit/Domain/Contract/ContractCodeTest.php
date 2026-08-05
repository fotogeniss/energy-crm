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
}
