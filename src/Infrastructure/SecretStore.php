<?php

/**
 * Where API credentials come from, in order of preference.
 *
 *   1. A PHP constant in wp-config.php — outside the database entirely.
 *   2. An environment variable.
 *   3. An encrypted option, for people who prefer an admin screen.
 *
 * On the encryption: the key is derived from wp-config.php salts, so it does
 * not protect against an attacker who already reads the filesystem. What it
 * does cover is the far more common exposure — a database dump handed to a
 * developer, a backup on shared storage, an SQL injection that reads options.
 * Encrypting there is worth doing; pretending it is more than that is not.
 *
 * The honest recommendation stays option 1.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

final class SecretStore
{
    /** Marks a value written by this class, so legacy plaintext is detectable. */
    private const PREFIX = 'ecrm1:';

    /**
     * Resolve a secret.
     *
     * @param string $name Logical name, e.g. "claude_api_key".
     */
    public function get(string $name): string
    {
        $constant = 'ECRM_' . strtoupper($name);

        if (defined($constant)) {
            return (string) constant($constant);
        }

        $env = getenv($constant);

        if (is_string($env) && $env !== '') {
            return $env;
        }

        $stored = (string) get_option('ecrm_' . $name, '');

        return $this->decrypt($stored);
    }

    /** True when the value is pinned outside the database and cannot be edited. */
    public function isPinned(string $name): bool
    {
        $constant = 'ECRM_' . strtoupper($name);

        return defined($constant) || is_string(getenv($constant)) && getenv($constant) !== '';
    }

    public function put(string $name, string $value): void
    {
        update_option('ecrm_' . $name, $value === '' ? '' : $this->encrypt($value));
    }

    /**
     * Last four characters, behind a fixed run of bullets.
     *
     * Fixed rather than proportional on purpose: a mask as long as the secret
     * tells anyone reading the screen how long the secret is, which is free
     * information for no benefit.
     */
    public function mask(string $name): string
    {
        $value = $this->get($name);

        if ($value === '') {
            return '';
        }

        return str_repeat('•', 12) . substr($value, -4);
    }

    private function encrypt(string $plaintext): string
    {
        if (! function_exists('sodium_crypto_secretbox')) {
            return $plaintext;
        }

        $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->key());

        return self::PREFIX . base64_encode($nonce . $cipher);
    }

    private function decrypt(string $stored): string
    {
        // Written before this class existed: still plaintext, still usable.
        // Re-saving through the settings screen upgrades it.
        if ($stored === '' || ! str_starts_with($stored, self::PREFIX)) {
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

        // False means the salts changed — the value is unrecoverable, and
        // saying so beats handing a corrupt key to the API.
        return $plain === false ? '' : $plain;
    }

    private function key(): string
    {
        return sodium_crypto_generichash(
            wp_salt('secure_auth'),
            '',
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES
        );
    }
}
