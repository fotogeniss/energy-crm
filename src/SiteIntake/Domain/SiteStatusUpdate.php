<?php

/**
 * (350) Τι λέμε στο site όταν αλλάζει η κατάσταση μιας αίτησης που ήρθε από εκεί.
 *
 * Ο πελάτης βλέπει την πορεία της αίτησής του στην εφαρμογή και στο site
 * («Η αίτησή μου»). Ως τώρα το site ήξερε μόνο ότι την έστειλε στο CRM· από
 * εκεί και πέρα σιωπή, μέχρι να τη γυρίσει κάποιος με το χέρι. Τώρα κάθε
 * αλλαγή κατάστασης φεύγει προς το site, υπογεγραμμένη με το ίδιο κοινό
 * μυστικό (SiteSignature) με το οποίο έρχονται οι αιτήσεις.
 *
 * Στέλνεται η ΤΕΛΙΚΗ κατάσταση, όχι η αλλαγή: αν χαθούν ή αργήσουν δύο
 * ενημερώσεις, η τελευταία αρκεί. Το `changed_at` λέει στο site ποια είναι
 * νεότερη, ώστε μια καθυστερημένη επανάληψη να μη γυρίσει πίσω την κατάσταση.
 *
 * Μόνο slug κατάστασης και ημερομηνίες σύμβασης: κανένα στοιχείο πελάτη, και
 * κανένα όνομα χρήστη του CRM. Το site τα ξέρει ήδη ή δεν τα χρειάζεται.
 *
 * Καθαρή κλάση, χωρίς WordPress: unit test και μεταφορά σε Laravel αυτούσια.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\SiteIntake\Domain;

use EnergyCRM\Domain\Contract\ContractStatus;

final class SiteStatusUpdate
{
    /** Ίδιο πρόθεμα με το `ref` που φτιάχνει το site (`CrmIntake::ref()` απέναντι). */
    public const PREFIX = 'site-';

    /** Μετά από τόσες αποτυχίες η ενημέρωση εγκαταλείπεται (≈ 6 μέρες με το 6ωρο ταβάνι). */
    public const MAX_ATTEMPTS = 30;

    private function __construct(
        public readonly string $ref,
        public readonly string $status,
        public readonly string $previous,
        public readonly ?string $startDate,
        public readonly ?string $endDate,
        public readonly int $changedAt,
    ) {
    }

    /**
     * null = δεν αφορά το site: υποψήφιος που δεν ήρθε από εκεί, ή το Πρόχειρο
     * (που δεν το βλέπει κανείς έξω από το CRM).
     */
    public static function forSite(
        ?string $ref,
        string $to,
        string $from,
        ?string $startDate,
        ?string $endDate,
        int $changedAt
    ): ?self {
        if (
            $ref === null
            || ! str_starts_with($ref, self::PREFIX)
            || preg_match('/^[A-Za-z0-9\-]{8,64}$/', $ref) !== 1
        ) {
            return null;
        }

        if (ContractStatus::tryFrom($to) === null || $to === ContractStatus::Draft->value) {
            return null;
        }

        return new self(
            $ref,
            $to,
            ContractStatus::tryFrom($from)->value ?? '',
            self::date($startDate),
            self::date($endDate),
            $changedAt
        );
    }

    /** @return array{ref: string, status: string, previous: string, start_date: ?string, end_date: ?string, changed_at: int} */
    public function toArray(): array
    {
        return [
            'ref'        => $this->ref,
            'status'     => $this->status,
            'previous'   => $this->previous,
            'start_date' => $this->startDate,
            'end_date'   => $this->endDate,
            'changed_at' => $this->changedAt,
        ];
    }

    /**
     * Το site είπε «όχι» και θα το ξαναπεί: λάθος αίτημα, άγνωστη αίτηση.
     * Δεν ξαναδοκιμάζουμε. Υπογραφή που δεν περνά (401) ΔΕΝ είναι εδώ: σημαίνει
     * συνήθως ότι άλλαξε το μυστικό και δεν μπήκε ακόμα στο .env του site.
     */
    public static function isPermanent(int $httpStatus): bool
    {
        return in_array($httpStatus, [400, 404, 410, 422], true);
    }

    /** 1', 2', 4', 8' … ως 6 ώρες. */
    public static function retryDelay(int $attempts): int
    {
        return (int) min(6 * 3600, 60 * (2 ** max(0, min(20, $attempts - 1))));
    }

    /** https πάντα· http μόνο για τοπικό site (localhost, *.test, *.local). */
    public static function validUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host']) || strlen($url) > 255) {
            return false;
        }

        $host = strtolower((string) $parts['host']);
        $local = $host === 'localhost'
            || $host === '127.0.0.1'
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.local');

        return ($parts['scheme'] ?? '') === 'https' || (($parts['scheme'] ?? '') === 'http' && $local);
    }

    private static function date(?string $value): ?string
    {
        $day = substr((string) $value, 0, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 ? $day : null;
    }
}
