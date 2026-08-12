<?php

/**
 * The in-app notices a contract raises: what lights the bell in the interface.
 *
 * ## Why this is not ECRM_Notifications
 *
 * The two share a name and nothing else, and the record said to merge them
 * until 2026-08-11. ECRM_Notifications sends **email** — a wp_mail on the move
 * to "pending", and a daily digest from cron. This one writes **rows** in the
 * notifications table, which is what the agent sees at the top of the screen.
 * Merging them would put two delivery mechanisms in one file and make the next
 * person work out which half they were reading.
 *
 * ## Who gets told
 *
 * The contract's owner, and everyone above them to the top of the network. The
 * upline matters because commission flows up the same tree: a manager who is
 * not told their team signed something finds out at payout time.
 *
 * The walk up the chain is one read of the stored path, not one per level. It
 * used to be a loop over `ecrm_parent`, up to fifty `get_user_meta` calls on a
 * path that runs on every signature and every document upload. That is the same
 * N+1 step 3 removed from `visible_user_ids()`, and it is removed here the same
 * way — the materialized path already holds the whole ancestry in one value.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use EnergyCRM\Access\NetworkPath;
use EnergyCRM\Persistence\ContractDetails;
use EnergyCRM\Persistence\NetworkRepository;
use EnergyCRM\Persistence\NotificationRepository;

final class ContractNotices
{
    /** A customer whose name we could not work out is still a customer. */
    private const FALLBACK_NAME = 'Ο πελάτης';

    public function __construct(
        private readonly ContractDetails $details,
        private readonly NotificationRepository $notifications,
        private readonly NetworkRepository $network,
    ) {
    }

    /** The customer attached a supporting document through their link. */
    public function documentUploaded(int $contractId, string $label = ''): void
    {
        $row = $this->details->noticeSubject($contractId);

        if ($row === null) {
            return;
        }

        $title = 'Νέο δικαιολογητικό — ' . (string) ($row['code'] ?? '');
        $body  = $this->customerName($row)
            . ' ανέβασε'
            . ($label !== '' ? ': ' . $label : ' έγγραφο')
            . '.';

        $this->tell($row, 'document', $title, $body, $contractId);
    }

    /** The customer signed the contract electronically. */
    public function signed(int $contractId, string $signer = ''): void
    {
        $row = $this->details->noticeSubject($contractId);

        if ($row === null) {
            return;
        }

        $title = 'Υπεγράφη σύμβαση ' . (string) ($row['code'] ?? '');
        $body  = $this->customerName($row)
            . ' υπέγραψε ηλεκτρονικά'
            . ($signer !== '' ? ' (' . $signer . ')' : '')
            . '.';

        $this->tell($row, 'signed', $title, $body, $contractId);
    }

    /**
     * One notice to the owner, then the same notice to each of their managers.
     *
     * @param array<string, mixed> $row
     */
    private function tell(array $row, string $type, string $title, string $body, int $contractId): void
    {
        $owner = (int) $row['partner_user_id'];

        $this->notifications->add($owner, $type, $title, $body, $contractId);

        foreach ($this->uplineOf($owner) as $managerId) {
            $this->notifications->add($managerId, $type, $title, $body, $contractId);
        }
    }

    /**
     * What to call the customer: the company when there is one, otherwise the
     * person.
     *
     * @param array<string, mixed> $row
     */
    private function customerName(array $row): string
    {
        $company = (string) ($row['company_name'] ?? '');
        $person  = trim(
            (string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? '')
        );

        return ($company ?: $person) ?: self::FALLBACK_NAME;
    }

    /**
     * The managers above a user, nearest first, the user themselves excluded.
     *
     * The stored path runs the other way round from the answer this method owes
     * its caller: "/1/7/23/" is root first and *includes* 23. Hence the two
     * adjustments, both of which matter —
     *
     *   - `array_slice(..., 0, -1)` drops the subject. Without it the owner is
     *     told twice about their own contract, once as owner and once as their
     *     own manager, on every signature and every upload.
     *   - `array_reverse` restores nearest-first, so the rows are written in the
     *     order they always were.
     *
     * A user id of zero has no path — NetworkPath::root() rejects it — so it is
     * turned away here. The old loop returned an empty array for the same input,
     * and a contract whose partner_user_id never got set is exactly the kind of
     * row that reaches this method through an anonymous signing link.
     *
     * The depth guard against a looping `ecrm_parent` did not go away, it moved
     * down: NetworkRepository::computePath() stops on a repeated id, and
     * NetworkPath::isValid() refuses a path that contains one.
     *
     * @return list<int>
     */
    private function uplineOf(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $lineage = NetworkPath::ids($this->network->pathFor($userId));

        return array_reverse(array_slice($lineage, 0, -1));
    }
}
