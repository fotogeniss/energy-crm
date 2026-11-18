<?php

/**
 * (421) Πρόγραμμα ή πάροχος με αιτήσεις δεν διαγράφεται: απενεργοποιείται.
 *
 * Η διαγραφή άφηνε τις αιτήσεις χωρίς πρόγραμμα/πάροχο (`ON DELETE SET NULL`)
 * και έσβηνε τους κανόνες προμήθειας (`ON DELETE CASCADE`).
 *
 * @kanonas K5
 * @kanonas K7
 * @kanonas K10
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Persistence\ProviderRepository;
use EnergyCRM\Persistence\Tables;

final class ProgramWithContractsIsRetiredTest extends IntegrationTestCase
{
    private ProviderRepository $providers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->providers = new ProviderRepository();
    }

    public function testAProgramWithAContractIsRetiredAndKeepsItsRules(): void
    {
        [$providerId, $programId] = $this->catalogue();
        $contractId               = $this->contract($providerId, $programId);
        $ruleId                   = $this->rule($providerId, $programId);

        self::assertSame(ProviderRepository::RETIRED, $this->providers->deleteOrRetireProgram($programId));

        self::assertSame(0, (int) $this->storedRow('programs', $programId)['active']);
        self::assertSame($programId, (int) $this->storedRow('contracts', $contractId)['program_id']);
        self::assertTrue($this->exists(Tables::COMMISSION_RULES, $ruleId), 'Ο κανόνας προμήθειας σβήστηκε.');
    }

    public function testAProgramWithoutContractsIsDeleted(): void
    {
        [, $programId] = $this->catalogue();

        self::assertSame(ProviderRepository::DELETED, $this->providers->deleteOrRetireProgram($programId));
        self::assertFalse($this->exists(Tables::PROGRAMS, $programId));
    }

    public function testAProviderWhoseProgramHasAContractIsRetiredWithItsPrograms(): void
    {
        [$providerId, $programId] = $this->catalogue();
        // Η αίτηση δείχνει μόνο στο πρόγραμμα: ο πάροχος παίρνει μαζί του τα
        // προγράμματα (CASCADE), άρα μετράει κι αυτή.
        $contractId = $this->contract(null, $programId);

        self::assertSame(ProviderRepository::RETIRED, $this->providers->deleteOrRetireProvider($providerId));

        self::assertSame(0, (int) $this->storedRow('providers', $providerId)['active']);
        self::assertSame(0, (int) $this->storedRow('programs', $programId)['active']);
        self::assertSame($programId, (int) $this->storedRow('contracts', $contractId)['program_id']);
    }

    public function testAProviderWithoutContractsIsDeletedWithItsPrograms(): void
    {
        [$providerId, $programId] = $this->catalogue();

        self::assertSame(ProviderRepository::DELETED, $this->providers->deleteOrRetireProvider($providerId));
        self::assertFalse($this->exists(Tables::PROVIDERS, $providerId));
        self::assertFalse($this->exists(Tables::PROGRAMS, $programId));
    }

    // --- fixtures ----------------------------------------------------------

    private function exists(string $table, int $id): bool
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM %i WHERE id = %d', Tables::name($table), $id)
        ) === 1;
    }

    /** @return array{0: int, 1: int} [provider id, program id] */
    private function catalogue(): array
    {
        global $wpdb;

        $slug = 'dokimi-' . wp_generate_password(6, false, false);
        $wpdb->insert(Tables::name(Tables::PROVIDERS), [
            'name'         => 'Πάροχος ' . $slug,
            'slug'         => strtolower($slug),
            'energy_types' => 'power',
            'active'       => 1,
        ]);
        $providerId = (int) $wpdb->insert_id;

        $wpdb->insert(Tables::name(Tables::PROGRAMS), [
            'provider_id' => $providerId,
            'name'        => 'Πρόγραμμα ' . $slug,
            'energy_type' => 'power',
            'active'      => 1,
        ]);

        return [$providerId, (int) $wpdb->insert_id];
    }

    private function contract(?int $providerId, int $programId): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::CONTRACTS), [
            'provider_id' => $providerId,
            'program_id'  => $programId,
            'status'      => 'draft',
            'code'        => 'ΕΝ-RET-' . $programId,
            'energy_type' => 'power',
        ]);

        return (int) $wpdb->insert_id;
    }

    private function rule(int $providerId, int $programId): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::COMMISSION_RULES), [
            'provider_id' => $providerId,
            'program_id'  => $programId,
            'energy_type' => 'power',
            'amount'      => 40,
        ]);

        return (int) $wpdb->insert_id;
    }
}
