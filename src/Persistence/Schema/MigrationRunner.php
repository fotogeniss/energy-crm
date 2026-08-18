<?php

/**
 * Applies pending migrations, in order, once each.
 *
 * The record of what ran lives in a single option. A migration that throws
 * stops the run and is not recorded, so the next request retries it rather
 * than skipping ahead and leaving the schema in a state nobody described.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema;

use Throwable;

final class MigrationRunner
{
    public const OPTION = 'ecrm_applied_migrations';

    private const LOCK = 'ecrm_migrations_running';

    /** Αρκετά για το πιο αργό migration, αρκετά λίγο ώστε να ξεκολλήσει μόνο του. */
    private const LOCK_SECONDS = 120;

    /** @var list<Migration> */
    private array $migrations;

    private SchemaInspector $schema;

    /** @param list<Migration> $migrations */
    public function __construct(array $migrations, ?SchemaInspector $schema = null)
    {
        $this->migrations = $migrations;
        $this->schema     = $schema ?? new SchemaInspector();
    }

    /** @return list<Migration> */
    public function pending(): array
    {
        $applied = $this->applied();

        return array_values(array_filter(
            $this->migrations,
            static fn (Migration $m): bool => ! in_array($m->id(), $applied, true)
        ));
    }

    /**
     * Run everything outstanding.
     *
     * @return list<string> Ids applied during this run.
     */
    public function run(): array
    {
        if ($this->pending() === []) {
            return [];
        }

        // Το run() καλείται σε ΚΑΘΕ αίτηση. Μετά από deploy που φέρνει νέο
        // migration, οι πρώτες ταυτόχρονες αιτήσεις το βλέπουν όλες εκκρεμές και
        // το τρέχουν όλες. Τα seed migrations θα διπλασίαζαν γραμμές, και το
        // markApplied() είναι read-modify-write σε option: δύο ταυτόχρονοι
        // τερματισμοί χάνουν το ένα id και το migration ξανατρέχει.
        //
        // Το transient δεν είναι αδιάβλητο κλείδωμα — δεν υπάρχει atomic
        // set-if-absent με object cache. Κλείνει όμως το ρεαλιστικό παράθυρο,
        // που είναι μερικά δευτερόλεπτα μία φορά ανά deploy.
        if (get_transient(self::LOCK) !== false) {
            return [];
        }

        set_transient(self::LOCK, 1, self::LOCK_SECONDS);

        try {
            return $this->applyPending();
        } finally {
            delete_transient(self::LOCK);
        }
    }

    /**
     * @return list<string>
     */
    private function applyPending(): array
    {
        $done = [];

        foreach ($this->pending() as $migration) {
            try {
                $migration->apply($this->schema);
            } catch (Throwable $e) {
                // Leave it unrecorded so the next request tries again, and say
                // why in the log rather than failing the whole page load.
                error_log(sprintf(
                    '[Energy CRM] Το migration %s απέτυχε: %s',
                    $migration->id(),
                    $e->getMessage()
                ));

                break;
            }

            $this->markApplied($migration->id());
            $done[] = $migration->id();
        }

        return $done;
    }

    /**
     * Record every migration as done without running it.
     *
     * Used on a fresh install, where dbDelta has just created the tables in
     * their final shape and replaying historical changes would be noise.
     */
    public function markAllApplied(): void
    {
        update_option(
            self::OPTION,
            array_map(static fn (Migration $m): string => $m->id(), $this->migrations)
        );
    }

    /** @return list<string> */
    public function applied(): array
    {
        $stored = get_option(self::OPTION, []);

        return is_array($stored) ? array_values(array_map('strval', $stored)) : [];
    }

    private function markApplied(string $id): void
    {
        $applied   = $this->applied();
        $applied[] = $id;

        update_option(self::OPTION, array_values(array_unique($applied)));
    }
}
