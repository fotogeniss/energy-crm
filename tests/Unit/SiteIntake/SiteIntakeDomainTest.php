<?php

/**
 * Αιτήσεις από το site σύγκρισης τιμών (310) — η καθαρή λογική, χωρίς βάση:
 * υπογραφή, έλεγχος αίτησης, είδη εγγράφων, τι γεμίζει η «Μετατροπή».
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\SiteIntake;

use EnergyCRM\SiteIntake\Domain\InvalidSiteApplication;
use EnergyCRM\SiteIntake\Domain\SiteApplication;
use EnergyCRM\SiteIntake\Domain\SiteDocumentKind;
use EnergyCRM\SiteIntake\Domain\SiteLeadPrefill;
use EnergyCRM\SiteIntake\Domain\SiteSignature;
use PHPUnit\Framework\TestCase;

final class SiteIntakeDomainTest extends TestCase
{
    private const SECRET = 'κοινό-μυστικό-για-δοκιμή';

    // --- Υπογραφή --------------------------------------------------------------

    public function testAGoodSignaturePasses(): void
    {
        $body = '{"ref":"abc12345"}';
        $sig  = SiteSignature::sign(self::SECRET, 1000, $body);

        self::assertTrue(SiteSignature::verify(self::SECRET, '1000', $sig, $body, 1000));
        self::assertTrue(SiteSignature::verify(self::SECRET, '1000', strtoupper($sig), $body, 1100));
    }

    public function testAChangedBodyFails(): void
    {
        $sig = SiteSignature::sign(self::SECRET, 1000, '{"ref":"abc12345"}');

        self::assertFalse(SiteSignature::verify(self::SECRET, '1000', $sig, '{"ref":"abc12346"}', 1000));
    }

    public function testAnOldRequestFails(): void
    {
        $body = '{}';
        $sig  = SiteSignature::sign(self::SECRET, 1000, $body);

        $late  = 1000 + SiteSignature::MAX_SKEW_SECONDS + 1;
        $early = 1000 - SiteSignature::MAX_SKEW_SECONDS - 1;

        self::assertFalse(SiteSignature::verify(self::SECRET, '1000', $sig, $body, $late));
        self::assertFalse(SiteSignature::verify(self::SECRET, '1000', $sig, $body, $early));
    }

    public function testWithoutASecretNothingPasses(): void
    {
        $sig = SiteSignature::sign('', 1000, '{}');

        self::assertFalse(SiteSignature::verify('', '1000', $sig, '{}', 1000), 'Κενό μυστικό = κλειστή πόρτα.');
        self::assertFalse(SiteSignature::verify(self::SECRET, '', $sig, '{}', 1000));
        self::assertFalse(SiteSignature::verify(self::SECRET, '10a0', $sig, '{}', 1000));
        self::assertFalse(SiteSignature::verify(self::SECRET, '1000', 'κοντή', '{}', 1000));
    }

    // --- Η αίτηση ---------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function payload(array $over = []): array
    {
        return $over + [
            'ref'              => 'app-20261013-0001',
            'full_name'        => 'Μαρία Παπαδοπούλου',
            'phone'            => '6912345678',
            'email'            => 'maria@example.gr',
            'afm'              => '094014201',
            'street'           => 'Καραϊσκάκη 12',
            'city'             => 'Τρίκαλα',
            'postal_code'      => '42100',
            'supply_number'    => '1 23456789-01',
            'category'         => 'home',
            'monthly_kwh'      => 330,
            'current_provider' => 'ΔΕΗ',
            'bill_in_own_name' => true,
            'docs_later'       => false,
            'consent_at'       => '2026-10-13T09:15:00+03:00',
            'consent_ip'       => '203.0.113.7',
            'terms_version'    => '2026-10',
            'reference'        => 'ΑΙΤ-2026-00001',
            'plan'             => [
                'provider' => 'Volton', 'provider_slug' => 'volton', 'name' => 'Blue Flat 18M',
                'tariff' => 'blue', 'monthly' => 71.4, 'price_kwh' => 0.139, 'fixed_monthly' => 5,
            ],
        ];
    }

    public function testAGoodApplicationIsRead(): void
    {
        $app = SiteApplication::fromPayload(self::payload());

        self::assertSame('app-20261013-0001', $app->ref);
        self::assertSame('Μαρία Παπαδοπούλου', $app->fullName);
        self::assertSame('2026-10-13 06:15:00', $app->consentAt, 'Η συναίνεση σε UTC, όπως το created_at.');
        self::assertSame('Volton Blue Flat 18M · 330 kWh/μήνα · ~71,40 €/μήνα · Οικιακό', $app->interest);
        self::assertSame('094014201', $app->private['afm']);
        self::assertSame('1 23456789-01', $app->private['supply_number']);
        self::assertStringContainsString(
            'αριθμός ΑΙΤ-2026-00001',
            $app->notes,
            'Ο πωλητής βρίσκει την αίτηση και στο site.'
        );
    }

    public function testTheNoteNeverCarriesTheAfmOrTheAddress(): void
    {
        $app = SiteApplication::fromPayload(self::payload());

        self::assertStringNotContainsString('094014201', $app->notes);
        self::assertStringNotContainsString('Καραϊσκάκη', $app->notes);
        self::assertStringNotContainsString('23456789', $app->notes);
        self::assertStringNotContainsString('094014201', $app->interest);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function badPayloads(): array
    {
        return [
            'χωρίς ref'         => [['ref' => '']],
            'ref με κενά'       => [['ref' => 'abc 12345']],
            'χωρίς όνομα'       => [['full_name' => '']],
            'όνομα μόνο ψηφία'  => [['full_name' => '6912345678']],
            'χωρίς κινητό'      => [['phone' => '']],
            'ΑΦΜ με γράμματα'   => [['afm' => '09401420Α']],
            'ΑΦΜ 8 ψηφία'       => [['afm' => '09401420']],
            'άγνωστη κατηγορία' => [['category' => 'gas']],
            'χωρίς συναίνεση'   => [['consent_at' => '']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badPayloads')]
    public function testABadApplicationIsRefused(array $over): void
    {
        $this->expectException(InvalidSiteApplication::class);

        SiteApplication::fromPayload(self::payload($over));
    }

    public function testABadEmailOrIpIsDroppedNotFatal(): void
    {
        $app = SiteApplication::fromPayload(self::payload(['email' => 'όχι-email', 'consent_ip' => 'κάτι']));

        self::assertSame('', $app->email);
        self::assertSame('', $app->consentIp);
    }

    public function testControlCharactersDoNotGetIn(): void
    {
        $app = SiteApplication::fromPayload(self::payload(['full_name' => "Μαρία\nΠαπαδοπούλου\x00"]));

        self::assertSame('Μαρία Παπαδοπούλου', $app->fullName);
    }

    // --- Είδη εγγράφων --------------------------------------------------------------

    public function testDocumentKindsMapToTheCrm(): void
    {
        self::assertSame('id_card', SiteDocumentKind::toCrm('id_front'));
        self::assertSame('id_card', SiteDocumentKind::toCrm('id_back'));
        self::assertSame('provider_bill', SiteDocumentKind::toCrm('bill'));
        self::assertSame('residence', SiteDocumentKind::toCrm('proof'));
        self::assertSame('other', SiteDocumentKind::toCrm('κάτι-άγνωστο'));
    }

    // --- Η «Μετατροπή» ---------------------------------------------------------------

    public function testConversionFillsCustomerAndContract(): void
    {
        $data      = SiteApplication::fromPayload(self::payload())->private;
        $providers = [
            ['id' => 3, 'slug' => 'nrg', 'name' => 'NRG'],
            ['id' => 7, 'slug' => 'volton', 'name' => 'VOLTON'],
        ];

        $customer = SiteLeadPrefill::customer($data);
        self::assertSame('094014201', $customer['afm']);
        self::assertSame('Καραϊσκάκη', $customer['street']);
        self::assertSame('12', $customer['street_no']);
        self::assertSame('Τρίκαλα', $customer['city']);
        self::assertSame('42100', $customer['postal_code']);

        $contract = SiteLeadPrefill::contract($data, $providers);
        self::assertSame('home', $contract['category']);
        self::assertSame('1 23456789-01', $contract['supply_number']);
        self::assertSame(7, $contract['provider_id']);
        self::assertStringContainsString('Volton Blue Flat 18M', $contract['notes']);
        self::assertStringContainsString('330 kWh/μήνα', $contract['notes']);
    }

    public function testTheProviderIsFoundByNameWhenTheSlugDiffers(): void
    {
        $providers = [['id' => 9, 'slug' => 'heron-energy', 'name' => 'Ήρων']];

        self::assertSame(
            9,
            SiteLeadPrefill::providerId(['provider' => 'ΗΡΩΝ', 'provider_slug' => 'heron'], $providers)
        );
    }

    public function testAnUnknownProviderStaysEmpty(): void
    {
        $contract = SiteLeadPrefill::contract(
            ['category' => 'business', 'plan' => ['provider' => 'Άγνωστη', 'provider_slug' => 'agnosti']],
            [['id' => 7, 'slug' => 'volton', 'name' => 'VOLTON']]
        );

        self::assertArrayNotHasKey('provider_id', $contract, 'Λάθος πάροχος είναι χειρότερος από κανέναν.');
        self::assertSame('business', $contract['category']);
    }

    public function testEmptyFieldsAreNotWritten(): void
    {
        self::assertSame(
            [],
            SiteLeadPrefill::customer(['afm' => '', 'street' => '', 'city' => '', 'postal_code' => ''])
        );
    }
}
