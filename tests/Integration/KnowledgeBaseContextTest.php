<?php

/**
 * (367b) Τι υλικό από τη Βάση Γνώσης παίρνει ο AIde για μια ερώτηση.
 *
 * Ο κανόνας μέχρι τις 12/11 κρατούσε μόνο λέξεις άνω των 3 γραμμάτων, οπότε
 * «nrg» ή «ΔΕΗ» δεν ψάχνονταν καθόλου και ο AIde έπαιρνε τις πρώτες τυχαίες
 * ενότητες. Και με όνομα παρόχου μαζί με γενικές λέξεις, οι ενότητες άλλων
 * παρόχων γέμιζαν το όριο πριν φτάσει ο πάροχος που ρωτήθηκε.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use ECRM_KB;
use EnergyCRM\Persistence\Tables;

final class KnowledgeBaseContextTest extends IntegrationTestCase
{
    private string $tag = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tag = 'q' . wp_generate_password(6, false, false);

        // Δεκαπέντε ενότητες άλλου παρόχου που ταιριάζουν στη γενική λέξη:
        // περισσότερες από το όριο των 14.
        for ($i = 0; $i < 15; $i++) {
            $this->entry('Άλλος πάροχος', 'Χαρτιά ' . $i, 'Χρειάζονται χαρτιά ' . $this->tag);
        }

        $this->entry('Zx' . $this->tag, 'Δικαιολογητικά Zx', 'Ταυτότητα και λογαριασμός για Zx.');
    }

    public function testAShortProviderNameIsSearchedFor(): void
    {
        $context = ECRM_KB::context_for('zx' . $this->tag);

        self::assertStringContainsString('Δικαιολογητικά Zx', $context);
    }

    public function testTheNamedProviderComesBeforeOtherMatches(): void
    {
        $context = ECRM_KB::context_for('Τι χαρτιά ' . $this->tag . ' θέλει η zx' . $this->tag . ';');

        self::assertStringContainsString(
            'Δικαιολογητικά Zx',
            $context,
            'Ο πάροχος της ερώτησης έμεινε έξω από το όριο.'
        );
        self::assertLessThan(
            strpos($context, 'Χαρτιά 0'),
            strpos($context, 'Δικαιολογητικά Zx'),
            'Ο πάροχος της ερώτησης πρέπει να είναι πρώτος.'
        );
    }

    public function testThreeLetterWordsCountButLinkingWordsDoNot(): void
    {
        // «για» και «της» είναι τριγράμματα αλλά όχι λέξεις αναζήτησης: αν
        // μετρούσαν, θα ταίριαζαν σχεδόν με όλες τις ενότητες.
        $context = ECRM_KB::context_for('για της zx' . $this->tag);

        self::assertStringContainsString('Δικαιολογητικά Zx', $context);
        self::assertStringNotContainsString('Χαρτιά 0', $context);
    }

    private function entry(string $provider, string $title, string $body): void
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::KB_ENTRIES), [
            'provider_name' => $provider,
            'section'       => 'docs',
            'title'         => $title,
            'body'          => $body,
        ]);
        self::assertGreaterThan(0, (int) $wpdb->insert_id, 'Η ενότητα δεν μπήκε.');
    }
}
