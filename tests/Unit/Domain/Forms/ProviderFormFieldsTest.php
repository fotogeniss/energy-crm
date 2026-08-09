<?php

/**
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Forms;

use EnergyCRM\Domain\Forms\ProviderFormFields;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProviderFormFieldsTest extends TestCase
{
    /**
     * The fields erasure exists for. If one of these is ever answered "not
     * personal", a customer's bank account or a third party's ΑΔΤ survives a
     * deletion request.
     *
     * @return list<array{string}>
     */
    public static function personalInputs(): array
    {
        return [
            ['iban'],
            ['rep_first_name'],
            ['rep_last_name'],
            ['contact_first_name'],
            ['contact_last_name'],
            ['contact_adt'],
            ['contact_afm'],
            ['contact_phone'],
            ['contact_mobile'],
            ['contact_email'],
            ['mobile_msisdn'],
            ['sim_number'],
            ['kad'],
            ['gemi'],
            ['company_type'],
            ['activity'],
            ['eidiki_katigoria'],
            // Ρεύματος στοιχείο μέσα σε αίτηση κινητής (COMBO): παραμένει
            // προσωπικό γιατί δένει με τη διεύθυνση του πελάτη, ORIZON-TODO.md #6.
            ['combo_supply_number'],
        ];
    }

    #[DataProvider('personalInputs')]
    public function testFieldsThatIdentifySomeoneDoNotSurviveErasure(string $input): void
    {
        self::assertTrue(ProviderFormFields::isPersonalInput($input));
    }

    /**
     * @return list<array{string}>
     */
    public static function impersonalInputs(): array
    {
        return [
            ['agreed_power'],
            ['day_indication'],
            ['guarantee'],
            ['previous_provider'],
            ['meter_position'],
            ['meter_reading_type'],
            ['payment_method'],
            ['offer_price'],
            ['request_type'],
            ['mobile_offer'],
        ];
    }

    #[DataProvider('impersonalInputs')]
    public function testSupplyAndPricingFiguresAreKeptForReporting(string $input): void
    {
        self::assertFalse(ProviderFormFields::isPersonalInput($input));
    }

    /**
     * The extras bag stores whatever key the form sends, so a field nobody has
     * classified yet must fail towards deletion rather than towards keeping.
     */
    public function testAnUnknownFieldIsTreatedAsPersonal(): void
    {
        self::assertTrue(ProviderFormFields::isPersonalInput('field_added_next_year'));
        self::assertTrue(ProviderFormFields::isPersonalInput(''));
    }
}
