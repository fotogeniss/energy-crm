<?php

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Contract;

use EnergyCRM\Domain\Contract\SignatureRoles;
use EnergyCRM\Domain\Contract\SignProof;
use PHPUnit\Framework\TestCase;

/**
 * (354b) Υπογραφή με ΑΦΜ και αποδεικτικό: οι καθαροί κανόνες.
 */
final class SignProofTest extends TestCase
{
    private const KEY = 'test-key-of-the-contract';

    public function testTheAfmIsComparedAsNineDigits(): void
    {
        self::assertTrue(SignProof::afmMatches('123456781', '123456781'));
        self::assertFalse(
            SignProof::afmMatches('123 456 781', '123456781'),
            'Τα κενά τα κόβει ο καλών με το ECRM_Validate::digits(), όχι εδώ.'
        );
        self::assertFalse(SignProof::afmMatches('123456782', '123456781'));
        self::assertFalse(SignProof::afmMatches('12345678', '12345678'), 'Οκτώ ψηφία δεν είναι ΑΦΜ.');
        self::assertFalse(SignProof::afmMatches('', ''), 'Κενό αναμενόμενο ΑΦΜ δεν ταιριάζει με τίποτα.');
    }

    public function testThePrimarySignerIsCheckedAgainstTheCustomerAndTheSecondAgainstTheExtras(): void
    {
        $contract = ['afm' => '111111111', 'energy_type' => 'power'];
        $extra    = ['combo_mobile_afm' => '222222222', 'combo_energy_afm' => '333333333'];
        $both     = [SignatureRoles::MOBILE, SignatureRoles::ENERGY];

        // Ρεύμα με κινητή, δύο πρόσωπα: κύριος ο πελάτης ενέργειας.
        self::assertSame('111111111', SignProof::expectedAfm($contract, $extra, SignatureRoles::ENERGY, $both));
        self::assertSame('222222222', SignProof::expectedAfm($contract, $extra, SignatureRoles::MOBILE, $both));

        // Ένα πρόσωπο: πάντα ο πελάτης της αίτησης.
        $one = [SignatureRoles::MOBILE];
        self::assertSame('111111111', SignProof::expectedAfm($contract, $extra, SignatureRoles::MOBILE, $one));

        // Κινητή με ενέργεια, δύο πρόσωπα: κύριος ο πελάτης κινητής.
        $mobile = ['afm' => '444444444', 'energy_type' => 'mobile'];
        self::assertSame('444444444', SignProof::expectedAfm($mobile, $extra, SignatureRoles::MOBILE, $both));
        self::assertSame('333333333', SignProof::expectedAfm($mobile, $extra, SignatureRoles::ENERGY, $both));
    }

    public function testAProofIsBoundToItsContractRoleAndTime(): void
    {
        $now   = 1_794_400_000;
        $proof = SignProof::makeProof(42, SignatureRoles::MOBILE, $now, self::KEY);

        self::assertSame($now, SignProof::readProof($proof, 42, SignatureRoles::MOBILE, self::KEY, $now + 60));
        self::assertNull(SignProof::readProof($proof, 43, SignatureRoles::MOBILE, self::KEY, $now), 'Άλλη αίτηση.');
        self::assertNull(SignProof::readProof($proof, 42, SignatureRoles::ENERGY, self::KEY, $now), 'Άλλος ρόλος.');
        self::assertNull(SignProof::readProof($proof, 42, SignatureRoles::MOBILE, 'other-key', $now), 'Άλλο κλειδί.');
        self::assertNull(
            SignProof::readProof($proof, 42, SignatureRoles::MOBILE, self::KEY, $now + SignProof::TTL_SECONDS + 1),
            'Έληξε.'
        );
        self::assertNull(SignProof::readProof('junk', 42, SignatureRoles::MOBILE, self::KEY, $now));
        self::assertNull(SignProof::readProof($proof, 42, SignatureRoles::MOBILE, '', $now), 'Χωρίς κλειδί, τίποτα.');
    }

    public function testTheProofHidesTheAfmAndIp(): void
    {
        self::assertSame('••••••781', SignProof::maskAfm('123456781'));
        self::assertSame('—', SignProof::maskAfm(''));
        self::assertSame('85.72.•••.•••', SignProof::maskIp('85.72.10.200'));
        self::assertSame('2a02:587:•••', SignProof::maskIp('2a02:587:1234::1'));
        self::assertSame('—', SignProof::maskIp(''));
    }

    private const UA_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 '
        . '(KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1';

    private const UA_ANDROID = 'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36';

    private const UA_EDGE = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0';

    public function testTheDeviceIsNamedInPlainWords(): void
    {
        self::assertSame(
            'iPhone · Safari 18',
            SignProof::deviceLabel(self::UA_IPHONE)
        );
        self::assertSame(
            'Android · Chrome 130',
            SignProof::deviceLabel(self::UA_ANDROID)
        );
        self::assertSame(
            'Windows · Edge 130',
            SignProof::deviceLabel(self::UA_EDGE)
        );
        self::assertSame('Άγνωστη συσκευή', SignProof::deviceLabel(''));
    }

    public function testTimesAreCutNotConverted(): void
    {
        self::assertSame('11/11/2026 15:40:37', SignProof::stamp('2026-11-11 15:40:37'));
        self::assertSame('', SignProof::stamp(''));
    }

    public function testEachSignerHasTheirOwnProofKind(): void
    {
        self::assertSame('sign_proof', SignProof::kindFor(SignatureRoles::MOBILE));
        self::assertSame('sign_proof_energy', SignProof::kindFor(SignatureRoles::ENERGY));
        self::assertSame('', SignProof::kindFor('nobody'));
    }
}
