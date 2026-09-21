<?php

/**
 * Ολοι οι υπάρχοντες χρήστες του CRM παίρνουν ΚΑΘΕ πάροχο -- μία φορά.
 *
 * ## Γιατί χρειάζεται
 *
 * Από το (278) ένας χρήστης χωρίς γραμμένη λίστα παρόχων δεν βλέπει κανέναν
 * (`ProviderGrantRepository::grantsOf()` -- το ασφαλές, για όποιον φτιαχτεί έξω
 * από τη ροή μας). Χωρίς αυτό το βήμα, την ημέρα που θα ανέβει η αλλαγή κάθε
 * πωλητής θα άνοιγε τη φόρμα και θα έβρισκε άδεια λίστα. Με αυτό, **δεν αλλάζει
 * τίποτα για κανέναν** μέχρι ο admin ή ένας manager να αφαιρέσει ρητά.
 *
 * ## Γιατί ΟΛΟΥΣ τους παρόχους και όχι μόνο τους ενεργούς
 *
 * Ενας ανενεργός πάροχος που ξαναενεργοποιείται αύριο θα ήταν αλλιώς «νέος» για
 * όλους -- μόνο ο admin θα τον έβλεπε (απόφαση ιδιοκτήτη 21/09 για τους νέους
 * παρόχους). Σήμερα όμως τον έβλεπαν όλοι όσο ήταν ενεργός, οπότε το «δεν
 * αλλάζει τίποτα» σημαίνει να τον κρατήσουν. Οσοι προστεθούν ΜΕΤΑ από αυτό το
 * βήμα είναι οι πραγματικά νέοι.
 *
 * ## Τι δεν αγγίζει
 *
 * - Τους admin: δεν έχουν λίστα, βλέπουν πάντα όλους.
 * - Οποιον έχει ήδη λίστα (έστω και άδεια): ένα δεύτερο τρέξιμο ή ένας χρήστης
 *   που φτιάχτηκε από τη νέα ροή δεν ξαναγεμίζει.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Access\Roles;
use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;
use EnergyCRM\Providers\Persistence\ProviderGrantRepository;

final class SeedProviderGrants implements Migration
{
    public function id(): string
    {
        return '0037_seed_provider_grants';
    }

    public function description(): string
    {
        return 'Ολοι οι υπάρχοντες χρήστες του CRM βλέπουν όλους τους παρόχους (αρχική λίστα)';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $table = Tables::name(Tables::PROVIDERS);

        if (! $schema->hasTable($table)) {
            return;
        }

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var list<string> $ids */
        $ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM %i ORDER BY id', [$table]));
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        $providerIds = array_map('intval', $ids);

        /** @var list<int|string> $users */
        $users = get_users([
            'role__in' => [Roles::PARTNER, Roles::SELLER],
            'fields'   => 'ID',
        ]);

        $grants = new ProviderGrantRepository();

        foreach ($users as $userId) {
            $userId = (int) $userId;

            if ($grants->has($userId) || user_can($userId, 'manage_options')) {
                continue;
            }

            $grants->set($userId, $providerIds);
        }
    }
}
