<?php

/**
 * Τι γίνεται με το ίδιο το lead όταν κάποιος πει «δεν θέλει κλήσεις».
 *
 * Ο αριθμός πάει πάντα στο Gateway (αυτό δεν είναι απόφαση, είναι υποχρέωση).
 * Εδώ κρίνεται μόνο η κατάσταση του lead: ένας υποψήφιος που ήδη έγινε πελάτης
 * (`won`) δεν γίνεται «χαμένος» επειδή ζήτησε να μην τον καλούν· οι υπόλοιποι
 * κλείνουν ως χαμένοι με σαφή λόγο, ώστε να μη ξαναεμφανιστούν σε λίστα κλήσεων.
 *
 * Καθαρή κλάση, χωρίς WordPress.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\GatewayIntake\Domain;

final class DoNotCallDecision
{
    public const LOST_REASON = 'Δεν θέλει κλήσεις';

    private const KEEP_STAGE = 'won';

    private function __construct()
    {
    }

    /**
     * Οι αλλαγές στο lead, ή κενός πίνακας όταν δεν αλλάζει τίποτα.
     *
     * @return array{stage: string, lost_reason: string}|array{}
     */
    public static function leadChanges(string $currentStage): array
    {
        if ($currentStage === self::KEEP_STAGE) {
            return [];
        }

        return ['stage' => 'lost', 'lost_reason' => self::LOST_REASON];
    }
}
