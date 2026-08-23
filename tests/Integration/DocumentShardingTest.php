<?php

/**
 * Ο κάδος `Y/m` της προστατευμένης αποθήκευσης, και ο φύλακας που τον δέχεται.
 *
 * Μέχρι τις 2026-08-23 κάθε έγγραφο πελάτη — ταυτότητες, λογαριασμοί,
 * παραγόμενα PDF, εικόνες υπογραφών — γραφόταν σε έναν επίπεδο φάκελο. Με τον
 * ρυθμό που εκτιμήθηκε στο `docs/DOC-SHARDING-PROPOSAL.html` αυτό γίνεται
 * φάκελος εκατοντάδων χιλιάδων αρχείων.
 *
 * Αυτά τα test φυλάνε τρία πράγματα που, αν σπάσουν, σπάνε **σιωπηλά**:
 *
 *   1. ότι ο κάδος υπάρχει πριν γράψει κανείς μέσα του — αλλιώς η αποτυχία
 *      εμφανίζεται την 1η κάθε μήνα, στην παραγωγή, ως χαμένο ανέβασμα·
 *   2. ότι ο φύλακας `contains()` δέχεται **και** τις παλιές επίπεδες
 *      διαδρομές — αυτός είναι ολόκληρος ο λόγος που δεν χρειάστηκε migration
 *      των υπαρχόντων αρχείων· αν σφίξει σε σύγκριση γονέα, τα παλιά έγγραφα
 *      σταματούν να σβήνονται σε αίτημα διαγραφής, χωρίς κανένα σφάλμα·
 *   3. ότι το `ECRM_Files` δεν ξαναρχίζει να χτίζει διαδρομές μόνο του.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use ECRM_Files;
use EnergyCRM\Persistence\DocumentStorage;

final class DocumentShardingTest extends IntegrationTestCase
{
    private DocumentStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = new DocumentStorage(ECRM_Files::dir());
    }

    /** Ο κάδος του τρέχοντος μήνα, όπως τον βλέπει το ρολόι του site. */
    private static function bucket(): string
    {
        return DIRECTORY_SEPARATOR . (string) current_time('Y')
            . DIRECTORY_SEPARATOR . (string) current_time('m') . DIRECTORY_SEPARATOR;
    }

    public function testNewPathPutsTheDocumentInAMonthlyBucket(): void
    {
        self::assertStringContainsString(self::bucket(), $this->storage->newPath('pdf'));
    }

    /**
     * Ο φάκελος πρέπει να υπάρχει, όχι απλώς να αναφέρεται.
     *
     * Χωρίς αυτό, το `move_uploaded_file()` επιστρέφει `false` και το ανέβασμα
     * αποτυγχάνει — την πρώτη μέρα κάθε μήνα, μόνο στην παραγωγή, μόνο για τον
     * πρώτο που θα ανεβάσει κάτι.
     */
    public function testTheBucketExistsBeforeAnyoneWritesInIt(): void
    {
        self::assertDirectoryExists(dirname($this->storage->newPath('pdf')));
    }

    public function testContainsAcceptsAPathInsideTheBucket(): void
    {
        $path = $this->storage->newPath('jpg');
        file_put_contents($path, 'bytes');

        self::assertTrue($this->storage->contains($path));
    }

    /**
     * Η απόδειξη ότι δεν χρειάστηκε migration.
     *
     * Τα ήδη γραμμένα έγγραφα κάθονται επίπεδα στη ρίζα και η στήλη `path`
     * τους δεν άλλαξε. Αν αυτό το test κοκκινίσει, κάθε ένα από αυτά έγινε
     * αόρατο για τη διαγραφή και για το σερβίρισμα την ίδια στιγμή.
     */
    public function testContainsStillAcceptsALegacyFlatPath(): void
    {
        $flat = rtrim(ECRM_Files::dir(), '/\\') . DIRECTORY_SEPARATOR
            . 'doc_legacy_' . wp_generate_password(8, false) . '.jpg';

        file_put_contents($flat, 'bytes');

        self::assertTrue($this->storage->contains($flat));
    }

    /** Ο κάδος δεν χαλάρωσε τον έλεγχο περιέκτη. */
    public function testContainsStillRefusesAPathOutsideTheStorage(): void
    {
        $outside = tempnam(sys_get_temp_dir(), 'ecrm');
        self::assertIsString($outside);

        try {
            self::assertFalse($this->storage->contains($outside));
        } finally {
            unlink($outside);
        }
    }

    /**
     * Το `ECRM_Files` δεν χτίζει πια δικές του διαδρομές.
     *
     * Είχε αντιγραμμένη τη λογική του `newPath()` σε δύο σημεία, και μόνο το
     * `UnprotectedDocuments` περνούσε από το `DocumentStorage`. Χωρίς αυτό το
     * test, ο κάδος θα ίσχυε για τα μετακομισμένα legacy αρχεία και **όχι**
     * για ό,τι ανεβάζει ή παράγει το ίδιο το plugin — μισή διόρθωση που
     * φαίνεται ολόκληρη.
     */
    public function testPutBytesGoesThroughTheSameBucket(): void
    {
        $saved = ECRM_Files::put_bytes('fixture bytes', 'jpg', 'image/jpeg', 'x.jpg');

        self::assertIsArray($saved);
        self::assertStringContainsString(self::bucket(), (string) $saved['path']);
    }
}
