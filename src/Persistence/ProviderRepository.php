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
                'SELECT id, slug, name, energy_types, logo_url
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
                        price_type, price_kwh, fixed_charge
                 FROM %i WHERE active = 1 ORDER BY sort_order, name',
                Tables::name(Tables::PROGRAMS)
            ),
            ARRAY_A
        );

        return $rows;
    }
}
