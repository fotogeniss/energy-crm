<?php

/**
 * Η βάση, και όχι ο κώδικας, εγγυάται ότι ένα code ανήκει σε ένα πρόγραμμα.
 *
 * Στις 04/09/2026 το `SeedVoltonPlans` έγραψε 23 προγράμματα δύο φορές. Ο
 * έλεγχος «υπάρχει ήδη;» μέσα στον βρόχο ήταν σωστός και δεν βοήθησε: το
 * `MigrationRunner::run()` καλείται σε κάθε αίτηση, δύο ταυτόχρονες αιτήσεις
 * είδαν και οι δύο μηδέν και έγραψαν και οι δύο.
 *
 * Αυτά τα tests δεν στήνουν την κούρσα — δεν γίνεται αξιόπιστα μέσα σε μία
 * διεργασία. Ελέγχουν το μόνο πράγμα που θα την είχε σταματήσει: ότι η βάση
 * λέει «όχι» στο δεύτερο γράψιμο. Και ότι λέει «ναι» εκεί που πρέπει, γιατί
 * ένας υπερβολικά σφιχτός index θα έσπαγε τους υπόλοιπους παρόχους.
 *
 * Κανένα τους δεν αγγίζει DDL: ένα ALTER εδώ θα έκανε implicit commit και θα
 * ακύρωνε την transaction που απομονώνει κάθε test.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class ProgramCodeIsUniquePerProviderTest extends IntegrationTestCase
{
    public function testTheUniqueIndexIsInPlace(): void
    {
        $schema = new SchemaInspector();

        self::assertTrue(
            $schema->hasIndex(Tables::name(Tables::PROGRAMS), 'provider_code'),
            'Χωρίς αυτό το index, δύο ταυτόχρονα seed ξαναγράφουν τα ίδια προγράμματα.'
        );
    }

    /** Η κούρσα του 0028, όπως θα κατέληγε σήμερα: το δεύτερο γράψιμο απορρίπτεται. */
    public function testASecondRowWithTheSameCodeIsRefused(): void
    {
        $provider = $this->makeProvider('acme-power');

        self::assertNotFalse($this->makeProgram($provider, 'acme_flat_18m'));
        self::assertFalse($this->makeProgram($provider, 'acme_flat_18m'));
    }

    /**
     * Τα γενικά starters των υπόλοιπων παρόχων δεν έχουν code. Αν ο index τα
     * θεωρούσε διπλά, η διόρθωση μιας ζημιάς θα έφτιαχνε μεγαλύτερη.
     */
    public function testRowsWithoutACodeAreNotConsideredDuplicates(): void
    {
        $provider = $this->makeProvider('acme-gas');

        self::assertNotFalse($this->makeProgram($provider, null));
        self::assertNotFalse($this->makeProgram($provider, null));
    }

    /** Το ίδιο code σε άλλον πάροχο είναι άλλο πρόγραμμα, όχι διπλό. */
    public function testTheSameCodeUnderAnotherProviderIsAllowed(): void
    {
        self::assertNotFalse($this->makeProgram($this->makeProvider('acme-one'), 'shared_code'));
        self::assertNotFalse($this->makeProgram($this->makeProvider('acme-two'), 'shared_code'));
    }

    private function makeProvider(string $slug): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::PROVIDERS), [
            'slug'         => $slug,
            'name'         => strtoupper($slug),
            'energy_types' => 'power,gas',
            'active'       => 1,
            'sort_order'   => 99,
        ]);

        return (int) $wpdb->insert_id;
    }

    /** @return int|false Ό,τι επιστρέφει το insert -- false σημαίνει «η βάση είπε όχι». */
    private function makeProgram(int $providerId, ?string $code)
    {
        global $wpdb;

        return $wpdb->insert(Tables::name(Tables::PROGRAMS), [
            'provider_id' => $providerId,
            'name'        => 'Δοκιμαστικό πρόγραμμα',
            'code'        => $code,
            'energy_type' => 'power',
            'category'    => 'home',
            'price_type'  => 'fixed',
            'active'      => 1,
            'sort_order'  => 1,
        ]);
    }
}
