<?php

/**
 * Which customer columns are stored encrypted, and the translation either way.
 *
 * One class decides this, for the same reason PersonalDataEraser is one class:
 * a column encrypted on write but forgotten on read shows an agent base64
 * where a tax number should be, and the failure surfaces in front of a
 * customer rather than in a test.
 *
 * ## Turning it on
 *
 * Writing ciphertext is deliberately opt-in:
 *
 *     define( 'ECRM_ENCRYPT_PII', true );   // wp-config.php
 *
 * Reading always understands both, so the switch can be flipped on a staging
 * copy, checked, and flipped back without touching data. Rows written while it
 * was on stay readable after it goes off. Nothing about this is a flag day,
 * which is the only way a change this wide is safe to ship.
 *
 * ## Why the ΑΦΜ has a second column
 *
 * `afm_hash` is a keyed hash of the same value (see FieldCipher::blindIndex).
 * Encryption is randomised, so `WHERE afm = %s` cannot work; the hash is
 * stable, so `WHERE afm_hash = %s` can. That keeps duplicate detection and
 * lookup by full ΑΦΜ working.
 *
 * Partial ΑΦΜ search does not survive, and nothing brings it back — a hash has
 * no prefix. Search by name, phone, contract code and supply number is
 * untouched.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

use EnergyCRM\Infrastructure\FieldCipher;
use EnergyCRM\Infrastructure\KeyFingerprint;

final class CustomerFields
{
    /**
     * Columns held as ciphertext.
     *
     * Deliberately not `city` or `region`: a county groups a report, a street
     * number identifies a household. And not `birth_date`, which is a DATE
     * column — storing ciphertext there needs a type change, so it is a
     * separate step rather than a silent truncation.
     *
     * `phone` joined this list on 2026-08-26 (LOW finding of the security
     * audit) -- see CustomerRepository::search() for the one place that
     * touching this list was not enough on its own: an exact-match `LIKE`
     * against ciphertext never matches, the same failure the ΑΦΜ already
     * solved with a blind index. `phone_hash` is that same fix, reused.
     *
     * Adding one here means widening it in the schema too: ciphertext is
     * several times longer than the value, and a column too narrow for it
     * truncates rather than fails on a non-strict server. See
     * Schema\Migrations\WidenEncryptedColumns and
     * Schema\Migrations\WidenCustomerPhoneColumn.
     *
     * @var list<string>
     */
    private const ENCRYPTED = ['afm', 'adt', 'street', 'street_no', 'postal_code', 'phone'];

    /** The column whose blind index makes exact lookup possible. */
    public const INDEXED = 'afm';

    public const INDEX_COLUMN = 'afm_hash';

    /**
     * Same problem as `INDEXED`/`INDEX_COLUMN`, one column later.
     *
     * A second dedicated pair rather than a generic map of many: there are
     * exactly two columns that need exact-match lookup once encrypted, and a
     * map would buy flexibility nothing here asks for.
     */
    public const PHONE_INDEXED = 'phone';

    public const PHONE_INDEX_COLUMN = 'phone_hash';

    public function __construct(private readonly FieldCipher $cipher)
    {
    }

    /**
     * The encrypted columns, for callers that must build SQL over them.
     *
     * Exposed rather than copied. A second list somewhere else would agree
     * today and diverge the first time a column is added here — the same
     * failure WritableColumns exists to prevent.
     *
     * @return list<string>
     */
    public static function encryptedColumns(): array
    {
        return self::ENCRYPTED;
    }

    /** Wired from the wp-config salts, the same source SecretStore uses. */
    public static function default(): self
    {
        return new self(new FieldCipher(wp_salt('secure_auth')));
    }

    /**
     * Whether new writes should store ciphertext. See the note above.
     *
     * The constant is the switch a site owner sets; the filter exists because
     * a constant cannot be changed once defined, and a test that can only
     * check one of the two states is half a test.
     */
    public static function isEnabled(): bool
    {
        $enabled = defined('ECRM_ENCRYPT_PII') && constant('ECRM_ENCRYPT_PII') === true;

        return (bool) apply_filters('ecrm_encrypt_pii', $enabled);
    }

    /**
     * A customer row on its way into the database.
     *
     * The blind index is maintained whether or not encryption is on, so that
     * turning it on later does not need a second backfill — and so lookups
     * behave identically either way.
     *
     * @param array<string, mixed> $customer
     *
     * @return array<string, mixed>
     */
    public function forStorage(array $customer): array
    {
        // Only when this row actually carries protected data. A save that
        // touches nothing encrypted — a phone number, a name — cannot destroy
        // anything under a rotated key, and refusing it would turn a safeguard
        // into an outage. The blind index counts as protected: written under
        // the wrong key it silently stops matching every other row.
        if (self::touchesProtected($customer)) {
            KeyFingerprint::assertUsable();
        }

        if (array_key_exists(self::INDEXED, $customer)) {
            $customer[self::INDEX_COLUMN] = $this->cipher->blindIndex((string) $customer[self::INDEXED]);
        }

        if (array_key_exists(self::PHONE_INDEXED, $customer)) {
            $customer[self::PHONE_INDEX_COLUMN] = $this->cipher->blindIndex((string) $customer[self::PHONE_INDEXED]);
        }

        if (! self::isEnabled()) {
            return $customer;
        }

        foreach (self::ENCRYPTED as $column) {
            if (isset($customer[$column]) && is_string($customer[$column])) {
                $customer[$column] = $this->cipher->encrypt($customer[$column]);
            }
        }

        return $customer;
    }

    /**
     * Whether this row would write a value that only the current key can read.
     *
     * @param array<string, mixed> $customer
     */
    private static function touchesProtected(array $customer): bool
    {
        if (array_key_exists(self::INDEXED, $customer)) {
            return true;
        }

        foreach (self::ENCRYPTED as $column) {
            if (array_key_exists($column, $customer)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Encrypt the columns of a row that is *already stored*, leaving the blind
     * index alone.
     *
     * forStorage() is for a row arriving from a form: it derives `afm_hash`
     * from the plaintext ΑΦΜ it was handed, then encrypts. Handing it a row
     * read back out of the database would hash the **ciphertext** and write
     * that over a correct index. Nothing would error. The next duplicate check
     * would simply match no rows, an agent would read that as "no duplicate
     * exists", and the same customer would be filed twice — the failure this
     * whole mechanism was built to avoid.
     *
     * So the backfill comes in here instead. The index is already complete for
     * every existing row (migration 0010) and there is nothing to maintain.
     *
     * Deliberately **not** gated on isEnabled(): this is an explicit operation
     * with an explicit caller, not a write that should quietly follow a switch.
     *
     * Returns only what changed, so a row with nothing to do is skipped rather
     * than rewritten — an idle UPDATE bumps `updated_at`, which is what the
     * contracts list sorts by.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, string> Columns to write; empty when already done.
     *
     * @throws \EnergyCRM\Infrastructure\MissingCipher When this PHP cannot encrypt.
     */
    public function encryptStoredColumns(array $row): array
    {
        $changes = [];

        foreach (self::ENCRYPTED as $column) {
            if (! isset($row[$column]) || ! is_string($row[$column]) || $row[$column] === '') {
                continue;
            }

            $stored = $row[$column];

            if (FieldCipher::isEncrypted($stored)) {
                continue;
            }

            $changes[$column] = $this->cipher->encrypt($stored);
        }

        return $changes;
    }

    /**
     * A row on its way out, whatever it was stored as.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function fromStorage(array $row): array
    {
        foreach (self::ENCRYPTED as $column) {
            if (isset($row[$column]) && is_string($row[$column])) {
                $row[$column] = $this->cipher->decrypt($row[$column]);
            }
        }

        // The hash is how we find the row, not part of it. A `SELECT *` would
        // otherwise carry it into an API response, where it is a stable
        // identifier for a tax number -- or a phone number -- and has no
        // reason to exist there.
        unset($row[self::INDEX_COLUMN]);
        unset($row[self::PHONE_INDEX_COLUMN]);

        return $row;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    public function fromStorageAll(array $rows): array
    {
        return array_map([$this, 'fromStorage'], $rows);
    }

    /** What to compare against `afm_hash`. */
    public function index(string $value): string
    {
        return $this->cipher->blindIndex($value);
    }
}
