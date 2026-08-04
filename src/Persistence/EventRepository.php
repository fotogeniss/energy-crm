<?php

/**
 * The status history of a contract.
 *
 * No scope parameter: an event carries no data of its own beyond what the
 * contract already exposes, and callers reach it only after the contract has
 * been loaded through a scoped read. Taking a scope here would suggest a check
 * that is not actually happening.
 *
 * See ContractRepository for the note on the phpcs exemptions.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

final class EventRepository
{
    private string $table;

    public function __construct(?string $table = null)
    {
        $this->table = $table ?? Tables::name(Tables::EVENTS);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forContract(int $contractId): array
    {
        global $wpdb;

        if ($contractId <= 0) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT type, from_status, to_status, message, created_at
                 FROM %i WHERE contract_id = %d ORDER BY created_at DESC',
                $this->table,
                $contractId
            ),
            ARRAY_A
        );

        return $rows;
    }
}
