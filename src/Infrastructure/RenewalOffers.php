<?php

/**
 * (335/344) Η φθηνότερη πρόταση για κάθε σύμβαση που λήγει.
 *
 * Ήταν ιδιωτική μέθοδος του RenewalsController· βγήκε εδώ για να τη
 * χρησιμοποιεί και η καθημερινή «Κλήση ανανέωσης» (RenewalCalls) με τον ίδιο
 * ακριβώς υπολογισμό. Ο κανόνας μένει στο Domain\Pricing\RenewalOffer· εδώ
 * μόνο συλλέγονται τα προγράμματα που επιτρέπονται στον χρήστη.
 *
 * Το `extra_json` χρειάζεται μόνο για την κατανάλωση και αφαιρείται από τη
 * γραμμή, ώστε να μη φτάσει ποτέ στον browser.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use EnergyCRM\Access\ProviderVisibility;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Domain\Pricing\RenewalOffer;
use EnergyCRM\Persistence\ProviderRepository;

final class RenewalOffers
{
    public function __construct(
        private readonly ProviderVisibility $providerAccess,
        private readonly ?ProviderRepository $providers = null,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    public function attach(array $rows, UserScope $scope): array
    {
        $programs = [];
        $current  = [];

        if ($this->providers !== null) {
            $access   = $this->providerAccess->forScope($scope);
            $programs = array_values(array_filter(
                $this->providers->activePrograms(),
                static fn (array $row): bool => $access->allows((int) $row['provider_id'])
            ));
            $current  = $this->providers->programsByIds(array_map(
                static fn (array $row): int => (int) ($row['program_id'] ?? 0),
                $rows
            ));
        }

        $today = current_time('Y-m-d');

        return array_map(
            static function (array $row) use ($programs, $current, $today): array {
                $kwh = RenewalOffer::kwhFrom($row['extra_json'] ?? null);
                unset($row['extra_json']);

                $energy = (string) ($row['energy_type'] ?? '');

                $row['annual_kwh'] = $kwh;
                $row['offer']      = in_array($energy, ['power', 'gas'], true) && $kwh !== null
                    ? RenewalOffer::for(
                        $current[(int) ($row['program_id'] ?? 0)] ?? null,
                        $programs,
                        $kwh,
                        $energy,
                        (string) ($row['category'] ?? ''),
                        $today
                    )
                    : null;
                $row['program_name'] = (string) (($current[(int) ($row['program_id'] ?? 0)] ?? [])['name'] ?? '');

                return $row;
            },
            $rows
        );
    }
}
