<?php

/**
 * (421) Ο σάκος `extra` σε επεξεργασία: μένει ό,τι υπάρχει, υπερισχύει ό,τι
 * στάλθηκε, φεύγει ό,τι στάλθηκε κενό.
 *
 * @kanonas K7
 * @kanonas K10
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Contract;

use EnergyCRM\Domain\Contract\ExtraFields;
use PHPUnit\Framework\TestCase;

final class ExtraFieldsMergeTest extends TestCase
{
    public function testWhatTheScreenDidNotSendStays(): void
    {
        $merged = ExtraFields::merge(['agreed_power' => '8', 'sim_number' => '8930'], ['agreed_power' => '12']);

        self::assertSame(['agreed_power' => '12', 'sim_number' => '8930'], $merged);
    }

    public function testWhatWasSentEmptyGoes(): void
    {
        $merged = ExtraFields::merge(['agreed_power' => '8', 'kad' => '35.14'], ['kad' => '', 'gemi' => '123']);

        self::assertSame(['agreed_power' => '8', 'gemi' => '123'], $merged);
    }

    public function testNothingStoredIsJustWhatWasSent(): void
    {
        self::assertSame(['a' => '1'], ExtraFields::merge([], ['a' => '1', 'b' => ' ']));
    }

    public function testTheKeyIsTheOneThatIsStored(): void
    {
        $merged = ExtraFields::merge(['agreed_power' => '8', 'kad' => '1'], ['Kad' => '']);

        self::assertSame(['agreed_power' => '8'], $merged);
        self::assertSame([], ExtraFields::merge([], ['!!' => 'x']));
    }
}
