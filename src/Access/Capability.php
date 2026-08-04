<?php

/**
 * The things a person can be allowed to do.
 *
 * Named per action rather than per role, so that adding a fourth role later is
 * a change to one table instead of a hunt through every endpoint. Code asks
 * "may you delete a contract", never "are you a seller".
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Access;

final class Capability
{
    /** Reach the CRM at all. Everyone with a CRM role has this. */
    public const USE_CRM = 'ecrm_use';

    public const CREATE_CONTRACT = 'ecrm_create_contract';
    public const EDIT_CONTRACT   = 'ecrm_edit_contract';
    public const DELETE_CONTRACT = 'ecrm_delete_contract';
    public const CHANGE_STATUS   = 'ecrm_change_status';

    /** Hand a contract to another partner. Moves the commission with it. */
    public const ASSIGN_CONTRACT = 'ecrm_assign_contract';

    public const MANAGE_TEAM      = 'ecrm_manage_team';
    public const VIEW_COMMISSIONS = 'ecrm_view_commissions';
    public const MANAGE_LEADS     = 'ecrm_manage_leads';
    public const IMPORT_DATA      = 'ecrm_import_data';
    public const EXPORT_DATA      = 'ecrm_export_data';
    public const VIEW_ANALYTICS   = 'ecrm_view_analytics';

    private function __construct()
    {
    }

    /**
     * Every capability the plugin defines.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::USE_CRM,
            self::CREATE_CONTRACT,
            self::EDIT_CONTRACT,
            self::DELETE_CONTRACT,
            self::CHANGE_STATUS,
            self::ASSIGN_CONTRACT,
            self::MANAGE_TEAM,
            self::VIEW_COMMISSIONS,
            self::MANAGE_LEADS,
            self::IMPORT_DATA,
            self::EXPORT_DATA,
            self::VIEW_ANALYTICS,
        ];
    }
}
