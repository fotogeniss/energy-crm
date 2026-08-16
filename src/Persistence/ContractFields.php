<?php

/**
 * The encrypted part of a contract row: the values inside `extra_json`.
 *
 * CustomerFields covers the customer's own columns. The extras bag needs its
 * own treatment because the sensitive part is not the column — it is a handful
 * of values inside a JSON document that also carries meter readings and
 * prices. Encrypting the whole blob would take the readable parts with it and
 * break the erasure filter, which works by key.
 *
 * So the keys stay plaintext and the personal values are encrypted
 * individually. `{"agreed_power":"8","iban":"ecrm1:…"}` still parses, still
 * filters by key, and no longer hands a bank account to whoever reads a dump.
 *
 * Which values count as personal is not decided here: it is
 * ProviderFormFields::isPersonalInput(), the same default-deny rule erasure
 * answers to. A field nobody has classified is treated as personal, so a new
 * provider field is protected before anyone remembers it exists.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

use EnergyCRM\Domain\Forms\ProviderFormFields;
use EnergyCRM\Infrastructure\FieldCipher;
use EnergyCRM\Infrastructure\KeyFingerprint;

final class ContractFields
{
    public const EXTRAS_COLUMN = 'extra_json';

    public function __construct(private readonly FieldCipher $cipher)
    {
    }

    public static function default(): self
    {
        return new self(new FieldCipher(wp_salt('secure_auth')));
    }

    /**
     * @param array<string, mixed> $contract
     *
     * @return array<string, mixed>
     */
    public function forStorage(array $contract): array
    {
        if (! CustomerFields::isEnabled() || ! isset($contract[self::EXTRAS_COLUMN])) {
            return $contract;
        }

        // Reached only when the bag is being written, which is the only way
        // this column can lose anything. See CustomerFields::forStorage().
        KeyFingerprint::assertUsable();

        $contract[self::EXTRAS_COLUMN] = $this->map(
            $contract[self::EXTRAS_COLUMN],
            fn (string $value): string => $this->cipher->encrypt($value)
        );

        return $contract;
    }

    /**
     * Encrypt the personal values inside a bag that is *already stored*.
     *
     * The counterpart to CustomerFields::encryptStoredColumns(), and simpler:
     * there is no blind index over the extras, so there is nothing here that a
     * second pass could corrupt.
     *
     * Not gated on isEnabled(), for the same reason as its counterpart — the
     * caller is a backfill that has already decided.
     *
     * Null rather than the unchanged string, so the caller writes nothing when
     * there is nothing to do. A bag whose personal values are already
     * ciphertext, one that holds no personal keys at all, and one that will not
     * parse all return null.
     *
     * @return string|null The bag to store, or null when unchanged.
     *
     * @throws \EnergyCRM\Infrastructure\MissingCipher When this PHP cannot encrypt.
     */
    public function encryptStoredExtras(string $json): ?string
    {
        $encrypted = $this->map($json, fn (string $value): string => $this->cipher->encrypt($value));

        return is_string($encrypted) && $encrypted !== $json ? $encrypted : null;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function fromStorage(array $row): array
    {
        if (! isset($row[self::EXTRAS_COLUMN])) {
            return $row;
        }

        $row[self::EXTRAS_COLUMN] = $this->map(
            $row[self::EXTRAS_COLUMN],
            fn (string $value): string => $this->cipher->decrypt($value)
        );

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

    /**
     * Apply a transform to every personal value in the bag, leaving the rest —
     * and the document itself — as they were.
     *
     * A bag that will not parse is handed back untouched rather than replaced
     * with an empty one: losing a contract's extras to a stray character is a
     * worse outcome than carrying the bad value forward where it is visible.
     *
     * @param callable(string): string $transform
     */
    private function map(mixed $json, callable $transform): mixed
    {
        if (! is_string($json) || $json === '') {
            return $json;
        }

        $extras = json_decode($json, true);

        if (! is_array($extras)) {
            return $json;
        }

        $changed = false;

        foreach ($extras as $key => $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }

            if (! ProviderFormFields::isPersonalInput((string) $key)) {
                continue;
            }

            $transformed = $transform($value);

            if ($transformed !== $value) {
                $extras[$key] = $transformed;
                $changed      = true;
            }
        }

        return $changed ? (string) wp_json_encode($extras) : $json;
    }
}
