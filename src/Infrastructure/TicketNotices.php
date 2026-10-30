<?php

/**
 * (339) Ποιος ειδοποιείται για μια αναφορά -- στο καμπανάκι, όχι email.
 *
 * Πίνακας «Ειδοποιήσεις» του docs/UI-TICKETS.html:
 * - νέα αναφορά → διαχειριστές· και οι υπεύθυνοι του αναφέροντος αν «με σταματάει»·
 * - απάντηση → οι άλλοι της συζήτησης (αναφέρων, διαχειριστές), όχι ο ίδιος·
 * - αλλαγή κατάστασης → ο αναφέρων.
 *
 * Ο τύπος είναι `ticket:{id}`: το καμπανάκι τον κάνει σύνδεσμο στην αναφορά
 * (NotificationsController), αφού ο πίνακας ειδοποιήσεων δεν έχει στήλη για
 * κάτι άλλο από σύμβαση.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use EnergyCRM\Persistence\NetworkRepository;
use EnergyCRM\Persistence\NotificationRepository;

final class TicketNotices
{
    public const TYPE_PREFIX = 'ticket:';

    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly NetworkRepository $network,
    ) {
    }

    public static function type(int $ticketId): string
    {
        return self::TYPE_PREFIX . $ticketId;
    }

    /** Το id της αναφοράς από τον τύπο ειδοποίησης, ή 0. */
    public static function ticketOf(string $type): int
    {
        return str_starts_with($type, self::TYPE_PREFIX) ? (int) substr($type, strlen(self::TYPE_PREFIX)) : 0;
    }

    public function created(int $ticketId, int $reporter, string $reporterName, bool $urgent, string $summary): void
    {
        $title = ($urgent ? 'Επείγουσα αναφορά' : 'Νέα αναφορά') . ' #' . $ticketId . ' · ' . $reporterName;

        $to = $this->admins();

        if ($urgent) {
            $to = array_merge($to, $this->network->uplineOf($reporter));
        }

        $this->send($to, $reporter, $ticketId, $title, $summary);
    }

    public function commented(int $ticketId, int $author, string $authorName, int $reporter, string $summary): void
    {
        $to = $author === $reporter ? $this->admins() : array_merge([$reporter], $this->admins());

        $this->send($to, $author, $ticketId, 'Απάντηση στην αναφορά #' . $ticketId . ' · ' . $authorName, $summary);
    }

    public function statusChanged(int $ticketId, int $author, int $reporter, string $label): void
    {
        $this->send([$reporter], $author, $ticketId, 'Η αναφορά #' . $ticketId . ': ' . $label, '');
    }

    public function reopened(int $ticketId, int $reporter, string $reporterName): void
    {
        $title = 'Ξανάνοιξε η αναφορά #' . $ticketId . ' · ' . $reporterName;

        $this->send($this->admins(), $reporter, $ticketId, $title, '');
    }

    /**
     * @param list<int> $to
     */
    private function send(array $to, int $except, int $ticketId, string $title, string $body): void
    {
        $title = mb_substr($title, 0, 190, 'UTF-8');
        $body  = mb_substr($body, 0, 160, 'UTF-8');

        foreach (array_unique(array_map('intval', $to)) as $userId) {
            if ($userId > 0 && $userId !== $except) {
                $this->notifications->add($userId, self::type($ticketId), $title, $body);
            }
        }
    }

    /** @return list<int> */
    private function admins(): array
    {
        return array_map('intval', get_users(['capability' => 'manage_options', 'fields' => 'ID']));
    }
}
