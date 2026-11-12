<?php

/**
 * (372) Η κλιμάκωση SLA: η εργασία που φτιάχνεται για τον υπεύθυνο ομάδας όταν
 * μια αίτηση μένει στάσιμη σε μη τελική κατάσταση πάνω από τις ημέρες της
 * ρύθμισης `sla_escalation_days`.
 *
 * Εδώ μόνο το όνομα του είδους· ποιες αιτήσεις και πότε, στο cron του
 * `ECRM_Tasks::run_sla_escalation()`. Ίδιο σχήμα με το `RenewalCall` (344).
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Contract;

final class SlaEscalation
{
    /**
     * Η τιμή της στήλης tasks.kind, και ο τύπος του γεγονότος στο ιστορικό.
     * Μέχρι το 371 ήταν σημείωση (`note`) και φαινόταν αυτούσια στην εργασία.
     */
    public const KIND = 'sla_escalation';

    private function __construct()
    {
    }
}
