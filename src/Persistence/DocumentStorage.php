<?php

/**
 * Πού ζουν τα bytes ενός εγγράφου, και τι μετράει ως «μέσα».
 *
 * Δύο καθαρές συναρτήσεις, χωρίς βάση και χωρίς κατάσταση. Βγήκαν εδώ επειδή
 * τις χρειάζονται δύο κλάσεις: ο `FileRepository`, που διαγράφει bytes μαζί με
 * τη γραμμή τους, και το `UnprotectedDocuments`, που τα μετακομίζει από τη
 * media library. Αντίγραφο σε καθεμιά θα ήταν δύο εκδοχές του τι σημαίνει
 * «ασφαλής διαδρομή» — και η μία θα έμενε πίσω.
 *
 * ## Γιατί στο Persistence και όχι στο Infrastructure
 *
 * Ο πίνακας namespaces δίνει τα αρχεία στο `Infrastructure`, και αυτό είναι
 * σωστό για ό,τι αγγίζει δίσκο. Εδώ όμως δεν αγγίζεται δίσκος παρά μόνο για
 * `realpath()`: είναι λογική διαδρομών, συνεργάτης δύο κλάσεων που ζουν και οι
 * δύο στο `Persistence`. Μετακόμιση σε άλλο namespace θα πρόσθετε εξάρτηση
 * μεταξύ layers για δύο συναρτήσεις χωρίς καμία εξάρτηση οι ίδιες.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

final class DocumentStorage
{
    private string $dir;

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/\\');
    }

    /** An unguessable name inside the protected directory. */
    public function newPath(string $extension): string
    {
        $safe = (string) preg_replace('/[^a-z0-9]/i', '', $extension);

        return $this->dir . DIRECTORY_SEPARATOR
            . 'doc_' . wp_generate_password(24, false) . '.' . ($safe !== '' ? $safe : 'bin');
    }

    /**
     * Never unlink — or hand out — a path just because a database row said so.
     *
     * The column holds an absolute path, and a tampered or mis-migrated row
     * could point anywhere on the filesystem. Only paths that resolve inside
     * the plugin's own storage directory are touched.
     *
     * `realpath()` on both sides is what makes it a containment check rather
     * than a string prefix: without it, `/storage/../etc/passwd` would pass.
     */
    public function contains(string $path): bool
    {
        $resolved = realpath($path);
        $base     = realpath($this->dir);

        if ($resolved === false || $base === false) {
            return false;
        }

        return str_starts_with($resolved, $base . DIRECTORY_SEPARATOR);
    }
}
