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
 * The walk itself now lives in `NetworkRepository::uplineOf()` (31/08) — moved
 * out from a private method here once `ECRM_Notifications::escalations()`
 * needed the identical logic. Same code either way, one authoritative copy.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use EnergyCRM\Domain\Contract\ContractLifecycle;
use EnergyCRM\Domain\Contract\ContractStatus;
use EnergyCRM\Persistence\ContractDetails;
use EnergyCRM\Persistence\NetworkRepository;
use EnergyCRM\Persistence\NotificationRepository;

final class ContractNotices
{
    /** A customer whose name we could not work out is still a customer. */
    private const FALLBACK_NAME = 'Ο πελάτης';

    /**
     * Οι καταστάσεις για τις οποίες χτυπά καμπανάκι, και γιατί μόνο αυτές.
     *
     * Δεν ειδοποιεί κάθε μετάβαση: μια αίτηση περνά από πρόχειρο, νέα,
     * υπογραφή, επεξεργασία και δρομολόγηση χωρίς να χρειάζεται τίποτα από
     * κανέναν, και ένα καμπανάκι σε κάθε βήμα είναι θόρυβος που μαθαίνει τον
     * χρήστη να μην κοιτάζει.
     *
     * Τρεις διαφορετικές αιτίες: η **εκκρεμότητα** ζητά ενέργεια από τον
     * συνεργάτη — «κάτι λείπει, κάποιος πρέπει να το φτιάξει» — η
     * **ακύρωση** τερματίζει τη δουλειά του, και η **απόρριψη** (31/08) είναι
     * ενέργεια παρόχου που επίσης ζητά κάτι από τον συνεργάτη -- εκεί μάλιστα
     * δημιουργείται και εργασία, δες `RejectionFollowUp`, το καμπανάκι εδώ
     * είναι το άμεσο σήμα, η εργασία είναι αυτή που δεν χάνεται. Και στις
     * τρεις, το να το μάθει αργά είναι το ίδιο κακό με το να μην το μάθει.
     *
     * Μόνο ποιες καταστάσεις — το κείμενο έρχεται αποκλειστικά από
     * `ContractStatus::label()`, ώστε να μην υπάρχει δεύτερο σημείο που
     * μπορεί να ξεμείνει πίσω όταν αλλάξει η ελληνική ετικέτα μιας κατάστασης.
     *
     * @var list<ContractStatus>
     */
    private const ANNOUNCED = [ContractStatus::Pending, ContractStatus::Cancelled, ContractStatus::Rejected];

    public function __construct(
        private readonly ContractDetails $details,
        private readonly NotificationRepository $notifications,
        private readonly NetworkRepository $network,
    ) {
    }

    /**
     * Ακούει τον κύκλο ζωής, αντί να τον καλεί εκείνος.
     *
     * Ο `ContractLifecycle` ζει στο `Domain` και δεν ξέρει ότι υπάρχει
     * ειδοποίηση — όπως δεν ξέρει ούτε ότι υπάρχει χρονοπρογραμματιστής. Ο
     * `AutoProcess` συνδέεται στο ίδιο σημείο για τον ίδιο λόγο.
     */
    public function register(): void
    {
        add_action(ContractLifecycle::STATUS_CHANGED, [$this, 'statusChanged'], 10, 2);
    }

    /**
     * Η σύμβαση μπήκε σε κατάσταση που ο συνεργάτης πρέπει να μάθει.
     *
     * Ως τις 19/08/2026 δεν υπήρχε τίποτα εδώ, και η συνέπεια μετρήθηκε: το
     * καμπανάκι κάλυπτε μόνο «ο πελάτης ανέβασε έγγραφο» και «ο πελάτης
     * υπέγραψε», το email της εκκρεμότητας πήγαινε σε λάθος άνθρωπο (δες
     * `ECRM_Notifications::notify_status_change()`), και η ημερήσια περίληψη
     * στέλνει μόνο για ό,τι έχει μείνει ακίνητο **πέντε μέρες** — ενώ η ίδια η
     * αλλαγή κατάστασης μόλις ανανέωσε το `updated_at`. Δηλαδή ο συνεργάτης
     * μάθαινε ότι η αίτησή του ζητά ενέργεια στην καλύτερη περίπτωση πέντε
     * μέρες αργότερα.
     *
     * Ο δράστης δεν εξαιρείται από τους παραλήπτες, και δεν είναι παράλειψη: το
     * `ecrm_contract_status_changed` δίνει σύμβαση, νέα και παλιά κατάσταση —
     * **όχι** ποιος ενήργησε. Θα ήταν σωστό να μην ειδοποιείται κάποιος για
     * κάτι που μόλις έκανε ο ίδιος, αλλά για να γίνει αυτό πρέπει ο δράστης να
     * περάσει μέσα από το ίδιο το γεγονός, που είναι αλλαγή στον κύκλο ζωής και
     * όχι εδώ. Ως τότε, ένα περιττό καμπανάκι είναι φθηνότερο από ένα που
     * λείπει — ο συνεργάτης που ακυρώνει μόνος του τη δική του αίτηση το βλέπει
     * δύο φορές, ο προϊστάμενός του μία, και κανείς δεν το χάνει.
     */
    public function statusChanged(int $contractId, string $to): void
    {
        $status = ContractStatus::tryFromSlug($to);

        if ($status === null || ! in_array($status, self::ANNOUNCED, true)) {
            return;
        }

        $row = $this->details->noticeSubject($contractId);

        if ($row === null) {
            return;
        }

        $title = $status->label() . ' — ' . (string) ($row['code'] ?? '');
        $body   = $this->customerName($row) . ': η αίτηση ' . match ($to) {
            'cancelled' => 'ακυρώθηκε.',
            'rejected'  => 'απορρίφθηκε από τον πάροχο -- δημιουργήθηκε εργασία.',
            default     => 'χρειάζεται ενέργεια.',
        };

        $this->tell($row, 'status', $title, $body, $contractId);
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

        foreach ($this->network->uplineOf($owner) as $managerId) {
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

}
