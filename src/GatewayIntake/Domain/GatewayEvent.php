<?php

/**
 * Ένα γεγονός του CRM προς το Call Gateway: «μη καλέσεις αυτόν τον αριθμό» ή
 * «σβήστηκαν τα στοιχεία αυτού του πελάτη».
 *
 * ## Γιατί δεν χάνεται ποτέ
 *
 * Ένα «μη με ξαναπάρετε» που δεν έφτασε στο Gateway σημαίνει παράνομη κλήση.
 * Άρα, αντίθετα από τις ενημερώσεις κατάστασης του site (που μετά από λίγες
 * προσπάθειες παραιτούνται), εδώ ΔΕΝ υπάρχει όριο προσπαθειών: ξαναδοκιμάζει
 * μέχρι να παραδοθεί. Μόνο 400/422, δηλαδή «αυτό το γεγονός είναι λάθος και θα
 * είναι πάντα», σταματά. Ένα 401 (λάθος μυστικό) ή 503 (το Gateway έπεσε) ΔΕΝ
 * σταματά: διορθώνεται, και το γεγονός περιμένει.
 *
 * Καθαρή κλάση, χωρίς WordPress.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\GatewayIntake\Domain;

use EnergyCRM\Domain\Customer\PhoneNumber;

final class GatewayEvent
{
    public const OPT_OUT = 'lead.opt_out';

    public const ERASED = 'lead.erased';

    /** Δευτερόλεπτα ανάμεσα σε προσπάθειες· η τελευταία τιμή επαναλαμβάνεται για πάντα. */
    private const DELAYS = [60, 120, 300, 900, 3600, 21600];

    private function __construct(
        public readonly string $type,
        public readonly string $phone,
        public readonly int $at,
    ) {
    }

    /** Null αν ο αριθμός δεν είναι ελληνικός αριθμός που το Gateway δέχεται. */
    public static function forPhone(string $type, string $rawPhone, int $now): ?self
    {
        if (! in_array($type, [self::OPT_OUT, self::ERASED], true)) {
            return null;
        }

        $phone = self::e164($rawPhone);

        return $phone === null ? null : new self($type, $phone, $now);
    }

    /**
     * Η μορφή που κρατά το Gateway (+30 και 10 ψηφία, κινητά 69… ή σταθερά 2…).
     *
     * K11: ο αριθμός περνά πρώτα από τον ΕΝΑ κανόνα του CRM (`PhoneNumber::key()`),
     * και μόνο μετά παίρνει τη μορφή του Gateway. Κανένα δεύτερο «ίδιο ή όχι».
     */
    public static function e164(string $raw): ?string
    {
        $key = PhoneNumber::key($raw);

        return preg_match('/^(69\d{8}|2\d{9})$/', $key) === 1 ? '+30' . $key : null;
    }

    /** Ένα γεγονός ανά είδος και αριθμό: δεύτερο ίδιο δεν χρειάζεται. */
    public function key(): string
    {
        return $this->type . ':' . hash('sha256', $this->phone);
    }

    /** @return array{type: string, phone: string, at: int} */
    public function toArray(): array
    {
        return ['type' => $this->type, 'phone' => $this->phone, 'at' => $this->at];
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): ?self
    {
        $type  = is_string($a['type'] ?? null) ? $a['type'] : '';
        $phone = is_string($a['phone'] ?? null) ? $a['phone'] : '';

        return self::forPhone($type, $phone, (int) ($a['at'] ?? 0));
    }

    public static function retryDelay(int $attempts): int
    {
        return self::DELAYS[min(max($attempts, 1), count(self::DELAYS)) - 1];
    }

    /** Μόνο «το γεγονός είναι λάθος»· όλα τα άλλα (και η αποτυχία δικτύου, 0) ξαναδοκιμάζονται. */
    public static function isPermanent(int $code): bool
    {
        return $code === 400 || $code === 422;
    }

    public static function delivered(int $code): bool
    {
        return $code >= 200 && $code < 300;
    }
}
