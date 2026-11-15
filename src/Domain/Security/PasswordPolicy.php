<?php

/**
 * (388) Ποιος νέος κωδικός γίνεται δεκτός. docs/UI-PROFILE-SECURITY.html.
 *
 * Τρεις κανόνες, ίδιοι με αυτούς που βλέπει ο χρήστης κάτω από το πεδίο:
 * τουλάχιστον 10 χαρακτήρες, όχι ίδιος με το email ή το όνομα (ούτε να τα
 * περιέχει), όχι από τους πιο συνηθισμένους. Κανένας κανόνας «ένα κεφαλαίο,
 * ένα σύμβολο»: μακρύς και ασυνήθιστος κωδικός αντέχει περισσότερο από
 * σύντομο με σύμβολα, και τον θυμάται κανείς.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Security;

final class PasswordPolicy
{
    public const MIN_LENGTH = 10;

    /** Πάνω από αυτό, κανείς δεν πληκτρολογεί κωδικό· είναι επικόλληση σκουπιδιών. */
    public const MAX_LENGTH = 200;

    /** Μέρη ονόματος/email κάτω από αυτό το μήκος δεν ελέγχονται (π.χ. «ko»). */
    private const MIN_PART = 3;

    /**
     * Οι πιο συνηθισμένοι κωδικοί με 10+ χαρακτήρες (λίστες διαρροών), και
     * ελληνικές παραλλαγές σε λατινικά. Συγκρίνονται χωρίς κεφαλαία.
     */
    private const COMMON = [
        '1234567890', '12345678910', '0123456789', '1111111111', '0000000000',
        '1q2w3e4r5t', 'qwertyuiop', 'qwerty1234', 'qwerty12345', 'asdfghjkl1',
        'password12', 'password123', 'password1234', 'passw0rd123', 'iloveyou12',
        'abcdefghij', 'abc1234567', 'zxcvbnm123', 'qazwsxedc1', 'football123',
        'princess123', 'welcome123', 'admin12345', 'administrator', 'letmein123',
        'sunshine123', 'monkey12345', 'dragon12345', 'baseball123', 'superman123',
        'kodikos123', 'kodikos1234', 'agapi12345', 'olympiakos1', 'olympiakos7',
        'panathinaikos', 'panathinaikos13', 'paok123456', 'paok1926', 'aek1924aek',
        'kalimera123', 'ellada1234', 'energy1234', 'energycrm1', 'energycrm123',
    ];

    private function __construct()
    {
    }

    /**
     * @param list<string> $personal Email, όνομα, επώνυμο, όνομα χρήστη.
     * @return string|null Ο λόγος σε μία πρόταση, ή null όταν γίνεται δεκτός.
     */
    public static function problem(string $password, array $personal = []): ?string
    {
        $length = mb_strlen($password);

        if ($length < self::MIN_LENGTH) {
            return sprintf('Ο κωδικός θέλει τουλάχιστον %d χαρακτήρες.', self::MIN_LENGTH);
        }

        if ($length > self::MAX_LENGTH) {
            return sprintf('Ο κωδικός μπορεί να έχει έως %d χαρακτήρες.', self::MAX_LENGTH);
        }

        $lower = mb_strtolower($password);

        foreach (self::parts($personal) as $part) {
            if (str_contains($lower, $part)) {
                return 'Ο κωδικός δεν πρέπει να περιέχει το email ή το όνομά σου.';
            }
        }

        if (in_array($lower, self::COMMON, true) || self::isOneRepeatedCharacter($lower)) {
            return 'Αυτός ο κωδικός είναι από τους πιο συνηθισμένους. Διάλεξε άλλον.';
        }

        return null;
    }

    /**
     * Τα κομμάτια που δεν πρέπει να περιέχει: το μέρος του email πριν το
     * «@», και κάθε λέξη του ονόματος.
     *
     * @param list<string> $personal
     * @return list<string>
     */
    private static function parts(array $personal): array
    {
        $out = [];

        foreach ($personal as $value) {
            $value = mb_strtolower(trim($value));

            if ($value === '') {
                continue;
            }

            // Από το email μόνο το μέρος πριν το «@»: το domain («gmail»,
            // «com») θα απέκλειε κωδικούς χωρίς λόγο.
            $at = mb_strpos($value, '@');

            if ($at !== false) {
                $value = mb_substr($value, 0, $at);
            }

            $out[] = $value;

            foreach (preg_split('/[\s._\-]+/u', $value) ?: [] as $word) {
                $out[] = $word;
            }
        }

        return array_values(array_unique(array_filter(
            $out,
            static fn (string $p): bool => mb_strlen($p) >= self::MIN_PART
        )));
    }

    private static function isOneRepeatedCharacter(string $password): bool
    {
        return count(array_unique(mb_str_split($password))) === 1;
    }
}
