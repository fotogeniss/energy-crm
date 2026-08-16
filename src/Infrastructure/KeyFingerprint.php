<?php

/**
 * Remembers which key encrypted this site's data, so a rotation is detectable.
 *
 * ## What it is protecting against
 *
 * FieldCipher derives its key from wp_salt('secure_auth'). Change
 * SECURE_AUTH_SALT and every ΑΦΜ, ΑΔΤ and address stops opening — and
 * decrypt() answers '' rather than throwing, which for one field is the right
 * call and in aggregate is a trap:
 *
 * 1. The ciphertext is still on disk, intact. Put the old salt back and
 *    everything returns, blind index included.
 * 2. Every screen shows those fields empty.
 * 3. An agent opens a contract, saves, and the emptiness is written over the
 *    ciphertext.
 *
 * Step 3 is the unrecoverable one. Until it happens nothing is lost, and
 * nothing in the system could tell the difference between "this customer never
 * had an ΑΦΜ" and "this customer's ΑΦΜ is one salt away". This class is that
 * difference.
 *
 * ## Why an option and not a constant
 *
 * The fingerprint has to live where the data lives, because that is what it is
 * a statement about: *these rows were written under that key*. A value in
 * wp-config would travel with the configuration instead of with the database
 * and would agree with itself after a restore of the wrong pair.
 *
 * ## The one case it gets wrong, stated rather than hidden
 *
 * The stamp is taken the first time it is asked for. On a site where the salt
 * has *already* been rotated and nobody noticed, that records the wrong key as
 * the right one. Nothing can distinguish those states from inside — the data
 * simply does not open, and it did not open before this class existed either.
 * What changes is that from the stamp onwards a rotation is caught. Where an
 * operator knows the stamp is wrong, deleting the option re-takes it.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

final class KeyFingerprint
{
    /**
     * Autoloaded on purpose, unlike the backfill cursor.
     *
     * That one is read once an hour by cron and would be dead weight in every
     * request's option cache. This one is read by anything that wants to know
     * whether the data is readable, so paying for a query each time is the
     * worse trade.
     */
    public const OPTION = 'ecrm_pii_key_fingerprint';

    public function __construct(private readonly FieldCipher $cipher)
    {
    }

    public static function default(): self
    {
        return new self(new FieldCipher(wp_salt('secure_auth')));
    }

    /** The key in use right now. */
    public function current(): string
    {
        return $this->cipher->fingerprint();
    }

    /** The key this site's data was written under, or '' when never recorded. */
    public function stored(): string
    {
        return (string) get_option(self::OPTION, '');
    }

    public function isRecorded(): bool
    {
        return $this->stored() !== '';
    }

    /**
     * True unless the key has demonstrably changed.
     *
     * An unrecorded fingerprint answers true. It is the state every site is in
     * before this class ships, and refusing to work on the strength of an
     * absence would turn a safeguard into an outage.
     */
    public function matches(): bool
    {
        $stored = $this->stored();

        return $stored === '' || hash_equals($stored, $this->current());
    }

    /**
     * Record the current key as this site's key, once.
     *
     * Never overwrites. An existing stamp that disagrees is the signal, and a
     * remember() that quietly replaced it would erase the only evidence at the
     * exact moment it started to matter.
     *
     * @return bool Whether this call is what wrote it.
     */
    public function remember(): bool
    {
        if ($this->isRecorded()) {
            return false;
        }

        return add_option(self::OPTION, $this->current());
    }
}
