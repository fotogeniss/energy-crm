<?php

/**
 * Every migration, in the order they must run.
 *
 * Append only. Reordering or renaming an entry that has shipped will make live
 * sites either re-run a change or skip one, because the id is the only record
 * that something already happened.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema;

use EnergyCRM\Persistence\Schema\Migrations\AddContractAddresses;
use EnergyCRM\Persistence\Schema\Migrations\AddContractListIndexes;
use EnergyCRM\Persistence\Schema\Migrations\AddCustomerAfmIndex;
use EnergyCRM\Persistence\Schema\Migrations\AddForeignKeys;
use EnergyCRM\Persistence\Schema\Migrations\AddPayoutAmountColumn;
use EnergyCRM\Persistence\Schema\Migrations\AddProgramCodeColumn;
use EnergyCRM\Persistence\Schema\Migrations\AddTrackKeyColumn;
use EnergyCRM\Persistence\Schema\Migrations\DropIbanFromExtras;
use EnergyCRM\Persistence\Schema\Migrations\EnsureInnoDb;
use EnergyCRM\Persistence\Schema\Migrations\EnsureLegacyColumns;
use EnergyCRM\Persistence\Schema\Migrations\FixProviderEnergyTypes;
use EnergyCRM\Persistence\Schema\Migrations\MoveMeterAddressOutOfExtras;
use EnergyCRM\Persistence\Schema\Migrations\SeedMobilePrograms;
use EnergyCRM\Persistence\Schema\Migrations\SeedOrizonPlans;
use EnergyCRM\Persistence\Schema\Migrations\SeedProtergiaHomePlans;
use EnergyCRM\Persistence\Schema\Migrations\PurgeOrphanFiles;
use EnergyCRM\Persistence\Schema\Migrations\WidenEncryptedColumns;

final class MigrationList
{
    private function __construct()
    {
    }

    /** @return list<Migration> */
    public static function all(): array
    {
        return [
            new EnsureLegacyColumns(),
            new PurgeOrphanFiles(),
            new EnsureInnoDb(),
            new AddForeignKeys(),
            new AddContractAddresses(),
            new MoveMeterAddressOutOfExtras(),
            new AddContractListIndexes(),
            new FixProviderEnergyTypes(),
            new SeedMobilePrograms(),
            new AddCustomerAfmIndex(),
            new WidenEncryptedColumns(),
            new AddProgramCodeColumn(),
            new SeedOrizonPlans(),
            new SeedProtergiaHomePlans(),
            new DropIbanFromExtras(),
            new AddPayoutAmountColumn(),
            new AddTrackKeyColumn(),
        ];
    }
}
