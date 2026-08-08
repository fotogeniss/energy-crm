<?php

/**
 * Encrypts individual database fields, and makes the encrypted ones findable.
 *
 * SecretStore has been doing this for API credentials since step 8. The same
 * reasoning applies with more force to a customer's ΑΦΜ, ΑΔΤ, address and
 * IBAN: a database dump handed to a developer, a backup on shared storage, an
 * injection that reads a table. The key comes from the wp-config salts, so it
 * does not protect against someone who already reads the filesystem — that is
 * the honest limit, and it is still the exposure that actually happens.
 *
 * The reason this is not simply SecretStore reused: a secret is written and
 * read whole, while a customer column is *searched*. Encryption defeats search
 * twice over — `LIKE` is impossible, and randomised encryption means the same
 * ΑΦΜ produces different bytes every time, so even equality fails.
 *
 * Hence blindIndex(): a keyed hash of the value, stored beside the ciphertext
 * in its own column. The same input always yields the same hash, so exact
 * lookups and duplicate detection keep working, while the hash reveals nothing
 * without the key. Keyed rather than a plain hash on purpose — nine digits is
 * a space a laptop enumerates in seconds, and an unkeyed SHA-256 of an ΑΦΜ is
 * a lookup table, not a protection.
 *
 * Partial search is the price. `LIKE '%1234%'` against a hash is meaningless,
 * and nothing brings it back.
 *
 * Constructed with its key material rather than reading wp_salt(), so every
 * decision here runs in a unit test without WordPress.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

final class FieldCipher
{
    /** Marks a value written by this class, so legacy plaintext is detectable. */
    public const PREFIX = 'ecrm1:';

    /** Derived once: a list of fifty rows would otherwise re-derive per field. */
    private ?string $key = null;

    private ?string $indexKey = null;

    public function __construct(private readonly string $keyMaterial)
    {
    }

    /** Whether a stored value has already been through this class. */
    public static function isEncrypted(string $stored): bool
    {
        return str_starts_with($stored, self::PREFIX);
    }

    /** Whether this PHP build can encrypt at all. */
    public static function isAvailable(): bool
    {
        return function_exists('sodium_crypto_secretbox')
            && function_exists('sodium_crypto_secretbox_open');
    }

    /**
     * @throws MissingCipher When encryption was asked for and cannot be done.
     */
    public function encrypt(string $plaintext): string
    {
        // Encrypting an empty column would turn "we never asked" into bytes
        // that look like an answer, and NULL is how the schema says unknown.
        if ($plaintext === '') {
            return $plaintext;
        }

        // Returning the plaintext here — which this used to do — is the worst
        // available answer. The column fills with readable ΑΦΜ while the site
        // owner, having switched encryption on, believes the opposite. A
        // failure nobody can see is not a safe default; refusing is.
        if (! self::isAvailable()) {
            throw MissingCipher::sodiumUnavailable();
        }

        // Encrypting twice is a bug that only shows itself on read, when the
        // user sees ciphertext. Cheaper to refuse.
        if (self::isEncrypted($plaintext)) {
            return $plaintext;
        }

        $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->key());

        return self::PREFIX . base64_encode($nonce . $cipher);
    }

    /**
     * The value back, whether or not it was ever encrypted.
     *
     * Rows written before this class existed are plaintext and stay readable.
     * That tolerance is what lets encryption arrive without a flag day, and
     * what stops a half-migrated table from showing anybody base64.
     */
    public function decrypt(string $stored): string
    {
        if (! self::isEncrypted($stored)) {
            return $stored;
        }

        if (! function_exists('sodium_crypto_secretbox_open')) {
            return '';
        }

        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);

        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }

        $nonce  = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain  = sodium_crypto_secretbox_open($cipher, $nonce, $this->key());

        // False means the salts changed. The value is unrecoverable, and an
        // empty field is a better answer than corrupt bytes on a contract.
        return $plain === false ? '' : $plain;
    }

    /**
     * A keyed hash for exact lookups on an encrypted column.
     *
     * Normalised first, because the index is only useful if the same ΑΦΜ typed
     * with a stray space finds the same row.
     *
     * @return string 64 hex characters, or '' for an empty value.
     */
    public function blindIndex(string $value): string
    {
        $value = strtolower(trim($value));

        if ($value === '') {
            return '';
        }

        return hash_hmac('sha256', $value, $this->indexKey());
    }

    /**
     * The label goes into the message, not the key argument.
     *
     * generichash()'s second parameter is a key, and it will only accept 16 to
     * 64 bytes — a short label there throws "unsupported key length" on the
     * first encrypt. Prefixing the material achieves the same separation with
     * no length rule to trip over.
     */
    private function key(): string
    {
        return $this->key ??= sodium_crypto_generichash(
            'ecrm-field-v1|' . $this->keyMaterial,
            '',
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES
        );
    }

    /**
     * Separate from the encryption key.
     *
     * The blind index travels differently — it sits in its own column, it is
     * compared, and it may end up in a query log. Deriving it from the same
     * bytes that decrypt the data would mean one leak costs both.
     */
    private function indexKey(): string
    {
        return $this->indexKey ??= hash_hmac('sha256', 'ecrm-blind-index-v1', $this->keyMaterial);
    }
}
