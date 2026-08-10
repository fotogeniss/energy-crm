<?php

/**
 * Signed contracts move themselves on to "processing" after a delay.
 *
 * The delay exists so an agent who signs and immediately notices a mistake has
 * a window to fix it before the back office sees the application.
 *
 * Two mechanisms, on purpose. Signing schedules a one-off event for its own
 * contract id — the id matters, because WordPress de-duplicates identical
 * no-argument events and a batch of signatures within one window would collapse
 * into a single job. On top of that a five-minute sweep runs regardless, so a
 * missed or lost event self-heals instead of leaving a contract stuck forever.
 *
 * Split from ContractLifecycle in roadmap step 10: scheduling is not part of
 * what a status change *is*. The lifecycle announces, this listens.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Contract;

use EnergyCRM\Persistence\ContractRepository;

final class AutoProcess
{
    public const HOOK = 'ecrm_auto_process';

    /** The self-healing pass, in case a one-off event never fired. */
    private const SWEEP_HOOK = self::HOOK . '_sweep';

    private const SCHEDULE = 'ecrm_5min';

    /**
     * A few seconds past the delay, so the job never lands in the same second
     * the cutoff is computed for and finds nothing to do.
     */
    private const SCHEDULING_MARGIN = 5;

    public function __construct(
        private readonly ContractRepository $contracts,
        private readonly ContractLifecycle $lifecycle,
    ) {
    }

    public function register(): void
    {
        add_filter('cron_schedules', [self::class, 'addFiveMinuteSchedule']);

        add_action(self::HOOK, [$this, 'run']);
        add_action(self::SWEEP_HOOK, [$this, 'run']);

        // Signing schedules its own follow-up, without the lifecycle knowing
        // that a scheduler exists.
        add_action(ContractLifecycle::STATUS_CHANGED, [$this, 'onStatusChanged'], 10, 2);

        if (wp_next_scheduled(self::SWEEP_HOOK) === false) {
            wp_schedule_event(time() + self::delay(), self::SCHEDULE, self::SWEEP_HOOK);
        }
    }

    /** Both hooks, cleared on deactivation so nothing fires from a dead plugin. */
    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
        wp_clear_scheduled_hook(self::SWEEP_HOOK);
    }

    /** Seconds to wait after signing before advancing. */
    public static function delay(): int
    {
        return (int) apply_filters('ecrm_auto_process_delay', 5 * MINUTE_IN_SECONDS);
    }

    /**
     * @param array<string, array{interval: int, display: string}> $schedules
     *
     * @return array<string, array{interval: int, display: string}>
     */
    public static function addFiveMinuteSchedule(array $schedules): array
    {
        $schedules[self::SCHEDULE] ??= [
            'interval' => 300,
            'display'  => 'Every 5 minutes (Energy CRM)',
        ];

        return $schedules;
    }

    public function onStatusChanged(int $contractId, string $to): void
    {
        if ($to !== ContractStatus::Signed->value) {
            return;
        }

        $delay = self::delay();

        if ($delay <= 0) {
            return;
        }

        wp_schedule_single_event(time() + $delay + self::SCHEDULING_MARGIN, self::HOOK, [$contractId]);
    }

    /**
     * Promote everything signed long enough ago.
     *
     * Only rows still in `signed` are selected, so an agent who already moved
     * the contract forward keeps their decision — without that, the sweep would
     * drag contracts backwards minutes after anyone touched them.
     *
     * The argument is untyped because it arrives from the cron table, where it
     * was serialised: under strict_types a declared `int` would fatal on the
     * day something stores it as a string.
     *
     * @param mixed $onlyId Limit to one contract, as the one-off event does.
     */
    public function run(mixed $onlyId = 0): void
    {
        $delay  = self::delay();
        $cutoff = gmdate('Y-m-d H:i:s', (int) current_time('timestamp') - $delay);

        foreach ($this->contracts->idsSignedBefore($cutoff, (int) $onlyId) as $contractId) {
            $this->lifecycle->moveTo($contractId, ContractStatus::Processing->value, [
                'from'    => ContractStatus::Signed->value,
                'message' => sprintf(
                    'Αυτόματη μετάβαση σε επεξεργασία (%d λεπτά μετά την υπογραφή)',
                    (int) round($delay / 60)
                ),
            ]);
        }
    }
}
