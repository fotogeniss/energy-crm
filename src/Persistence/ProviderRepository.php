<?php

/**
 * The provider and tariff catalogue.
 *
 * Reference data: the same for every user, so no scope. It changes rarely and
 * is read on nearly every page, which makes it the obvious first candidate if
 * caching is ever needed.
 *
 * (278, 21/09) «Ιδιος για όλους» ισχύει για ΤΟΝ ΚΑΤΑΛΟΓΟ, όχι για το τι βλέπει
 * ο καθένας: ποιους παρόχους επιτρέπεται να δει ένας χρήστης το αποφασίζει το
 * `Access\ProviderVisibility`, στον controller, πάνω σε αυτό που γυρνά εδώ.
 * Εδώ δεν μπαίνει φίλτρο χρήστη σκόπιμα -- το ίδιο αποθετήριο το ρωτά και ο
 * admin για να ζωγραφίσει τη λίστα από την οποία δίνει.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

final class ProviderRepository
{
    /**
     * @return list<array<string, mixed>>
     */
    public function active(): array
    {
        global $wpdb;

        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, slug, name, energy_types, logo_url, brand_color
                 FROM %i WHERE active = 1 ORDER BY sort_order, name',
                Tables::name(Tables::PROVIDERS)
            ),
            ARRAY_A
        );

        return $rows;
    }

    /**
     * Τα id των ενεργών παρόχων, με τη σειρά του καταλόγου.
     *
     * @return list<int>
     */
    public function activeIds(): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->active()
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function activePrograms(): array
    {
        global $wpdb;

        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, provider_id, name, code, energy_type, category,
                        price_type, price_kwh, fixed_charge, pricing, price_updated, exit_fees
                 FROM %i WHERE active = 1 ORDER BY sort_order, name',
                Tables::name(Tables::PROGRAMS)
            ),
            ARRAY_A
        );

        return $rows;
    }

    /**
     * (328) Νέα τιμή προγράμματος από τη σελίδα Παρόχων, με ημερομηνία.
     */
    public function updatePrice(
        int $programId,
        int $providerId,
        ?float $priceKwh,
        ?float $fixedCharge,
        string $date
    ): void {
        global $wpdb;

        $wpdb->update(
            Tables::name(Tables::PROGRAMS),
            ['price_kwh' => $priceKwh, 'fixed_charge' => $fixedCharge, 'price_updated' => $date],
            ['id' => $programId, 'provider_id' => $providerId]
        );
    }

    /**
     * (331) Ρήτρα αποχώρησης, € ανά μήνα· κενή λίστα τη σβήνει.
     *
     * @param list<int> $fees
     */
    public function updateExitFees(int $programId, int $providerId, array $fees): void
    {
        global $wpdb;

        $wpdb->update(
            Tables::name(Tables::PROGRAMS),
            ['exit_fees' => $fees === [] ? null : (string) json_encode($fees)],
            ['id' => $programId, 'provider_id' => $providerId]
        );
    }

    /**
     * (335) Προγράμματα με id, ενεργά ή όχι -- το πρόγραμμα μιας σύμβασης που
     * λήγει μπορεί να έχει αποσυρθεί, και η τιμή του χρειάζεται για σύγκριση.
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>> id => γραμμή
     */
    public function programsByIds(array $ids): array
    {
        global $wpdb;

        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0
        )));

        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, provider_id, name, code, energy_type, category,
                        price_type, price_kwh, fixed_charge, pricing, price_updated, exit_fees
                 FROM %i WHERE id IN ({$placeholders})",
                [Tables::name(Tables::PROGRAMS), ...$ids]
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row['id']] = $row;
        }

        return $out;
    }

    /** Το πρόγραμμα/ο πάροχος σβήστηκε: καμία αίτηση δεν τον είχε. */
    public const DELETED = 'deleted';

    /** Είχε αιτήσεις: απενεργοποιήθηκε αντί να σβηστεί. */
    public const RETIRED = 'retired';

    /**
     * (421) Διαγραφή προγράμματος από τη σελίδα «Πάροχοι».
     *
     * Το `contracts.program_id` είναι `ON DELETE SET NULL` και το
     * `commission_rules.program_id` `ON DELETE CASCADE`: μία διαγραφή άφηνε
     * κάθε αίτηση του προγράμματος χωρίς πρόγραμμα (έντυπο, τιμή, ρήτρα) και
     * έσβηνε τους κανόνες προμήθειας του. Αν υπάρχει έστω μία αίτηση, το
     * πρόγραμμα απενεργοποιείται: φεύγει από τη «Νέα αίτηση», οι παλιές το
     * κρατούν. Κανόνες K5, K7, K10.
     *
     * @return self::DELETED|self::RETIRED
     */
    public function deleteOrRetireProgram(int $programId): string
    {
        global $wpdb;

        $programs = Tables::name(Tables::PROGRAMS);

        if ($this->contractsUsing('program_id', [$programId]) > 0) {
            $wpdb->update($programs, ['active' => 0], ['id' => $programId]);

            return self::RETIRED;
        }

        $wpdb->delete($programs, ['id' => $programId]);

        return self::DELETED;
    }

    /**
     * (421) Το ίδιο για τον πάροχο. Ο πάροχος παίρνει μαζί του (CASCADE) όλα
     * τα προγράμματά του, άρα μετράνε οι αιτήσεις του παρόχου ΚΑΙ όσες
     * δείχνουν σε πρόγραμμά του. Αν υπάρχει έστω μία, απενεργοποιούνται ο
     * πάροχος και τα προγράμματά του, και δεν σβήνεται τίποτα.
     *
     * @return self::DELETED|self::RETIRED
     */
    public function deleteOrRetireProvider(int $providerId): string
    {
        global $wpdb;

        $programs  = Tables::name(Tables::PROGRAMS);
        $providers = Tables::name(Tables::PROVIDERS);

        /** @var list<string> $programIds */
        $programIds = $wpdb->get_col(
            $wpdb->prepare('SELECT id FROM %i WHERE provider_id = %d', $programs, $providerId)
        );
        $programIds = array_map('intval', $programIds);

        if (
            $this->contractsUsing('provider_id', [$providerId]) > 0
            || $this->contractsUsing('program_id', $programIds) > 0
        ) {
            $wpdb->update($providers, ['active' => 0], ['id' => $providerId]);
            $wpdb->update($programs, ['active' => 0], ['provider_id' => $providerId]);

            return self::RETIRED;
        }

        $wpdb->delete($programs, ['provider_id' => $providerId]);
        $wpdb->delete($providers, ['id' => $providerId]);

        return self::DELETED;
    }

    /**
     * Πόσες αιτήσεις (οποιασδήποτε κατάστασης) δείχνουν σε αυτά τα id.
     *
     * @param 'program_id'|'provider_id' $column
     * @param list<int>                  $ids
     */
    private function contractsUsing(string $column, array $ids): int
    {
        global $wpdb;

        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));

        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM %i WHERE %i IN ({$placeholders})",
                [Tables::name(Tables::CONTRACTS), $column, ...$ids]
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
    }
}
