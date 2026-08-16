<?php

/**
 * The stamp that tells a rotated key apart from a customer who never had an ΑΦΜ.
 *
 * FieldCipher::decrypt() answers '' when the key no longer opens a value, which
 * is right for one field and dangerous in aggregate: after a rotation every
 * screen reads empty, and the first save writes that emptiness over ciphertext
 * that was still perfectly recoverable. KeyFingerprint exists so the system can
 * say which of the two it is looking at.
 *
 * The tests below are about the three states — never recorded, recorded and
 * matching, recorded and not — and about the two refusals that keep the
 * safeguard from becoming its own outage: an absent stamp must not block
 * anything, and remember() must never overwrite.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Infrastructure\KeyFingerprint;

final class KeyFingerprintTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        delete_option(KeyFingerprint::OPTION);
    }

    protected function tearDown(): void
    {
        delete_option(KeyFingerprint::OPTION);

        parent::tearDown();
    }

    /**
     * An unrecorded stamp allows everything.
     *
     * Every site is in this state until the option is first written, including
     * every site that upgrades to the version introducing it. A safeguard that
     * treated absence as failure would take those sites down on deploy, which
     * is a worse outcome than the silent loss it is meant to prevent.
     */
    public function testAnAbsentFingerprintIsNotTreatedAsAMismatch(): void
    {
        $keys = KeyFingerprint::default();

        self::assertFalse($keys->isRecorded());
        self::assertTrue($keys->matches(), 'Απουσία αποτυπώματος δεν είναι απόδειξη περιστροφής.');
    }

    /** Recording it once, and the state it leaves behind. */
    public function testRememberRecordsTheCurrentKeyAndThenMatches(): void
    {
        $keys = KeyFingerprint::default();

        self::assertTrue($keys->remember(), 'Η πρώτη καταγραφή έπρεπε να γράψει.');
        self::assertTrue($keys->isRecorded());
        self::assertSame($keys->current(), $keys->stored());
        self::assertTrue($keys->matches());
    }

    /**
     * A second call changes nothing, and that is the important half.
     *
     * A remember() that overwrote would erase the disagreement at exactly the
     * moment it started to matter: the first page load after a rotation would
     * quietly re-stamp the new key as correct, and the evidence would be gone
     * before anyone read a screen.
     */
    public function testRememberNeverOverwritesAnExistingStamp(): void
    {
        $keys = KeyFingerprint::default();
        $keys->remember();

        update_option(KeyFingerprint::OPTION, 'ένα-αποτύπωμα-άλλου-κλειδιού');

        self::assertFalse($keys->remember(), 'Η δεύτερη κλήση δεν πρέπει να γράφει.');
        self::assertSame('ένα-αποτύπωμα-άλλου-κλειδιού', $keys->stored(), 'Το αποτύπωμα αντικαταστάθηκε.');
    }

    /** A stamp that disagrees with the key in use is the whole point. */
    public function testAStoredFingerprintFromAnotherKeyDoesNotMatch(): void
    {
        update_option(KeyFingerprint::OPTION, str_repeat('a', 64));

        $keys = KeyFingerprint::default();

        self::assertTrue($keys->isRecorded());
        self::assertFalse($keys->matches(), 'Άλλο κλειδί έπρεπε να αναγνωριστεί ως άλλο κλειδί.');
    }

    /**
     * The fingerprint is stable, and it is not the salt.
     *
     * Stable because a value that changed per call would report a rotation on
     * every request. Not the salt because it is written into the options table
     * in plain sight — the same table the encryption exists to survive.
     */
    public function testTheFingerprintIsStableAndIsNotTheSaltItself(): void
    {
        $keys = KeyFingerprint::default();

        $first = $keys->current();

        self::assertSame($first, KeyFingerprint::default()->current(), 'Το αποτύπωμα πρέπει να είναι σταθερό.');
        self::assertSame(64, strlen($first), 'Αναμένεται sha256 σε δεκαεξαδικό.');
        self::assertNotSame(wp_salt('secure_auth'), $first, 'Το αποτύπωμα ΔΕΝ επιτρέπεται να είναι το ίδιο το salt.');
        self::assertStringNotContainsString(wp_salt('secure_auth'), $first);
    }
}
