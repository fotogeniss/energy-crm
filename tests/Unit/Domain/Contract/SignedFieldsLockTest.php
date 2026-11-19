<?php

/**
 * (415) Μετά την υπογραφή του πελάτη: πάροχος, πρόγραμμα, είδος, πελάτης και
 * ΑΦΜ δεν αλλάζουν. Η ίδια τιμή ξανά και το κενό που γεμίζει περνούν.
 *
 * @kanonas K3
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Contract;

use EnergyCRM\Domain\Contract\SignedFieldsLock;
use PHPUnit\Framework\TestCase;

final class SignedFieldsLockTest extends TestCase
{
    private const SIGNED = [
        'signed_at'   => '2026-11-10 12:00:00',
        'provider_id' => '3',
        'program_id'  => '17',
        'energy_type' => 'power',
        'customer_id' => '40',
    ];

    public function testUnsignedContractsChangeFreely(): void
    {
        $unsigned = ['signed_at' => null] + self::SIGNED;

        self::assertNull(SignedFieldsLock::refusal($unsigned, ['program_id' => 99], '090000045', '094014201'));
    }

    public function testTheSameValuesResentAreNotAChange(): void
    {
        $patch = ['provider_id' => 3, 'program_id' => 17, 'energy_type' => 'power', 'customer_id' => 40];

        self::assertNull(SignedFieldsLock::refusal(self::SIGNED, $patch, '090000045', '090000045'));
    }

    public function testEachLockedFieldRefuses(): void
    {
        foreach (['provider_id' => 4, 'program_id' => 18, 'energy_type' => 'gas', 'customer_id' => 41] as $col => $v) {
            $refusal = SignedFieldsLock::refusal(self::SIGNED, [$col => $v], '', null);

            self::assertNotNull($refusal, $col);
            self::assertSame($col, $refusal['field']);
            self::assertStringContainsString('έχει υπογραφεί', $refusal['error']);
        }
    }

    public function testAnotherAfmRefusesButFillingAnEmptyOneDoesNot(): void
    {
        self::assertSame('afm', SignedFieldsLock::refusal(self::SIGNED, [], '090000045', '094014201')['field'] ?? null);
        self::assertNull(SignedFieldsLock::refusal(self::SIGNED, [], '', '094014201'));
        self::assertNull(SignedFieldsLock::refusal(self::SIGNED, [], '090000045', null), 'Δεν στάλθηκε ΑΦΜ.');
        self::assertFalse(SignedFieldsLock::afmChanged('090000045', '090000045'));
    }

    public function testFillingAnEmptyProgramIsNotAChange(): void
    {
        $noProgram = ['program_id' => null] + self::SIGNED;

        self::assertNull(SignedFieldsLock::refusal($noProgram, ['program_id' => 17], '', null));
    }

    public function testUnrelatedFieldsStillSave(): void
    {
        $patch = ['notes' => 'νέα σημείωση', 'supply_number' => '1'];

        self::assertNull(SignedFieldsLock::refusal(self::SIGNED, $patch, '', null));
    }
    /** (428) Τυπωμένα πεδία: αλλαγή, σβήσιμο και γέμισμα κενού μετρούν· η ίδια τιμή όχι. */
    public function testPrintedFieldsAreStrict(): void
    {
        $before  = ['street' => 'Ερμού', 'guarantee' => '120', 'phone' => ''];
        $printed = ['street', 'guarantee', 'phone'];

        $same = ['street' => ' Ερμού ', 'guarantee' => '120'];

        self::assertNull(SignedFieldsLock::printedChange($before, $same, $printed));
        self::assertNull(SignedFieldsLock::printedChange($before, ['notes' => 'άλλο'], $printed));
        self::assertSame('street', SignedFieldsLock::printedChange($before, ['street' => 'Σταδίου'], $printed));
        self::assertSame('guarantee', SignedFieldsLock::printedChange($before, ['guarantee' => ''], $printed));
        self::assertSame('phone', SignedFieldsLock::printedChange($before, ['phone' => '2101234567'], $printed));
    }

    public function testThePrintedRefusalNamesTheField(): void
    {
        $refusal = SignedFieldsLock::printedRefusal('street');

        self::assertSame('street', $refusal['field']);
        self::assertStringContainsString('η διεύθυνση τυπώνεται', $refusal['error']);
        self::assertStringContainsString('αυτό το στοιχείο', SignedFieldsLock::printedRefusal('kad')['error']);
    }
}
