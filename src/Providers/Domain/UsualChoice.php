<?php

/**
 * Ο συνδυασμός «πάροχος + είδος + πρόγραμμα» που βάζει συνήθως ένας πωλητής.
 *
 * ## Τι πρόβλημα λύνει
 *
 * Κάθε νέα αίτηση ξεκινά κενή. Ο πωλητής που δουλεύει καθημερινά με τον ίδιο
 * πάροχο ξανακάνει τα ίδια δύο κλικ σε κάθε αίτηση — διάλεξε πάροχο, διάλεξε
 * πρόγραμμα — για κάτι που δεν αλλάζει ποτέ. Λίστα «Λιγότερο πληκτρολόγιο»
 * του ιδιοκτήτη, σημείο 4.
 *
 * ## Γιατί υπολογισμός και όχι αποθηκευμένη ρύθμιση
 *
 * Ρητή απόφαση ιδιοκτήτη, 28/08/2026: **αυτόματα από το ιστορικό**, όχι οθόνη
 * ρυθμίσεων. Μια ρύθμιση θέλει στήλη, οθόνη, και έναν άνθρωπο να τη γεμίσει —
 * και μετά ξεχνιέται όταν αλλάξει η δουλειά. Ο υπολογισμός από τις ίδιες τις
 * συμβάσεις δεν θέλει τίποτα από αυτά και **προσαρμόζεται μόνος του**: αν ο
 * πωλητής γυρίσει σε άλλον πάροχο, μετά από λίγες αιτήσεις αλλάζει η πρόταση.
 *
 * ## Γιατί υπάρχει κατώφλι
 *
 * Το `MIN_TIMES` είναι ο λόγος που αυτή η κλάση υπάρχει καθόλου αντί για ένα
 * σκέτο array από το repository. «Συνήθως βάζεις Χ» πάνω σε **μία** σύμβαση
 * δεν είναι πρόταση, είναι ψέμα — και ψέμα που ο πωλητής θα πατήσει, γιατί το
 * κουμπί είναι εκεί. Κάτω από το κατώφλι δεν εμφανίζεται τίποτα.
 *
 * Καθαρό PHP, μηδέν WordPress, μηδέν SQL — τρέχει σε unit test χωρίς βάση και
 * μεταφέρεται αυτούσιο (§1.12). Το repository μετράει· **εδώ** αποφασίζεται αν
 * το μέτρημα λέει κάτι.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Providers\Domain;

final class UsualChoice
{
    /**
     * Πόσες από τις τελευταίες συμβάσεις του πωλητή κοιτάζονται.
     *
     * Είκοσι είναι αρκετές για να φανεί συνήθεια και αρκετά λίγες ώστε μια
     * αλλαγή παρόχου να φανεί μέσα σε λίγες μέρες, όχι σε μήνες.
     */
    public const SAMPLE = 20;

    /** Κάτω από τρεις φορές δεν λέγεται «συνήθως». Βλ. docblock. */
    public const MIN_TIMES = 3;

    private function __construct(
        private readonly int $providerId,
        private readonly string $energyType,
        private readonly ?int $programId,
        private readonly int $times,
        private readonly int $outOf,
    ) {
    }

    /** Καμία πρόταση: νέος πωλητής, ή καμία συνήθεια αρκετά καθαρή. */
    public static function none(): self
    {
        return new self(0, '', null, 0, 0);
    }

    /**
     * Από το μέτρημα του repository — εδώ εφαρμόζεται το κατώφλι.
     *
     * Το `$outOf` δεν κρίνει τίποτα· ταξιδεύει μόνο για να μπορεί η οθόνη να
     * πει «14 από τις τελευταίες 20» αντί για ένα σκέτο «συνήθως», που δεν
     * λέει στον πωλητή πόσο ισχυρή είναι η συνήθεια που του προτείνεται.
     */
    public static function from(
        int $providerId,
        string $energyType,
        ?int $programId,
        int $times,
        int $outOf
    ): self {
        if ($providerId <= 0 || $energyType === '' || $times < self::MIN_TIMES) {
            return self::none();
        }

        return new self($providerId, $energyType, $programId, $times, $outOf);
    }

    public function isKnown(): bool
    {
        return $this->providerId > 0;
    }

    /**
     * Ό,τι χρειάζεται η οθόνη — `null` όταν δεν υπάρχει πρόταση.
     *
     * Ονόματα παρόχου και προγράμματος **δεν** επιστρέφονται: η φόρμα έχει ήδη
     * ολόκληρο τον κατάλογο στο ίδιο response και τα βρίσκει μόνη της. Ένα
     * `JOIN` εδώ θα έστελνε δεύτερη φορά κείμενο που ταξιδεύει ήδη.
     *
     * @return array{provider_id: int, energy_type: string, program_id: int|null, times: int, of: int}|null
     */
    public function toArray(): ?array
    {
        if (! $this->isKnown()) {
            return null;
        }

        return [
            'provider_id' => $this->providerId,
            'energy_type' => $this->energyType,
            'program_id'  => $this->programId,
            'times'       => $this->times,
            'of'          => $this->outOf,
        ];
    }
}
