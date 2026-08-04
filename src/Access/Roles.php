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
 *   Συνεργάτης  — runs a downline. Everything except site administration.
 *   Πωλητής     — sells. Owns leads and contracts, sees their own commission,
 *                 cannot delete or reassign, because that moves money.
 *   Καταχωρητής — types applications on behalf of others. No commission, no
 *                 leads, no deletion.
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
    private const VERSION = '2';

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
                    Capability::VIEW_COMMISSIONS,
                    Capability::MANAGE_LEADS,
                    Capability::EXPORT_DATA,
                ],
            ],
            self::REGISTRAR => [
                'label'        => 'Καταχωρητής',
                'capabilities' => [
                    Capability::USE_CRM,
                    Capability::CREATE_CONTRACT,
                    Capability::EDIT_CONTRACT,
                    Capability::CHANGE_STATUS,
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
     * Create the roles and bring their capabilities in line with the matrix.
     *
     * add_role() is a no-op when the role exists, so an installed site would
     * otherwise keep whatever capabilities it was created with forever. Here
     * the set is recomputed: capabilities no longer in the matrix are removed
     * as well as added, which is what makes revoking one actually work.
     */
    public static function sync(): void
    {
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
