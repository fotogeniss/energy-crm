<?php

/**
 * The extended-fields bag, on its way into `contracts.extra_json`.
 *
 * Everything a provider form asks for that is not a column of its own lands
 * here: meter details, guarantees, the answers to the per-provider questions.
 * The shape is open by necessity — a new provider form adds keys without a
 * migration — so the sanitising is the only thing standing between the form and
 * the database.
 *
 * Both halves matter. Keys go through `sanitize_key()`, because they end up as
 * array indices that the PDF filler looks up by name. Values go through
 * `sanitize_text_field()` and are flattened to strings, because nothing in this
 * bag is ever anything else, and accepting a nested array here would mean
 * accepting whatever a caller nests inside it.
 *
 * Lifted out of ECRM_REST in roadmap step 10.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Contract;

final class ExtraFields
{
    private function __construct()
    {
    }

    /**
     * Clean the bag and encode it, or null when there is nothing worth storing.
     *
     * Null rather than `'{}'`: an empty bag and no bag are the same fact, and
     * one of the two spellings would otherwise show up in every query that asks
     * whether a contract has extras.
     */
    public static function toJson(mixed $extra): ?string
    {
        if (! is_array($extra) || $extra === []) {
            return null;
        }

        $clean = [];

        foreach ($extra as $key => $value) {
            $name = sanitize_key((string) $key);

            // sanitize_key() strips everything outside [a-z0-9_-]; a key made
            // only of those characters leaves nothing behind and is dropped
            // rather than stored under ''.
            if ($name === '') {
                continue;
            }

            $clean[$name] = sanitize_text_field((string) $value);
        }

        return $clean === [] ? null : wp_json_encode($clean);
    }
}
