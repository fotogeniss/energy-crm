<?php

/**
 * Υποψήφιοι από το AI τηλεφωνικό κέντρο (448) — η καθαρή λογική, χωρίς βάση:
 * ποιος μπαίνει στην πόρτα, τι δεχόμαστε, τι φτάνει στη λίστα του πωλητή.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\GatewayIntake;

use EnergyCRM\GatewayIntake\Domain\GatewayAccess;
use EnergyCRM\GatewayIntake\Domain\GatewayLead;
use EnergyCRM\GatewayIntake\Domain\InvalidGatewayLead;
use EnergyCRM\SiteIntake\Domain\SiteSignature;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GatewayIntakeDomainTest extends TestCase
{
    private const REF = '0B7C9A52-3F1E-4D6A-9C2B-1A2B3C4D5E6F';

    private const SECRET = 'gateway-secret-για-δοκιμή-0123456789abcdef';

    /** @return array<string, mixed> */
    private static function outbound(array $over = []): array
    {
        return array_merge([
            'ref'              => self::REF,
            'name'             => 'Νίκος Παπαδόπουλος',
            'phone'            => '+306982774563',
            'energy_type'      => 'electricity',
            'interest'         => 'high',
            'customer_type'    => 'residential',
            'current_provider' => 'ΔΕΗ',
            'notes'            => 'Θέλει προσφορά για το σπίτι.',
            'direction'        => 'outbound',
            'transferred'      => true,
            'consent'          => [
                'id'          => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
                'text_id'     => 'v1',
                'given_at'    => '2026-11-23T09:00:00.000Z',
                'verified_at' => '2026-11-23T09:02:00.000Z',
            ],
        ], $over);
    }

    // --- Υπογραφή: ίδια με του Gateway (Node, signEcrm) ------------------------

    public function testTheGatewaySignsExactlyLikeTheCrmVerifies(): void
    {
        // Το διάνυσμα βγήκε από το `signEcrm()` του Gateway (src/security/signatures.ts).
        $body = '{"ref":"0b7c9a52-3f1e-4d6a-9c2b-1a2b3c4d5e6f"}';
        $sig  = 'df3e491b9aaa6e4250a24f5cde43b904bc5f2bd6cde3cc0e618858696ec7fb77';

        self::assertSame($sig, SiteSignature::sign(self::SECRET, 1795450000, $body));
        self::assertTrue(SiteSignature::verify(self::SECRET, '1795450000', $sig, $body, 1795450100));
    }

    // --- Η πόρτα ---------------------------------------------------------------

    public function testTheDoorIsClosedUntilEverythingIsSet(): void
    {
        $good = str_repeat('g', 32);
        $ips  = ['203.0.113.10'];

        self::assertSame('', GatewayAccess::closedBecause($good, 'site-secret', $ips));
        self::assertNotSame('', GatewayAccess::closedBecause('', 'site-secret', $ips));
        self::assertNotSame('', GatewayAccess::closedBecause(str_repeat('g', 31), 'site-secret', $ips));
        self::assertNotSame('', GatewayAccess::closedBecause($good, 'site-secret', []));
    }

    public function testTheGatewayCannotUseTheSiteSecret(): void
    {
        $shared = str_repeat('s', 40);

        self::assertStringContainsString(
            'ίδιο με του site',
            GatewayAccess::closedBecause($shared, $shared, ['127.0.0.1'])
        );
    }

    public function testOnlyListedAddressesPass(): void
    {
        $list = GatewayAccess::parseList(' 203.0.113.10 , 2001:db8::1, όχι-ip, ');

        self::assertSame(['203.0.113.10', '2001:db8::1'], $list);
        self::assertTrue(GatewayAccess::ipAllowed('203.0.113.10', $list));
        self::assertTrue(GatewayAccess::ipAllowed('2001:0db8:0000:0000:0000:0000:0000:0001', $list));
        self::assertFalse(GatewayAccess::ipAllowed('203.0.113.11', $list));
        self::assertFalse(GatewayAccess::ipAllowed('', $list));
        self::assertFalse(GatewayAccess::ipAllowed('203.0.113.10', []));
    }

    // --- Ο υποψήφιος -----------------------------------------------------------

    public function testAGoodOutboundLead(): void
    {
        $lead = GatewayLead::fromPayload(self::outbound());

        self::assertSame('gw-0b7c9a52-3f1e-4d6a-9c2b-1a2b3c4d5e6f', $lead->ref);
        self::assertSame('Νίκος Παπαδόπουλος', $lead->fullName);
        self::assertSame('outbound', $lead->direction);
        self::assertTrue($lead->transferred);
        self::assertSame(strtotime('2026-11-23T09:00:00Z'), $lead->consentGivenAt);
        self::assertSame('Κλήση AI · ενδιαφέρον υψηλό · Οικιακό · μεταφέρθηκε σε πωλητή', $lead->interest);
        self::assertStringContainsString('Πάροχος σήμερα: ΔΕΗ', $lead->notes);
        self::assertStringContainsString('κείμενο v1', $lead->notes);
    }

    public function testTheRefCannotMeetASiteRef(): void
    {
        self::assertStringStartsWith(GatewayLead::REF_PREFIX, GatewayLead::fromPayload(self::outbound())->ref);
    }

    /**
     * Ο κάτοχος του υποψηφίου είναι ο χρήστης του wp-config (`ECRM_GATEWAY_INTAKE_OWNER`),
     * ποτέ κάτι που έστειλε το Gateway.
     *
     * @kanonas K6
     */
    public function testTheGatewayCannotChooseTheOwner(): void
    {
        $lead = GatewayLead::fromPayload(self::outbound(['owner_id' => 99, 'user_id' => 99, 'assigned_to' => 99]));

        foreach (array_keys(get_object_vars($lead)) as $field) {
            self::assertDoesNotMatchRegularExpression('/owner|user|assign/i', $field);
        }
    }

    public function testAnInboundCallNeedsNoConsent(): void
    {
        $lead = GatewayLead::fromPayload(self::outbound(['direction' => 'inbound', 'consent' => null]));

        self::assertNull($lead->consentGivenAt);
        self::assertStringContainsString('Εισερχόμενη', $lead->notes);
    }

    public function testAMissingNameGetsALabelNotAnError(): void
    {
        foreach ([null, '12345'] as $name) {
            $lead = GatewayLead::fromPayload(self::outbound(['name' => $name]));
            self::assertSame('Χωρίς όνομα (κλήση AI)', $lead->fullName);
        }
    }

    public function testAfmAndIbanNeverReachTheNotes(): void
    {
        $lead = GatewayLead::fromPayload(self::outbound([
            'notes' => 'ΑΦΜ 123456789, IBAN GR1601101250000000012300695, θέλει 300 kWh.',
        ]));

        self::assertStringNotContainsString('123456789', $lead->notes);
        self::assertStringNotContainsString('GR16', $lead->notes);
        self::assertStringContainsString('300 kWh', $lead->notes);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function badPayloads(): array
    {
        $consent = self::outbound()['consent'];

        return [
            'χωρίς ref'                    => [self::outbound(['ref' => ''])],
            'ref όχι uuid'                 => [self::outbound(['ref' => 'abc12345'])],
            'χωρίς τηλέφωνο'               => [self::outbound(['phone' => ''])],
            'αέριο'                        => [self::outbound(['energy_type' => 'gas'])],
            'κατεύθυνση'                   => [self::outbound(['direction' => 'sideways'])],
            'ενδιαφέρον'                   => [self::outbound(['interest' => 'maybe'])],
            'τύπος πελάτη'                 => [self::outbound(['customer_type' => 'company'])],
            'εξερχόμενη χωρίς συγκατάθεση' => [self::outbound(['consent' => null])],
            'συγκατάθεση χωρίς επιβεβαίωση' => [
                self::outbound(['consent' => array_merge($consent, ['verified_at' => null])]),
            ],
            'επιβεβαίωση πριν τη συγκατάθεση' => [
                self::outbound(['consent' => array_merge($consent, ['verified_at' => '2026-11-22T09:00:00Z'])]),
            ],
            'id συγκατάθεσης'              => [self::outbound(['consent' => array_merge($consent, ['id' => 'x'])])],
        ];
    }

    #[DataProvider('badPayloads')]
    public function testABadLeadIsRefused(array $payload): void
    {
        $this->expectException(InvalidGatewayLead::class);

        GatewayLead::fromPayload($payload);
    }

    public function testTheInterestLineFitsTheColumn(): void
    {
        self::assertLessThanOrEqual(255, mb_strlen(GatewayLead::fromPayload(self::outbound())->interest));
    }
}
