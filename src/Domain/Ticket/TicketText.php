<?php

/**
 * (339) Το κείμενο μιας αναφοράς ή απάντησης, πριν αποθηκευτεί.
 *
 * Το γράφει ο χρήστης ελεύθερα, άρα μπορεί να γράψει «ο πελάτης με ΑΦΜ
 * 123456789 και κινητό 6912345678». Μια αναφορά προβλήματος δεν χρειάζεται
 * τίποτα από αυτά -- η αίτηση συνδέεται ήδη μέσω του πλαισίου -- οπότε
 * κρύβονται ΠΡΙΝ φτάσουν στη βάση (mockup docs/UI-TICKETS.html, «GDPR»).
 *
 * Κρύβονται: email, IBAN, ΑΦΜ (9 ψηφία), τηλέφωνα (10 ψηφία που αρχίζουν
 * από 2 ή 69, με ή χωρίς +30 και κενά). ΔΕΝ κρύβονται οι 11ψήφιοι αριθμοί
 * παροχής ούτε οι κωδικοί αιτήσεων: δείχνουν σύμβαση, όχι πρόσωπο, και είναι
 * ακριβώς αυτό που χρειάζεται όποιος ψάχνει το πρόβλημα.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Ticket;

final class TicketText
{
    public const MAX_LENGTH = 4000;

    private function __construct()
    {
    }

    public static function clean(string $text, int $max = self::MAX_LENGTH): string
    {
        $text = str_replace("\r\n", "\n", $text);
        $text = (string) preg_replace('/[^\P{C}\n\t]/u', '', $text);
        $text = trim(self::redact($text));

        return mb_substr($text, 0, $max, 'UTF-8');
    }

    public static function redact(string $text): string
    {
        $rules = [
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu'                       => '[email]',
            '/\bGR\s?\d{2}(?:\s?\d{4}){5}\s?\d{3}\b/iu'                           => '[IBAN]',
            '/(?<![\d])(?:\+?30[\s\-]?)?(?:2\d|69)\d(?:[\s\-]?\d){7}(?![\d])/u' => '[τηλέφωνο]',
            '/(?<![\d])\d{9}(?![\d])/u'                                          => '[ΑΦΜ]',
        ];

        foreach ($rules as $pattern => $mask) {
            $text = (string) preg_replace($pattern, $mask, $text);
        }

        return $text;
    }
}
