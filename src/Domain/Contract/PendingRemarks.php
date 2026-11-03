<?php

/**
 * (348) Παρατηρήσεις εκκρεμότητας: «Παρατηρήσεις Protergia» και «Παρατηρήσεις Agent».
 *
 * Όταν μια αίτηση μπαίνει σε «Εκκρεμότητα», ο πάροχος εξηγεί τι λείπει
 * (το γράφει ο Back office, από το email/portal) και ο agent που τη χειρίζεται
 * απαντά τι έκανε. Δύο κείμενα ανά αίτηση, με ιστορικό: κάθε αποθήκευση είναι
 * νέα γραμμή στο `events`, η τελευταία είναι η τρέχουσα. Καμία γραμμή δεν
 * αλλάζει ή σβήνεται.
 *
 * Μόνο κανόνες: τι είναι έγκυρο, ποιος γράφει τι. Χωρίς WordPress.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Contract;

final class PendingRemarks
{
    public const PROVIDER = 'provider';
    public const AGENT    = 'agent';

    public const EVENT_PROVIDER = 'remark_provider';
    public const EVENT_AGENT    = 'remark_agent';

    /** Μόνο σε αυτή την κατάσταση ανοίγουν τα πεδία. */
    public const STATUS = 'pending_issue';

    public const MAX_LENGTH = 1000;

    private function __construct()
    {
    }

    /** @return list<string> */
    public static function kinds(): array
    {
        return [self::PROVIDER, self::AGENT];
    }

    /** @return list<string> */
    public static function eventTypes(): array
    {
        return [self::EVENT_PROVIDER, self::EVENT_AGENT];
    }

    public static function eventType(string $kind): string
    {
        return $kind === self::PROVIDER ? self::EVENT_PROVIDER : self::EVENT_AGENT;
    }

    public static function kindOf(string $eventType): string
    {
        return $eventType === self::EVENT_PROVIDER ? self::PROVIDER : self::AGENT;
    }

    public static function label(string $kind): string
    {
        return $kind === self::PROVIDER ? 'Παρατηρήσεις Protergia' : 'Παρατηρήσεις Agent';
    }

    /** Το κείμενο όπως αποθηκεύεται: χωρίς κενά στις άκρες, μέχρι MAX_LENGTH χαρακτήρες. */
    public static function clean(string $text): string
    {
        $text = trim($text);

        return function_exists('mb_substr')
            ? mb_substr($text, 0, self::MAX_LENGTH)
            : substr($text, 0, self::MAX_LENGTH);
    }

    /**
     * Ο λόγος που δεν γίνεται η αποθήκευση, ή null.
     *
     * Η παρατήρηση του παρόχου γράφεται μόνο από Back office / διαχειριστή.
     * Και οι δύο μόνο όσο η αίτηση είναι σε «Εκκρεμότητα».
     */
    public static function problem(string $kind, string $status, string $text, bool $isBackOffice): ?string
    {
        if (! in_array($kind, self::kinds(), true)) {
            return 'Άγνωστο είδος παρατήρησης.';
        }

        if ($kind === self::PROVIDER && ! $isBackOffice) {
            return 'Τις «Παρατηρήσεις Protergia» τις γράφει το Back office.';
        }

        if ($status !== self::STATUS) {
            return 'Οι παρατηρήσεις γράφονται μόνο σε αιτήσεις με κατάσταση «Εκκρεμότητα».';
        }

        if (self::clean($text) === '') {
            return 'Γράψε μια παρατήρηση.';
        }

        return null;
    }
}
