<?php

/**
 * Race condition: δύο ταυτόχρονες `ContractLifecycle::moveTo()` στην ίδια σύμβαση.
 *
 * Εύρημα εξωτερικού ελέγχου, επιβεβαιωμένο από τον ιδιοκτήτη (30/08/2026, «pame
 * me auta, nai»). Η `ContractTransitions::applyTransition()` έγραφε το `status`
 * με σκέτο `$wpdb->update(..., ['id' => $contractId])` -- χωρίς όρο πάνω στην
 * κατάσταση από την οποία ξεκινάει. Η `moveTo()` διαβάζει το `$from` με
 * ξεχωριστό ερώτημα (`statusOf()`) πριν αποφασίσει αν η μετάβαση επιτρέπεται --
 * ανάμεσα σε αυτό το διάβασμα και τη γραφή δεν υπήρχε καμία εγγύηση ότι η
 * σύμβαση έμεινε εκεί. Δύο ταυτόχρονες μεταβάσεις από την ίδια αφετηρία προς
 * διαφορετικούς προορισμούς (π.χ. το cron sweep προς 'awaiting_signature' και μια μαζική
 * ενέργεια προς 'presale', ή απλά διπλό κλικ) θα έγραφαν και οι δύο -- η
 * δεύτερη σιωπηλά πάνω από την πρώτη, χωρίς κανένα σφάλμα, καμία δεύτερη
 * καταχώρηση στο ιστορικό, κανείς loser.
 *
 * Το τεστ προσομοιώνει το race ρητά, με την ίδια τεχνική ατομικότητας που ήδη
 * χρησιμοποιεί το `PayoutDeletePendingRaceTest`: η δεύτερη κλήση περνά ρητά
 * `['from' => ...]` για να εκφράσει «ό,τι πίστευε ο καλών πριν προλάβει ο
 * άλλος» -- χωρίς αυτό, η ίδια η `moveTo()` θα ξαναδιάβαζε το ΤΩΡΙΝΟ status
 * (μονού-νήματος δοκιμή) και θα έβγαινε αμέσως από το «ήδη εκεί» κλαδί, χωρίς
 * ποτέ να φτάσει στη δεσμευμένη εγγραφή που ελέγχεται εδώ.
 *
 * Οι δύο στόχοι-ανταγωνιστές είναι σκόπιμα `awaiting_signature`/`presale`, όχι
 * μια ακύρωση -- και οι δύο είναι νόμιμοι επόμενοι σταθμοί από `registration`
 * (δες `ContractStatus::allowedNext()`), άρα το race δεν κρύβεται πίσω από
 * άλλη, ασύνδετη άρνηση κάποιας πύλης.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\UserScope;
use EnergyCRM\Domain\Contract\ContractLifecycle;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\EventRepository;
use EnergyCRM\Services;

final class ContractLifecycleMoveToRaceTest extends IntegrationTestCase
{
    private ContractRepository $contracts;

    private EventRepository $events;

    private ContractLifecycle $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contracts = new ContractRepository();
        $this->events    = new EventRepository();
        $this->lifecycle = Services::lifecycle();
    }

    /**
     * Ο χαμένος με ΔΙΑΦΟΡΕΤΙΚΟ στόχο από τον νικητή: false, τίποτα δεν αλλάζει.
     *
     * Ο διαχειριστής Α (π.χ. το cron sweep) προλαβαίνει πρώτος προς 'awaiting_signature'.
     * Ο διαχειριστής Β είχε ήδη διαβάσει 'registration' ως αφετηρία (π.χ. μια
     * μαζική ενέργεια που ξεκίνησε μια στιγμή νωρίτερα) και προσπαθεί προς
     * 'presale' -- πριν τη διόρθωση αυτό θα έγραφε σιωπηλά πάνω στο 'awaiting_signature'.
     */
    public function testTheLoserWithADifferentTargetChangesNothing(): void
    {
        $contractId = $this->processingContract();

        self::assertTrue($this->lifecycle->moveTo($contractId, 'awaiting_signature'), 'Ο Α πρέπει να πετύχει.');

        $result = $this->lifecycle->moveTo($contractId, 'presale', ['from' => 'registration']);

        self::assertFalse($result, 'Ο Β έχασε το race -- η αφετηρία που πίστευε δεν ίσχυε πια.');
        self::assertSame(
            'awaiting_signature',
            $this->storedRow('contracts', $contractId)['status'],
            'Η νίκη του Α δεν πρέπει να αντικατασταθεί σιωπηλά από τον Β.'
        );

        $changes = array_filter(
            $this->events->forContract($contractId),
            static fn (array $event): bool => $event['type'] === 'status_change'
        );

        // 2, όχι 1: το processingContract() fixture κάνει ήδη μία μετάβαση
        // (presale -> registration), και η νικήτρια του Α είναι η δεύτερη. Η
        // χαμένη απόπειρα του Β δεν πρέπει να προσθέσει τρίτη.
        self::assertCount(2, $changes, 'Μόνο οι δύο πραγματικές μεταβάσεις πρέπει να καταγράφηκαν.');
    }

    /**
     * Ο χαμένος με ΤΟΝ ΙΔΙΟ στόχο με τον νικητή: true (idempotent), καμία
     * διπλή καταχώρηση.
     *
     * Ο Α και ο Β ήθελαν και οι δύο 'awaiting_signature' -- ο Α έφτασε πρώτος. Το
     * αποτέλεσμα που ζήτησε ο Β ήδη ισχύει, άρα η moveTo() του επιστρέφει
     * true χωρίς να ξαναγράψει το ιστορικό ή να ξαναστείλει ειδοποίηση: αυτό
     * το έκανε ήδη η κλήση του Α.
     */
    public function testTheLoserWithTheSameTargetIsIdempotentNotDuplicated(): void
    {
        $contractId = $this->processingContract();

        self::assertTrue($this->lifecycle->moveTo($contractId, 'awaiting_signature'), 'Ο Α πρέπει να πετύχει.');

        $result = $this->lifecycle->moveTo($contractId, 'awaiting_signature', ['from' => 'registration']);

        self::assertTrue($result, 'Ο Β ζητούσε ό,τι ήδη ισχύει -- idempotent true.');
        self::assertSame('awaiting_signature', $this->storedRow('contracts', $contractId)['status']);

        $changes = array_filter(
            $this->events->forContract($contractId),
            static fn (array $event): bool => $event['type'] === 'status_change'
        );

        // 2, όχι 1: ίδιος λόγος όπως παραπάνω -- processingContract() (1) + η
        // νίκη του Α (1). Η idempotent «ήττα» του Β δεν πρέπει να προσθέσει
        // τρίτη, ΙΔΙΑ κατάσταση κι ας ζητούσε.
        self::assertCount(2, $changes, 'Η δεύτερη κλήση δεν πρέπει να προσθέσει δεύτερη καταχώρηση.');
    }

    /** A losing race must not touch updated_at either -- nothing about it actually applied. */
    public function testTheLoserDoesNotRefreshUpdatedAt(): void
    {
        $contractId = $this->processingContract();
        self::assertTrue($this->lifecycle->moveTo($contractId, 'awaiting_signature'));

        $updatedAtAfterWin = $this->storedRow('contracts', $contractId)['updated_at'];

        $this->lifecycle->moveTo($contractId, 'presale', ['from' => 'registration']);

        self::assertSame(
            $updatedAtAfterWin,
            $this->storedRow('contracts', $contractId)['updated_at'],
            'Ο χαμένος δεν πρέπει να αγγίξει καν το updated_at.'
        );
    }

    private function processingContract(): int
    {
        $partner    = $this->makePartner();
        $contractId = $this->contracts->create(
            ['status' => 'presale', 'supply_number' => '90000000001', 'energy_type' => 'power'],
            UserScope::forSelf($partner)
        );

        self::assertGreaterThan(0, $contractId);
        self::assertTrue($this->lifecycle->moveTo($contractId, 'registration'));

        return $contractId;
    }
}
