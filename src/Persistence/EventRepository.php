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

    /**
     * Append an entry to a contract's history.
     *
     * The log is append-only by design: it is the record of who did what, and
     * there is deliberately no method here that edits or removes an entry.
     *
     * @param string               $type    'created', 'status_change', 'note'…
     * @param array<string, mixed> $details from_status, to_status, message.
     */
    public function record(int $contractId, int $userId, string $type, array $details = []): void
    {
        global $wpdb;

        if ($contractId <= 0) {
            return;
        }

        $wpdb->insert($this->table, [
            'contract_id' => $contractId,
            'user_id'     => max(0, $userId),
            'type'        => $type,
            // Null rather than an empty string: a missing previous status and a
            // status of "" are different claims about what happened.
            'from_status' => $this->orNull($details['from_status'] ?? null),
            'to_status'   => $this->orNull($details['to_status'] ?? null),
            'message'     => $this->orNull($details['message'] ?? null),
        ]);
    }

    /**
     * Whether the contract ever reached a status, according to its own history.
     *
     * The one question the transition graph cannot answer. `pending` accepts
     * contracts from `new`, where cancelling is ordinary, and from `active`,
     * where it is not — and the status column holds only where the contract is
     * now, never where it has been. See Domain\Contract\CancellationGate.
     *
     * Reads `to_status` and not `from_status`: a status the contract left is
     * one it reached, but the first entry of all has no `from` at all, so
     * asking the other side would miss a contract that has moved exactly once.
     */
    public function hasReached(int $contractId, string $status): bool
    {
        global $wpdb;

        if ($contractId <= 0 || $status === '') {
            return false;
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT EXISTS (
                     SELECT 1 FROM %i
                     WHERE contract_id = %d AND type = %s AND to_status = %s
                 )',
                $this->table,
                $contractId,
                'status_change',
                $status
            )
        ) === 1;
    }

    private function orNull(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
