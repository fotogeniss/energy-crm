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
 * The walk up the chain is still a loop, one get_user_meta per level. That is
 * an N+1 on a hot path — it runs on every signature and every document upload —
 * and it is replaced by the materialized path in the very next commit. It is
 * left alone here on purpose, so that the move and the optimisation are two
 * diffs and the tests can prove that only the second one changed the answer.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\NetworkRepository;
use EnergyCRM\Persistence\NotificationRepository;

final class ContractNotices
{
    /** A customer whose name we could not work out is still a customer. */
    private const FALLBACK_NAME = 'Ο πελάτης';

    /**
     * Depth guard for a parent chain that loops or is absurdly deep.
     *
     * The same 50 as NetworkRepository::MAX_DEPTH, and for the same reason: a
     * cycle in ecrm_parent would otherwise spin until the request times out.
     */
    private const MAX_DEPTH = 50;

    public function __construct(
        private readonly ContractRepository $contracts,
        private readonly NotificationRepository $notifications,
    ) {
    }

    /** The customer attached a supporting document through their link. */
    public function documentUploaded(int $contractId, string $label = ''): void
    {
        $row = $this->contracts->noticeSubject($contractId);

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
        $row = $this->contracts->noticeSubject($contractId);

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
     * Moved unchanged from ECRM_REST::upline_of(). The next commit replaces the
     * whole body with NetworkPath::ids() over the stored path — note that the
     * path *includes* the subject, so the replacement has to drop its last
     * element or the owner is notified twice.
     *
     * @return list<int>
     */
    private function uplineOf(int $userId): array
    {
        $managers = [];
        $current  = $userId;
        $depth    = 0;

        while ($current > 0 && $depth < self::MAX_DEPTH) {
            $depth++;

            $parentId = (int) get_user_meta($current, NetworkRepository::PARENT_META, true);

            if ($parentId <= 0 || in_array($parentId, $managers, true)) {
                break;
            }

            $managers[] = $parentId;
            $current    = $parentId;
        }

        return $managers;
    }
}
