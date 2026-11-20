<?php

/**
 * (437) Η προεπισκόπηση της εισαγωγής Excel λέει ό,τι θα κάνει η εισαγωγή.
 *
 * Ο γράφος ελεγχόταν πριν από το dry/live, όχι όμως τα χαρτιά και η υπογραφή
 * (`PaperworkGate`). Αίτηση χωρίς δικαιολογητικά μετρούσε στην προεπισκόπηση
 * ως «θα ενημερωθεί» και η πραγματική εισαγωγή μετά την αρνιόταν.
 *
 * @kanonas K4
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use ECRM_Import;
use EnergyCRM\Access\Roles;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;

final class ImportPreviewTellsTheTruthTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    public function testAContractWithoutPapersIsRejectedInThePreviewToo(): void
    {
        $importer = $this->makeCrmUser(Roles::PARTNER);
        $id       = (new ContractRepository())->create(
            ['status' => 'registration', 'supply_number' => '33300000001', 'energy_type' => 'power'],
            UserScope::forSelf($importer)
        );
        self::assertGreaterThan(0, $id);

        wp_set_current_user($importer);

        $rows    = [['supply' => '33300000001', 'status' => 'finalisation']];
        $preview = ECRM_Import::apply($rows, true);
        $live    = ECRM_Import::apply($rows, false);

        self::assertSame(0, $preview['updated'], 'Η προεπισκόπηση υποσχέθηκε ενημέρωση που δεν θα γίνει.');
        self::assertSame(['33300000001'], $preview['rejected']);
        self::assertSame($live['updated'], $preview['updated']);
        self::assertSame($live['rejected'], $preview['rejected']);
        self::assertSame('registration', (string) $this->storedRow('contracts', $id)['status']);
    }
}
