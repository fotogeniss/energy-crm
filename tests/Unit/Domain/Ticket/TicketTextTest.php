<?php

/**
 * (339) Ό,τι γράφει ο χρήστης σε μια αναφορά, πριν φτάσει στη βάση.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Ticket;

use EnergyCRM\Domain\Ticket\TicketContext;
use EnergyCRM\Domain\Ticket\TicketText;
use PHPUnit\Framework\TestCase;

final class TicketTextTest extends TestCase
{
    public function testPersonalNumbersAreHiddenButContractNumbersStay(): void
    {
        $out = TicketText::clean(
            'Ο πελάτης με ΑΦΜ 123456789, κινητό 6912345678 και σταθερό +30 210 123 4567, '
            . 'mail giorgos@example.gr, IBAN GR16 0110 1250 0000 0001 2300 695. '
            . 'Παροχή 12345678901, αίτηση EC-2026-0412.'
        );

        self::assertStringNotContainsString('123456789,', $out);
        self::assertStringNotContainsString('6912345678', $out);
        self::assertStringNotContainsString('210 123 4567', $out);
        self::assertStringNotContainsString('giorgos@', $out);
        self::assertStringNotContainsString('0110 1250', $out);
        self::assertStringContainsString('[ΑΦΜ]', $out);
        self::assertStringContainsString('[τηλέφωνο]', $out);
        self::assertStringContainsString('[email]', $out);
        self::assertStringContainsString('[IBAN]', $out);
        // Δείχνουν σύμβαση, όχι πρόσωπο -- και είναι αυτό που ψάχνει όποιος διορθώνει.
        self::assertStringContainsString('12345678901', $out);
        self::assertStringContainsString('EC-2026-0412', $out);
    }

    public function testControlCharactersGoNewlinesStayAndLengthIsCapped(): void
    {
        self::assertSame("α\nβ", TicketText::clean("  α\x07\r\nβ  "));
        self::assertSame(TicketText::MAX_LENGTH, mb_strlen(TicketText::clean(str_repeat('ά', 5000)), 'UTF-8'));
    }

    public function testTheContextKeepsOnlyKnownKeysAndCleansTheErrors(): void
    {
        $out = TicketContext::clean([
            'view'          => 'contracts',
            'contract_id'   => '412',
            'contract_code' => 'EC-2026-0412',
            'device'        => str_repeat('x', 500),
            'password'      => 'secret',
            'errors'        => array_merge(
                ['TypeError: x is undefined (ecrm-sign.js:214) για 6912345678'],
                array_fill(0, 20, str_repeat('e', 400)),
                [['nested']]
            ),
        ]);

        self::assertArrayNotHasKey('password', $out);
        self::assertSame(412, $out['contract_id']);
        self::assertSame(120, mb_strlen($out['device'], 'UTF-8'));
        self::assertCount(TicketContext::MAX_ERRORS, $out['errors']);
        self::assertSame('TypeError: x is undefined (ecrm-sign.js:214) για [τηλέφωνο]', $out['errors'][0]);
        self::assertSame(TicketContext::ERROR_LENGTH, mb_strlen($out['errors'][1], 'UTF-8'));
        self::assertSame([], TicketContext::clean(['contract_id' => 0, 'view' => '  ']));
    }
}
