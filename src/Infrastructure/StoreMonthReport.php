<?php

/**
 * (345) Η μηνιαία αναφορά καταστήματος για έναν κλεισμένο (ή τον τρέχοντα)
 * μήνα. Την χρησιμοποιούν η καρτέλα «Κατάστημα» (GET /store/month) και το
 * email της 1ης του μήνα (MonthlyStoreReport), ώστε να λένε τα ίδια νούμερα.
 *
 * Τίποτα δεν αποθηκεύεται: υπολογίζεται κάθε φορά από αιτήσεις και γεγονότα.
 * Μόνο πλήθη και ονόματα πωλητών· κανένα στοιχείο πελάτη. Mockup:
 * docs/UI-TARGETS-AND-MONTHLY.html.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use EnergyCRM\Access\UserScope;
use EnergyCRM\Domain\Analytics\Month;
use EnergyCRM\Domain\Analytics\StoreFigures;
use EnergyCRM\Domain\Contract\CloseReason;
use EnergyCRM\Domain\Contract\ContractStatus;
use EnergyCRM\Persistence\StoreRepository;
use EnergyCRM\Persistence\TargetRepository;
use EnergyCRM\Persistence\TeamActivityRepository;
use EnergyCRM\Persistence\TeamRepository;

final class StoreMonthReport
{
    public function __construct(
        private readonly StoreRepository $store,
        private readonly TargetRepository $targets,
        private readonly TeamActivityRepository $activity,
        private readonly TeamRepository $team,
    ) {
    }

    /** @return list<string> */
    public static function lostStatuses(): array
    {
        return [
            ContractStatus::ThalisRejected->value,
            ContractStatus::MnpReject->value,
            ContractStatus::Cancelled->value,
        ];
    }

    /**
     * @param string $month YYYY-MM, ήδη ελεγμένο.
     *
     * @return array<string, mixed>
     */
    public function build(UserScope $scope, string $month): array
    {
        $lost     = self::lostStatuses();
        $start    = Month::firstDay($month);
        $prev     = Month::firstDay(Month::previous($month));
        $byOwner  = $this->store->monthByOwner($scope, $start, $lost);
        $before   = $this->store->monthByOwner($scope, $prev, $lost);
        $reasons  = $this->store->monthReasons($scope, $start, $lost);
        $leads    = $this->store->monthLeads($scope, $start);
        $members  = $this->activity->memberIds($scope);
        $ids      = array_values(array_unique([...$members, ...array_keys($byOwner)]));
        $names    = $this->team->displayNames($ids);
        $targets  = $this->targets->forMonth($month, $ids);

        $rows = [];

        foreach ($ids as $id) {
            $stat   = $byOwner[$id] ?? ['applications' => 0, 'activated' => 0, 'lost' => 0];
            $target = $targets[$id] ?? null;

            $idle = $stat['applications'] + $stat['activated'] + $stat['lost'] === 0 && $target === null;

            if (! isset($names[$id]) || $idle) {
                continue;
            }

            $rows[] = [
                'id'           => $id,
                'name'         => $names[$id],
                'applications' => $stat['applications'],
                'target'       => $target,
                'of_target'    => StoreFigures::ofTarget($stat['applications'], $target),
                'activated'    => $stat['activated'],
                'lost'         => $stat['lost'],
            ];
        }

        usort(
            $rows,
            static fn (array $a, array $b): int =>
                ($b['applications'] <=> $a['applications']) ?: strcmp($a['name'], $b['name'])
        );

        $applications = array_sum(array_column($byOwner, 'applications'));
        $prevApps     = array_sum(array_column($before, 'applications'));
        $activated    = array_sum(array_column($byOwner, 'activated'));
        $targetTotal  = $targets === [] ? null : array_sum($targets);

        return [
            'month'     => $month,
            'label'     => Month::label($month),
            'previous'  => Month::accusative(Month::previous($month)),
            'tiles'     => [
                'applications'     => $applications,
                'before'           => $prevApps,
                'change'           => StoreFigures::change($applications, $prevApps),
                'target'           => $targetTotal,
                'of_target'        => StoreFigures::ofTarget($applications, $targetTotal),
                'activated'        => $activated,
                'activated_before' => array_sum(array_column($before, 'activated')),
                'lost'             => array_sum(array_column($byOwner, 'lost')),
                'top_reason'       => $reasons !== [] ? CloseReason::label($reasons[0]['reason']) : '',
                'top_reason_count' => $reasons !== [] ? $reasons[0]['count'] : 0,
                'leads'            => $leads['leads'],
                'leads_converted'  => $leads['converted'],
                'leads_qr'         => $leads['qr'],
            ],
            'members'   => $rows,
            'providers' => $this->store->monthByProvider($scope, $start),
        ];
    }
}
