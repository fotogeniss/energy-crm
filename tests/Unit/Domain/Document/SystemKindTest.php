<?php

/**
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Document;

use EnergyCRM\Domain\Contract\SignatureRoles;
use EnergyCRM\Domain\Document\SystemKind;
use EnergyCRM\Infrastructure\ContractDocuments;
use PHPUnit\Framework\TestCase;

final class SystemKindTest extends TestCase
{
    public function testTheApplicationInEveryFormIsAnApplication(): void
    {
        self::assertTrue(SystemKind::isApplication('contract'));
        self::assertTrue(SystemKind::isApplication('signed_pdf'));
        self::assertTrue(SystemKind::isApplication('form_porting'));
    }

    public function testEveryRoleSignatureIsASignature(): void
    {
        foreach (SignatureRoles::kinds() as $kind) {
            self::assertTrue(SystemKind::isSignature($kind), $kind);
            self::assertTrue(SystemKind::isSystem($kind), $kind);
            self::assertNotNull(SystemKind::label($kind), $kind);
        }
    }

    public function testASignatureIsNotAnApplication(): void
    {
        // Η υπογραφή μένει ορατή στα «Έγγραφα»· η αίτηση όχι.
        self::assertFalse(SystemKind::isApplication('signature'));
    }

    public function testUploadedDocumentsAreNeverSystem(): void
    {
        foreach (['id_card', 'provider_bill', 'sim_card', 'iban', 'other', 'e9', ''] as $kind) {
            self::assertFalse(SystemKind::isSystem($kind), $kind);
            self::assertNull(SystemKind::label($kind), $kind);
        }
    }

    public function testTheEnergySignatureIsToldApart(): void
    {
        self::assertNotSame(
            SystemKind::label(SignatureRoles::kindFor(SignatureRoles::MOBILE)),
            SystemKind::label(SignatureRoles::kindFor(SignatureRoles::ENERGY))
        );
    }

    /** Αν αλλάξει το όνομα στον κατασκευαστή της αίτησης, να σπάσει εδώ. */
    public function testMatchesWhatContractDocumentsWrites(): void
    {
        self::assertContains(ContractDocuments::KIND, SystemKind::APPLICATION);
        self::assertSame(ContractDocuments::SHEET_PREFIX, SystemKind::SHEET_PREFIX);
    }

    /** Ιδια αλήθεια για το `signed_pdf` που γράφει η σελίδα υπογραφής. */
    public function testMatchesWhatTheSigningPageWrites(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 4) . '/public/class-ecrm-tracking.php');

        foreach (SystemKind::APPLICATION as $kind) {
            if ($kind === ContractDocuments::KIND) {
                continue;
            }

            self::assertStringContainsString("'" . $kind . "'", $source, $kind);
        }
    }
}
