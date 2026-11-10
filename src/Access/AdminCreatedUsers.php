<?php

/**
 * (352c) Χρήστης που φτιάχνεται από το WordPress μπαίνει σε ομάδα.
 *
 * Από το CRM («Ομάδα» → νέο μέλος) ο νέος χρήστης έπαιρνε πάντα προϊστάμενο.
 * Από το wp-admin (Users → Add User) όχι: έμενε «χωρίς ομάδα», αόρατος στο CRM
 * και χωρίς τρόπο να πάρει παρόχους (09/11/2026, πέντε χρήστες στο live).
 *
 * Το `edit_user_created_user` τρέχει ΜΟΝΟ από τη φόρμα «Add User» του
 * wp-admin, όχι από το `wp_insert_user()` γενικά: το CRM, οι εισαγωγές και τα
 * τεστ δεν επηρεάζονται. Διαχειριστής δεν μπαίνει σε ομάδα· βλέπει τα πάντα.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Access;

use EnergyCRM\Persistence\TeamRepository;

final class AdminCreatedUsers
{
    public function __construct(private readonly TeamRepository $team)
    {
    }

    public function register(): void
    {
        add_action('edit_user_created_user', [$this, 'onCreated'], 10, 1);
    }

    public function onCreated(int $userId): void
    {
        $creator = get_current_user_id();
        $user    = get_userdata($userId);

        if ($creator <= 0 || ! $user || ! current_user_can('manage_options') || user_can($user, 'manage_options')) {
            return;
        }

        if (array_intersect((array) $user->roles, array_keys(Roles::matrix())) === []) {
            return;
        }

        if (! $this->team->hasManager($userId)) {
            $this->team->attach($userId, $creator);
        }
    }
}
