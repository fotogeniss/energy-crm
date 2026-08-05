<?php

/**
 * The human reference printed on a contract — APP-0001.
 *
 * It derives from the row id, so it cannot be produced before the insert. That
 * is why every creation path is an insert followed by an update, and why the
 * format lived in three places, copy-pasted, until it moved here. A contract's
 * code is what a partner quotes on the phone; it must not vary by which screen
 * created the row.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Contract;

final class ContractCode
{
    private const PREFIX = 'APP-';

    /** Four digits keeps the early codes aligned; later ones simply grow. */
    private const PADDING = 4;

    private function __construct()
    {
    }

    public static function forId(int $contractId): string
    {
        return self::PREFIX . str_pad((string) max(0, $contractId), self::PADDING, '0', STR_PAD_LEFT);
    }
}
