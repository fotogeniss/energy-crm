<?php

/**
 * (348) Παρατηρήσεις αίτησης: «Παρατηρήσεις Protergia» και «Παρατηρήσεις Agent».
 *
 * - **Protergia:** η εξήγηση του παρόχου για μια εκκρεμότητα. Τη γράφει μόνο ο
 *   Back office (και ο διαχειριστής), και μόνο όσο η αίτηση είναι σε
 *   «Εκκρεμότητα». Εκτός «Εκκρεμότητας» δεν φαίνεται.
 * - **Agent:** ό,τι γράφει όποιος χειρίζεται την αίτηση, σε κάθε αίτηση,
 *   όποια κατάσταση κι αν έχει.
 *
 * Κάθε αποθήκευση είναι νέα γραμμή στο `events`, η τρέχουσα είναι η
 * τελευταία. Καμία γραμμή δεν αλλάζει ή σβήνεται.
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

    /** Η κατάσταση όπου υπάρχει «Παρατηρήσεις Protergia». */
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

    /** Φαίνεται η «Παρατηρήσεις Protergia» σε αυτή την κατάσταση; */
    public static function showsProvider(string $status): bool
    {
        return $status === self::STATUS;
    }

    /** Το κείμενο όπως αποθηκεύεται: χωρίς κενά στις άκρες, μέχρι MAX_LENGTH χαρακτήρες. */
    public static function clean(string $text): string
    {
        $text = trim($text);

        return function_exists('mb_substr')
            ? mb_substr($text, 0, self::MAX_LENGTH)
            : substr($text, 0, self::MAX_LENGTH);
    }

    /** Ο λόγος που δεν γίνεται η αποθήκευση, ή null. */
    public static function problem(string $kind, string $status, string $text, bool $isBackOffice): ?string
    {
        if (! in_array($kind, self::kinds(), true)) {
            return 'Άγνωστο είδος παρατήρησης.';
        }

        if ($kind === self::PROVIDER && ! $isBackOffice) {
            return 'Τις «Παρατηρήσεις Protergia» τις γράφει το Back office.';
        }

        if ($kind === self::PROVIDER && ! self::showsProvider($status)) {
            return 'Οι «Παρατηρήσεις Protergia» γράφονται μόνο σε αιτήσεις σε «Εκκρεμότητα».';
        }

        if (self::clean($text) === '') {
            return 'Γράψε μια παρατήρηση.';
        }

        return null;
    }
}
