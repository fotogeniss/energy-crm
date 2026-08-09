<?php

/**
 * The human reference printed on a contract — ORIZON-0001, PROTERGIA-0042.
 *
 * It derives from the row id, so it cannot be produced before the insert. That
 * is why every creation path is an insert followed by an update, and why the
 * format lived in three places, copy-pasted, until it moved here. A contract's
 * code is what a partner quotes on the phone; it must not vary by which screen
 * created the row.
 *
 * The provider prefix exists because a flat "APP-0035" sequence shared by
 * every provider tells you nothing until you open the row — five agents on
 * the phone reading codes to each other need to know which provider a code
 * belongs to from the code alone. The numbering itself stays one global
 * sequence (the row id): a second Orizon contract does not need to know how
 * many Protergia contracts came before it, and a shared sequence needs no
 * per-provider counter to keep unique. Existing codes are never rewritten —
 * a code already quoted to a customer or printed on paper must not change
 * under it.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Contract;

final class ContractCode
{
    /** Used when a contract has no provider yet (e.g. a bare lead). */
    private const DEFAULT_PREFIX = 'APP';

    /** Four digits keeps the early codes aligned; later ones simply grow. */
    private const PADDING = 4;

    private function __construct()
    {
    }

    /**
     * @param string $providerPrefix The provider's own prefix (e.g. its slug,
     *                                upper-cased). Falls back to the generic
     *                                "APP" prefix when blank.
     */
    public static function forId(int $contractId, string $providerPrefix = ''): string
    {
        $prefix = $providerPrefix !== '' ? strtoupper(trim($providerPrefix, '-')) : self::DEFAULT_PREFIX;

        return $prefix . '-' . str_pad((string) max(0, $contractId), self::PADDING, '0', STR_PAD_LEFT);
    }
}
