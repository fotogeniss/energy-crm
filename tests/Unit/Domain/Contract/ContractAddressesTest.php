<?php

/**
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Contract;

use EnergyCRM\Domain\Contract\ContractAddresses;
use PHPUnit\Framework\TestCase;

final class ContractAddressesTest extends TestCase
{
    /** @return array<string, mixed> */
    private function home(): array
    {
        return [
            'street'      => 'ΣΟΛΩΜΟΥ',
            'street_no'   => '15',
            'city'        => 'ΤΡΙΚΑΛΑ',
            'postal_code' => '42100',
            'region'      => 'ΤΡΙΚΑΛΩΝ',
        ];
    }

    /** Every contract written before these columns existed meant "the same". */
    public function testARowWithoutTheNewColumnsRepeatsTheHomeAddress(): void
    {
        $addresses = ContractAddresses::from($this->home());

        self::assertSame('ΣΟΛΩΜΟΥ', $addresses->supply->street);
        self::assertSame('ΣΟΛΩΜΟΥ', $addresses->billing->street);
    }

    public function testAFlaggedDifferentSupplyAddressIsKeptApart(): void
    {
        $addresses = ContractAddresses::from($this->home() + [
            'supply_addr_same'   => 0,
            'supply_street'      => 'ΑΣΚΛΗΠΙΟΥ',
            'supply_street_no'   => '3',
            'supply_city'        => 'ΚΑΛΑΜΠΑΚΑ',
            'supply_postal_code' => '42200',
            'supply_region'      => 'ΤΡΙΚΑΛΩΝ',
        ]);

        self::assertSame('ΑΣΚΛΗΠΙΟΥ', $addresses->supply->street);
        self::assertSame('ΚΑΛΑΜΠΑΚΑ', $addresses->supply->city);
        // The other two must not move with it.
        self::assertSame('ΣΟΛΩΜΟΥ', $addresses->home->street);
        self::assertSame('ΣΟΛΩΜΟΥ', $addresses->billing->street);
    }

    public function testTheBillingAddressIsIndependentOfTheSupplyAddress(): void
    {
        $addresses = ContractAddresses::from($this->home() + [
            'supply_addr_same'    => 0,
            'supply_street'       => 'ΑΣΚΛΗΠΙΟΥ',
            'billing_addr_same'   => 0,
            'billing_street'      => 'ΠΑΤΗΣΙΩΝ',
            'billing_city'        => 'ΑΘΗΝΑ',
        ]);

        self::assertSame('ΑΣΚΛΗΠΙΟΥ', $addresses->supply->street);
        self::assertSame('ΠΑΤΗΣΙΩΝ', $addresses->billing->street);
    }

    /**
     * A half-finished form: the agent unticked "same" and then never filled the
     * fields. A blank mandatory box on a provider application is worse than the
     * home address, which is what they would have written by hand.
     */
    public function testUntickedButEmptyFallsBackToHomeRatherThanPrintingNothing(): void
    {
        $addresses = ContractAddresses::from($this->home() + [
            'supply_addr_same' => 0,
            'supply_street'    => '',
            'supply_city'      => '',
        ]);

        self::assertSame('ΣΟΛΩΜΟΥ', $addresses->supply->street);
    }

    /** Whitespace is not an address. */
    public function testAnAddressOfOnlySpacesCountsAsEmpty(): void
    {
        $addresses = ContractAddresses::from($this->home() + [
            'billing_addr_same' => 0,
            'billing_street'    => '   ',
        ]);

        self::assertSame('ΣΟΛΩΜΟΥ', $addresses->billing->street);
    }

    public function testTickedSameIgnoresWhateverIsStoredInTheColumns(): void
    {
        $addresses = ContractAddresses::from($this->home() + [
            'supply_addr_same' => 1,
            // Left over from before the agent ticked the box.
            'supply_street'    => 'ΠΑΛΙΑ ΟΔΟΣ',
        ]);

        self::assertSame('ΣΟΛΩΜΟΥ', $addresses->supply->street);
    }

    public function testTheOneLineFormIsWhatTheSingleBoxFormsPrint(): void
    {
        self::assertSame(
            'ΣΟΛΩΜΟΥ 15, 42100 ΤΡΙΚΑΛΑ',
            ContractAddresses::from($this->home())->home->oneLine()
        );
    }

    /** A missing part must not leave a stray comma behind. */
    public function testAnAddressWithoutATownPrintsNoTrailingSeparator(): void
    {
        $addresses = ContractAddresses::from([
            'street'    => 'ΣΟΛΩΜΟΥ',
            'street_no' => '15',
        ]);

        self::assertSame('ΣΟΛΩΜΟΥ 15', $addresses->home->oneLine());
    }
}
