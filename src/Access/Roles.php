<?php

/**
 * Which role may do what.
 *
 * Until now the three roles were decorative: all of them carried `ecrm_use`
 * and nothing else, so a Πωλητής could delete contracts, read the whole
 * commission ledger and run an Excel import. The matrix below is the only
 * place that changes when the business decides otherwise.
 *
 * The reading behind it:
 *   Συνεργάτης — runs a downline. Everything except site administration.
 *   Πωλητής    — sells. Owns leads and contracts, sees their own commission,
 *                can delete their own work, cannot reassign someone else's
 *                (that moves money between people, not just a record).
 *                (v6: this is also what "Καταχωρητής" used to mean — one
 *                role now, not two.)
 *
 * (v5, 25/08): DELETE_CONTRACT is now scope-gated for everyone, not
 * role-gated for nobody-but-admin. "Ο καθένας σβήνει τα δικά του" — an
 * explicit owner decision, not a default. The scope check (Guards +
 * Scopes::forCurrentUser()) is what stops anyone from deleting outside
 * their own downline; the capability alone was never the real boundary.
 * See docs/CHANGELOG.md (127).
 *
 * (v6, 25/08): REGISTRAR retired. The owner confirmed Πωλητής and
 * Καταχωρητής did the same real job — the split never meant anything in
 * practice. Every ecrm_registrar user is moved to ecrm_seller by sync()
 * itself (see below) the moment this version ships; the role definition
 * is then deleted so it cannot come back with stale capabilities. The
 * REGISTRAR class constant stays defined so old references do not fatal,
 * but it is no longer in matrix() and must not be reintroduced there.
 * See docs/CHANGELOG.md (128).
 *
 * If that does not match how the company actually works, edit `matrix()` and
 * bump VERSION; nothing else needs to move.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Access;

final class Roles
{
    public const PARTNER   = 'ecrm_partner';
    public const SELLER    = 'ecrm_seller';
    public const REGISTRAR = 'ecrm_registrar';

    /** Bump whenever matrix() changes, so live sites pick the change up. */
    private const VERSION = '6';

    private const VERSION_OPTION = 'ecrm_roles_version';

    private function __construct()
    {
    }

    /**
     * @return array<string, array{label: string, capabilities: list<string>}>
     */
    public static function matrix(): array
    {
        return [
            self::PARTNER => [
                'label'        => 'Συνεργάτης',
                'capabilities' => [
                    Capability::USE_CRM,
                    Capability::CREATE_CONTRACT,
                    Capability::EDIT_CONTRACT,
                    // DELETE_CONTRACT (v4, 25/08): επαναφέρθηκε μετά από ρητή
                    // διόρθωση πολιτικής του ιδιοκτήτη — η v3 το αφαίρεσε
                    // εντελώς και άφησε τη διαγραφή αποκλειστικά σε
                    // διαχειριστή WordPress, κάτι που δεν ήταν ποτέ η πρόθεση
                    // (βλ. docs/CHANGELOG.md 127). Το scope (Guards +
                    // Scopes::forCurrentUser()) ήδη εμποδίζει έναν Συνεργάτη
                    // να σβήσει έξω από τη δική του downline — αυτό
                    // δοκιμάζεται ρητά από το
                    // ContractRestAccessTest::testAPartnerWithTheCapability...
                    // Ο πραγματικός κίνδυνος (ON DELETE CASCADE σβήνει και
                    // τον φάκελο υπογραφής μαζί με μια ήδη υπογεγραμμένη
                    // σύμβαση) ΔΕΝ λύνεται εδώ· χρειάζεται ξεχωριστή πύλη
                    // (βλ. build queue #15, DeletionGate) πριν θεωρηθεί
                    // πλήρως κλειστό.
                    Capability::DELETE_CONTRACT,
                    Capability::CHANGE_STATUS,
                    Capability::ASSIGN_CONTRACT,
                    Capability::MANAGE_TEAM,
                    Capability::VIEW_COMMISSIONS,
                    Capability::MANAGE_LEADS,
                    Capability::IMPORT_DATA,
                    Capability::EXPORT_DATA,
                    Capability::VIEW_ANALYTICS,
                ],
            ],
            self::SELLER => [
                'label'        => 'Πωλητής',
                'capabilities' => [
                    Capability::USE_CRM,
                    Capability::CREATE_CONTRACT,
                    Capability::EDIT_CONTRACT,
                    Capability::CHANGE_STATUS,
                    Capability::DELETE_CONTRACT,
                    Capability::VIEW_COMMISSIONS,
                    Capability::MANAGE_LEADS,
                    Capability::EXPORT_DATA,
                ],
            ],
        ];
    }

    /**
     * Capabilities granted to a role, or an empty list for unknown roles.
     *
     * @return list<string>
     */
    public static function capabilitiesFor(string $role): array
    {
        return self::matrix()[$role]['capabilities'] ?? [];
    }

    /**
     * (v6, 25/08) REGISTRAR no longer exists as a role. Any WordPress user
     * still carrying `ecrm_registrar` is moved to `ecrm_seller` — same real
     * job, per the owner — and the role definition itself is deleted so it
     * cannot linger with capabilities nobody is maintaining anymore. This
     * runs once per sync(), which itself only runs once per VERSION bump, so
     * it is cheap to leave in permanently: after the first run there is
     * nobody left with the old role and get_users() returns empty.
     */
    private static function retireRegistrarRole(): void
    {
        if (get_role(self::REGISTRAR) === null) {
            return;
        }

        $stragglers = get_users(['role' => self::REGISTRAR, 'fields' => 'ID']);

        foreach ($stragglers as $userId) {
            $user = get_user_by('id', (int) $userId);

            if ($user === false) {
                continue;
            }

            $user->remove_role(self::REGISTRAR);
            $user->add_role(self::SELLER);
        }

        remove_role(self::REGISTRAR);
    }

    /**
     * Create the roles and bring their capabilities in line with the matrix.
     *
     * add_role() is a no-op when the role exists, so an installed site would
     * otherwise keep whatever capabilities it was created with forever. Here
     * the set is recomputed: capabilities no longer in the matrix are removed
     * as well as added, which is what makes revoking one actually work.
     */
    public static function sync(): void
    {
        self::retireRegistrarRole();

        foreach (self::matrix() as $slug => $definition) {
            $role = get_role($slug);

            if ($role === null) {
                add_role($slug, $definition['label'], ['read' => true]);
                $role = get_role($slug);
            }

            if ($role === null) {
                continue;
            }

            foreach (Capability::all() as $capability) {
                if (in_array($capability, $definition['capabilities'], true)) {
                    $role->add_cap($capability);
                } else {
                    $role->remove_cap($capability);
                }
            }
        }

        // Administrators run the company and get everything.
        $administrator = get_role('administrator');

        if ($administrator !== null) {
            foreach (Capability::all() as $capability) {
                $administrator->add_cap($capability);
            }
        }

        update_option(self::VERSION_OPTION, self::VERSION);
    }

    /** Cheap check on every request; a single option read when up to date. */
    public static function maybeSync(): void
    {
        if (get_option(self::VERSION_OPTION) === self::VERSION) {
            return;
        }

        self::sync();
    }
}
