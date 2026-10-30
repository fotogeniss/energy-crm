<?php

/**
 * (330) Μια αίτηση ρεύματος/αερίου που γίνεται «Ενεργή» αποκτά λήξη.
 *
 * Χωρίς `end_date` η σύμβαση δεν μπαίνει ποτέ στις «Ανανεώσεις» και δεν
 * βγάζει υπενθύμιση. Ο κανόνας στο `ContractTerm::onActivation()`· εδώ μόνο
 * η σύνδεση με τον κύκλο ζωής -- ίδιο σημείο με τον RejectionFollowUp, ώστε
 * να ισχύει από κάθε πόρτα (κουμπί, μαζική αλλαγή, εισαγωγή Excel).
 *
 * Χωρίς UserScope, όπως ο ίδιος ο κύκλος ζωής (ContractTransitions): τρέχει
 * ΜΕΤΑ από μετάβαση που εγκρίθηκε ήδη, και γράφει μόνο στη σύμβαση που
 * μετακινήθηκε.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use EnergyCRM\Domain\Contract\ContractLifecycle;
use EnergyCRM\Domain\Contract\ContractStatus;
use EnergyCRM\Domain\Contract\ContractTerm;
use EnergyCRM\Persistence\ContractTransitions;

final class ActivationTerm
{
    public function __construct(private readonly ContractTransitions $transitions)
    {
    }

    public function register(): void
    {
        add_action(ContractLifecycle::STATUS_CHANGED, [$this, 'onStatusChanged'], 10, 2);
    }

    public function onStatusChanged(int $contractId, string $to): void
    {
        if ($to !== ContractStatus::Active->value || $contractId <= 0) {
            return;
        }

        $row = $this->transitions->termOf($contractId);

        if ($row === null || ! in_array($row['energy_type'], ['power', 'gas'], true)) {
            return;
        }

        $term = ContractTerm::onActivation(
            $row['start_date'],
            $row['term_months'],
            $row['end_date'],
            current_time('Y-m-d')
        );

        if ($term !== null) {
            $this->transitions->writeTerm($contractId, $term);
        }
    }
}
