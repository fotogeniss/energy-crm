<?php

/**
 * Ο χάρτης καταστάσεων επιβιώνει στη βάση, και ο εισαγωγέας σέβεται τον πάροχο.
 *
 * Δύο πράγματα δοκιμάζονται εδώ που δεν μπορούν να δοκιμαστούν σε unit:
 *
 * 1. Ο χάρτης γράφεται και διαβάζεται από τη στήλη `providers.status_map`.
 * 2. **Το σφάλμα ταιριάσματος που βρέθηκε στις 28/08.** Ο αριθμός παροχής
 *    (ΗΚΑΣΠ) ανήκει στο σημείο κατανάλωσης, όχι στον πάροχο: πελάτης που
 *    άλλαξε πάροχο έχει ΔΥΟ συμβάσεις με τον ίδιο αριθμό. Η `apply()` έψαχνε
 *    `WHERE supply_number = %s ... LIMIT 1` χωρίς πάροχο και χωρίς `ORDER BY`,
 *    άρα διάλεγε αυθαίρετα — ένα αρχείο Protergia μπορούσε να ενημερώσει την
 *    παλιά σύμβαση ΔΕΗ του ίδιου σημείου.
 *
 * Το δεύτερο είναι ο λόγος που αυτό το αρχείο αξίζει περισσότερο από το unit:
 * το σφάλμα χρειάζεται **δύο πραγματικές γραμμές** στη βάση για να φανεί.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use ECRM_Import;
use EnergyCRM\Access\Roles;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\Tables;
use EnergyCRM\Providers\Domain\ProviderStatusMap;
use EnergyCRM\Providers\Persistence\ProviderStatusMapRepository;

final class ProviderStatusMapTest extends IntegrationTestCase
{
    private ContractRepository $contracts;

    private ProviderStatusMapRepository $maps;

    private int $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contracts = new ContractRepository();
        $this->maps      = new ProviderStatusMapRepository();
        $this->partner   = $this->makeCrmUser(Roles::SELLER);

        wp_set_current_user($this->partner);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    private function makeProvider(string $slug, string $name): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::PROVIDERS), [
            'slug'         => $slug,
            'name'         => $name,
            'energy_types' => 'power',
        ]);

        return (int) $wpdb->insert_id;
    }

    private function contractFor(int $providerId, string $supply, string $status): int
    {
        $id = $this->contracts->create(
            [
                'status'        => $status,
                'supply_number' => $supply,
                'energy_type'   => 'power',
                'provider_id'   => $providerId,
            ],
            UserScope::forSelf($this->partner)
        );

        self::assertGreaterThan(0, $id);

        return $id;
    }

    private function statusOf(int $contractId): string
    {
        return (string) $this->storedRow('contracts', $contractId)['status'];
    }

    // --- ο χάρτης στη βάση ---------------------------------------------------

    public function testAMapSurvivesAWriteAndARead(): void
    {
        $providerId = $this->makeProvider('protergia-test', 'Protergia');

        self::assertTrue($this->maps->find($providerId)->isEmpty());

        self::assertTrue($this->maps->save(
            $providerId,
            ProviderStatusMap::fromArray(['ΣΕ ΕΞΕΛΙΞΗ' => 'processing'])
        ));

        self::assertSame(['ΣΕ ΕΞΕΛΙΞΗ' => 'processing'], $this->maps->find($providerId)->toArray());
    }

    /** Αντικατάσταση, όχι συγχώνευση: τιμή που έσβησε ο χρήστης πρέπει να φύγει. */
    public function testSavingReplacesRatherThanMerges(): void
    {
        $providerId = $this->makeProvider('zenith-test', 'ΖΕΝΙΘ');

        $this->maps->save($providerId, ProviderStatusMap::fromArray(['Α' => 'active', 'Β' => 'pending']));
        $this->maps->save($providerId, ProviderStatusMap::fromArray(['Α' => 'active']));

        self::assertSame(['Α' => 'active'], $this->maps->find($providerId)->toArray());
    }

    public function testAnUnknownProviderIsRefusedRatherThanSilentlyIgnored(): void
    {
        self::assertFalse($this->maps->save(0, ProviderStatusMap::fromArray(['Α' => 'active'])));
    }

    // --- το σφάλμα ταιριάσματος ---------------------------------------------

    /**
     * Ο ίδιος αριθμός παροχής σε δύο παρόχους: η ενημέρωση πάει στον σωστό.
     *
     * Χωρίς το φίλτρο παρόχου αυτό το test θα περνούσε ή θα αποτύγχανε ανάλογα
     * με τη σειρά που τυχαίνει να επιστρέψει η MySQL — που είναι ακριβώς ο
     * ορισμός του σφάλματος που διορθώνεται.
     */
    public function testTheUpdateLandsOnTheContractOfTheChosenProvider(): void
    {
        $old = $this->makeProvider('dei-test', 'ΔΕΗ');
        $new = $this->makeProvider('protergia-test2', 'Protergia');

        $oldContract = $this->contractFor($old, '99900000001', 'active');
        $newContract = $this->contractFor($new, '99900000001', 'new');

        $report = ECRM_Import::apply(
            [['supply' => '99900000001', 'status' => 'processing']],
            false,
            $new
        );

        self::assertSame(1, $report['updated']);
        self::assertSame('processing', $this->statusOf($newContract));
        self::assertSame('active', $this->statusOf($oldContract), 'Η σύμβαση του άλλου παρόχου δεν αγγίχτηκε.');
    }

    /** Γραμμή για πάροχο που δεν έχει τέτοια παροχή δεν ταιριάζει με κανέναν άλλον. */
    public function testARowForTheWrongProviderMatchesNothing(): void
    {
        $one = $this->makeProvider('one-test', 'Ένας');
        $two = $this->makeProvider('two-test', 'Δύο');

        $contract = $this->contractFor($one, '99900000002', 'new');

        $report = ECRM_Import::apply(
            [['supply' => '99900000002', 'status' => 'processing']],
            false,
            $two
        );

        self::assertSame(0, $report['matched']);
        self::assertSame(1, $report['unmatched_total']);
        self::assertSame('new', $this->statusOf($contract));
    }

    /** Χωρίς επιλεγμένο πάροχο δεν μπαίνει φίλτρο — η παλιά ροή δεν σπάει. */
    public function testWithoutAProviderTheMatchIsUnrestricted(): void
    {
        $provider = $this->makeProvider('solo-test', 'Μόνος');
        $contract = $this->contractFor($provider, '99900000003', 'new');

        $report = ECRM_Import::apply([['supply' => '99900000003', 'status' => 'processing']], false, 0);

        self::assertSame(1, $report['updated']);
        self::assertSame('processing', $this->statusOf($contract));
    }
}
