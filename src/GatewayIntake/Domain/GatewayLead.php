<?php

/**
 * Ένας υποψήφιος από το AI τηλεφωνικό κέντρο (Call Gateway), ελεγμένος.
 *
 * ## Από πού έρχεται
 *
 * Μόνο από τον server του Gateway, με υπογραφή (ίδιο σχήμα με το site,
 * `SiteSignature`, αλλά ΔΙΚΟ του μυστικό) και από λίστα επιτρεπτών IP. Το AI
 * δεν μιλά ποτέ απευθείας με το CRM: συμπληρώνει ένα lead στο Gateway, και το
 * Gateway το στέλνει εδώ αφού κλείσει η κλήση.
 *
 * ## Τι ΔΕΝ περιέχει, επίτηδες
 *
 * ΑΦΜ, IBAN, ταυτότητα: το AI δεν τα ζητά, και ό,τι μοιάζει με αυτά αφαιρείται
 * στο Gateway. Εδώ ξανά, δεύτερο δίχτυ, στη σημείωση: εννιά ψηφία στη σειρά ή
 * IBAN δεν φτάνουν ποτέ σε στήλη που φαίνεται σε λίστα. Το συμβόλαιο το κλείνει
 * άνθρωπος, με τα στοιχεία από τον πελάτη.
 *
 * ## Συγκατάθεση
 *
 * Εξερχόμενη κλήση γίνεται μόνο με επιβεβαιωμένη συγκατάθεση (άρθρο 11 ν.
 * 3471/2006)· ο έλεγχος είναι στο Gateway, πριν την κλήση. Εδώ κρατάμε την
 * ένδειξη: πότε δόθηκε, πότε επιβεβαιώθηκε, ποιο κείμενο. Εισερχόμενη κλήση
 * (μας πήρε ο πελάτης) δεν έχει συγκατάθεση και δεν χρειάζεται.
 *
 * Καθαρή κλάση, χωρίς WordPress.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\GatewayIntake\Domain;

final class GatewayLead
{
    public const SOURCE = 'ai_call';

    /** Πρόθεμα στο `leads.external_ref`: δεν συγκρούεται ποτέ με `ref` του site. */
    public const REF_PREFIX = 'gw-';

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private const INTEREST = ['high' => 'υψηλό', 'medium' => 'μέτριο', 'low' => 'χαμηλό', 'none' => 'κανένα'];

    private const REDACTED = '[αφαιρέθηκε]';

    private function __construct(
        public readonly string $ref,
        public readonly string $fullName,
        public readonly string $phone,
        public readonly string $direction,
        public readonly bool $transferred,
        public readonly ?int $consentGivenAt,
        public readonly string $interest,
        public readonly string $notes,
    ) {
    }

    /**
     * @param array<string, mixed> $p Το JSON του Gateway.
     *
     * @throws InvalidGatewayLead
     */
    public static function fromPayload(array $p): self
    {
        $ref = self::text($p, 'ref', 36);
        if (preg_match(self::UUID, $ref) !== 1) {
            throw new InvalidGatewayLead('Λείπει ή είναι λάθος το αναγνωριστικό (ref).');
        }

        $phone = self::text($p, 'phone', 40);
        if ($phone === '') {
            throw new InvalidGatewayLead('Λείπει το τηλέφωνο.');
        }

        $energy = (string) ($p['energy_type'] ?? '');
        if (! in_array($energy, ['electricity', 'power'], true)) {
            throw new InvalidGatewayLead('Το Gateway στέλνει μόνο ρεύμα.');
        }

        $direction = (string) ($p['direction'] ?? '');
        if (! in_array($direction, ['inbound', 'outbound'], true)) {
            throw new InvalidGatewayLead('Λάθος κατεύθυνση κλήσης (inbound ή outbound).');
        }

        $interest = $p['interest'] ?? null;
        if ($interest !== null && ! array_key_exists((string) $interest, self::INTEREST)) {
            throw new InvalidGatewayLead('Λάθος βαθμός ενδιαφέροντος.');
        }

        $customerType = $p['customer_type'] ?? null;
        if ($customerType !== null && ! in_array($customerType, ['residential', 'business'], true)) {
            throw new InvalidGatewayLead('Λάθος τύπος πελάτη (residential ή business).');
        }

        $consent = self::consent($p['consent'] ?? null, $direction);

        $name = self::text($p, 'name', 160);
        if ($name !== '' && preg_match('/\p{L}/u', $name) !== 1) {
            $name = '';
        }

        $provider    = self::text($p, 'current_provider', 120);
        $transferred = ($p['transferred'] ?? false) === true;
        $notesIn     = self::redact(self::text($p, 'notes', 1000));

        return new self(
            self::REF_PREFIX . strtolower($ref),
            $name !== '' ? $name : 'Χωρίς όνομα (κλήση AI)',
            $phone,
            $direction,
            $transferred,
            $consent['given'] ?? null,
            self::interestLine($interest === null ? null : (string) $interest, $customerType, $transferred),
            self::notesText($direction, $provider, $notesIn, $consent),
        );
    }

    /**
     * Η συγκατάθεση: υποχρεωτική και επιβεβαιωμένη σε εξερχόμενη.
     *
     * @return array{given: int, verified: int, id: string, text_id: string}|null
     *
     * @throws InvalidGatewayLead
     */
    private static function consent(mixed $c, string $direction): ?array
    {
        if (! is_array($c)) {
            if ($direction === 'outbound') {
                throw new InvalidGatewayLead('Εξερχόμενη κλήση χωρίς συγκατάθεση.');
            }

            return null;
        }

        $id       = self::text($c, 'id', 36);
        $textId   = self::text($c, 'text_id', 40);
        $given    = strtotime(self::text($c, 'given_at', 40));
        $verified = strtotime(self::text($c, 'verified_at', 40));

        if (preg_match(self::UUID, $id) !== 1 || $textId === '' || $given === false || $verified === false) {
            throw new InvalidGatewayLead('Η συγκατάθεση είναι ελλιπής (id, κείμενο, πότε δόθηκε, πότε επιβεβαιώθηκε).');
        }
        if ($verified < $given) {
            throw new InvalidGatewayLead('Η συγκατάθεση επιβεβαιώθηκε πριν δοθεί.');
        }

        return ['given' => $given, 'verified' => $verified, 'id' => strtolower($id), 'text_id' => $textId];
    }

    /** Η γραμμή που βλέπει ο πωλητής στη λίστα (στήλη 255 χαρακτήρων). */
    private static function interestLine(?string $interest, mixed $customerType, bool $transferred): string
    {
        $parts = ['Κλήση AI'];

        if ($interest !== null) {
            $parts[] = 'ενδιαφέρον ' . self::INTEREST[$interest];
        }
        if ($customerType !== null) {
            $parts[] = $customerType === 'business' ? 'Επαγγελματικό' : 'Οικιακό';
        }
        if ($transferred) {
            $parts[] = 'μεταφέρθηκε σε πωλητή';
        }

        return mb_substr(implode(' · ', $parts), 0, 255);
    }

    /**
     * @param array{given: int, verified: int, id: string, text_id: string}|null $consent
     */
    private static function notesText(string $direction, string $provider, string $notes, ?array $consent): string
    {
        $lines = [$direction === 'outbound'
            ? 'Εξερχόμενη κλήση από το AI τηλεφωνικό κέντρο.'
            : 'Εισερχόμενη κλήση στο AI τηλεφωνικό κέντρο.'];

        if ($provider !== '') {
            $lines[] = 'Πάροχος σήμερα: ' . $provider;
        }
        if ($notes !== '') {
            $lines[] = 'Σημείωση AI: ' . $notes;
        }
        if ($consent !== null) {
            $lines[] = 'Συγκατάθεση για αυτοματοποιημένες κλήσεις: κείμενο ' . $consent['text_id']
                . ', επιβεβαίωση με SMS. Η πλήρης απόδειξη (' . $consent['id'] . ') είναι στο Gateway.';
        }
        $lines[] = 'Το AI δεν συλλέγει ΑΦΜ, IBAN ή ταυτότητα. Αυτά τα παίρνει ο πωλητής από τον πελάτη.';

        return implode("\n", $lines);
    }

    /** Δεύτερο δίχτυ: εννιά ψηφία στη σειρά (ΑΦΜ) και IBAN δεν μένουν στη σημείωση. */
    private static function redact(string $text): string
    {
        $text = (string) preg_replace('/\b[A-Za-z]{2}\d{2}(?:[ -]?[A-Za-z0-9]){11,30}\b/', self::REDACTED, $text);

        return (string) preg_replace('/(?<![0-9])[0-9]{9}(?![0-9])/', self::REDACTED, $text);
    }

    /**
     * @param array<string, mixed> $p
     */
    private static function text(array $p, string $key, int $max): string
    {
        $value = $p[$key] ?? '';

        if (! is_scalar($value)) {
            return '';
        }

        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value));

        return mb_substr($clean, 0, $max);
    }
}
