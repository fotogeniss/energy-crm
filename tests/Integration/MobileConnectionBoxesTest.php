<?php

/**
 * The ΕΙΔΟΣ ΣΥΝΔΕΣΗΣ boxes, tested where the bug actually lived.
 *
 * MobilePaperworkTest pins what connectionTicks() returns. That was already
 * green while the printed form was wrong, because the defect was never in the
 * method — it was in the merge.
 *
 * ECRM_FormFill::values() builds `$mobile + [ …electricity… ]`. PHP's `+` keeps
 * the left operand's keys, but only the keys it *has*. connectionTicks()
 * returned a single key, so `energopoiisi_ananeosi` fell through to the
 * electricity map, which writes it from `activation_type`. A mobile contract
 * carrying 'renewal' printed ΦΟΡΗΤΟΤΗΤΑ and ΑΝΑΝΕΩΣΗ at once — an application
 * telling the provider two contradictory things, signed and sent.
 *
 * So the assertions here go through values(), with an activation_type set. A
 * test of the method alone would pass again tomorrow if someone reverted the
 * fix to "return only what you tick".
 *
 * Integration rather than unit because values() reaches for get_userdata() and
 * the options table.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use ECRM_FormFill;
use EnergyCRM\Domain\Forms\MobilePaperwork;

final class MobileConnectionBoxesTest extends IntegrationTestCase
{
    /**
     * A mobile contract that also carries an electricity activation type.
     *
     * Not a contrived combination: the renewal route sets activation_type, and
     * nothing stops a mobile contract going through it.
     *
     * @return array<string, mixed>
     */
    private function mobileContract(string $requestType, string $activationType): array
    {
        return [
            'energy_type'     => 'mobile',
            'activation_type' => $activationType,
            'customer_type'   => 'individual',
            'first_name'      => 'Κωνσταντίνος',
            'last_name'       => 'Νίκας',
            'extra_json'      => (string) wp_json_encode(['request_type' => $requestType]),
        ];
    }

    /** The exact combination that printed two ticks. */
    public function testAPortabilityRenewalDoesNotTickRenewalAsWell(): void
    {
        $values = ECRM_FormFill::values(
            $this->mobileContract(MobilePaperwork::REQUEST_PORTABILITY, 'renewal')
        );

        self::assertSame('X', $values['energopoiisi_foritotita']);
        self::assertSame('', $values['energopoiisi_ananeosi'], 'Η ΑΝΑΝΕΩΣΗ τσεκαρίστηκε από το activation_type.');
        self::assertSame('', $values['energopoiisi_nea_syndesi']);
    }

    public function testANewNumberTicksOnlyNewConnection(): void
    {
        $values = ECRM_FormFill::values(
            $this->mobileContract(MobilePaperwork::REQUEST_NEW_NUMBER, 'renewal')
        );

        self::assertSame('X', $values['energopoiisi_nea_syndesi']);
        self::assertSame('', $values['energopoiisi_foritotita']);
        self::assertSame('', $values['energopoiisi_ananeosi']);
    }

    /**
     * Exactly one box, whatever the electricity side is carrying.
     *
     * The provider reads this row as a single answer. Two ticks is not a
     * cosmetic defect, it is an application that contradicts itself.
     */
    public function testExactlyOneBoxIsEverTicked(): void
    {
        $activationTypes = ['renewal', 'new_connection', 'change_provider', 'succession', ''];

        foreach ($activationTypes as $activationType) {
            $values = ECRM_FormFill::values(
                $this->mobileContract(MobilePaperwork::REQUEST_PORTABILITY, $activationType)
            );

            $ticked = array_filter([
                $values['energopoiisi_foritotita'],
                $values['energopoiisi_nea_syndesi'],
                $values['energopoiisi_ananeosi'],
            ]);

            self::assertCount(1, $ticked, "Με activation_type '{$activationType}' τσεκαρίστηκαν " . count($ticked) . ' κουτιά.');
        }
    }

    /**
     * Electricity is untouched by all of this.
     *
     * The fix works by having the mobile map answer for keys it does not tick.
     * If that map ever leaked into a non-mobile contract, every electricity
     * form would stop reporting its activation type — silently, since an empty
     * box looks exactly like a box nobody needed.
     */
    public function testAnElectricityContractStillTicksItsActivationType(): void
    {
        $values = ECRM_FormFill::values([
            'energy_type'     => 'power',
            'activation_type' => 'renewal',
            'customer_type'   => 'individual',
            'first_name'      => 'Κωνσταντίνος',
            'last_name'       => 'Νίκας',
        ]);

        self::assertSame('X', $values['energopoiisi_ananeosi']);
    }
}
