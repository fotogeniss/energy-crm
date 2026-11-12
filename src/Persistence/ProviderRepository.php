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
}
