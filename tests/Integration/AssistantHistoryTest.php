<?php

/**
 * Το ιστορικό της Λίτσα ζει πλέον στη βάση, ένας χρήστης τη φορά -- build
 * queue 14, αντικαθιστά το παλιό localStorage του browser (καθαρό κείμενο,
 * χωρίς λήξη, έξω από κάθε δικαίωμα πρόσβασης της εφαρμογής).
 *
 * Τρία πράγματα αξίζει να μείνουν πιασμένα εδώ:
 *
 *   - Scoping ανά χρήστη: το ιστορικό του Α δεν είναι ορατό ή διαγράψιμο
 *     από τον Β, ούτε καν όταν ζητάει explicit το row id -- δεν υπάρχει
 *     καν επιλογή "ανά id" εδώ, μόνο "ανά χρήστη".
 *   - Το όριο 40 γραμμών, ίδιο με το παλιό history.slice(-40) -- ρητά
 *     επιβεβαιωμένο από τον ιδιοκτήτη να μείνει το ίδιο, όχι νέο σχέδιο.
 *   - clear() σβήνει μόνο τα δικά του, ό,τι κι αν κρατάει κάποιος άλλος.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Persistence\AssistantHistoryRepository;

final class AssistantHistoryTest extends IntegrationTestCase
{
    private AssistantHistoryRepository $history;

    protected function setUp(): void
    {
        parent::setUp();

        $this->history = new AssistantHistoryRepository();
    }

    public function testAppendAndRecentForRoundTripInOrder(): void
    {
        $user = $this->makeCrmUser();

        $this->history->append($user, 'user', 'Πόσες εκκρεμότητες έχω;');
        $this->history->append($user, 'assistant', 'Έχεις 3 εκκρεμότητες.');

        $rows = $this->history->recentFor($user);

        self::assertCount(2, $rows);
        self::assertSame('user', $rows[0]['role']);
        self::assertSame('Πόσες εκκρεμότητες έχω;', $rows[0]['content']);
        self::assertSame('assistant', $rows[1]['role']);
        self::assertSame('Έχεις 3 εκκρεμότητες.', $rows[1]['content']);
    }

    public function testRecentForIsScopedToItsOwner(): void
    {
        $owner  = $this->makeCrmUser();
        $other  = $this->makeCrmUser();

        $this->history->append($owner, 'user', 'Δικό μου μήνυμα.');
        $this->history->append($other, 'user', 'Μήνυμα του άλλου.');

        $rows = $this->history->recentFor($owner);

        self::assertCount(1, $rows);
        self::assertSame('Δικό μου μήνυμα.', $rows[0]['content']);
    }

    /**
     * Ίδιο όριο με το παλιό localStorage -- ρητά επιβεβαιωμένο, όχι νέα
     * απόφαση. Η 45η γραμμή σπρώχνει έξω τις 5 παλιότερες, όχι απλώς αγνοεί
     * ό,τι ξεπερνά το όριο.
     */
    public function testOnlyTheNewestFortyRowsSurvive(): void
    {
        $user = $this->makeCrmUser();

        for ($i = 1; $i <= 45; $i++) {
            $this->history->append($user, 'user', 'msg-' . $i);
        }

        $rows = $this->history->recentFor($user);

        self::assertCount(40, $rows);
        self::assertSame('msg-6', $rows[0]['content'], 'Oldest surviving row should be the 6th written.');
        self::assertSame('msg-45', $rows[39]['content']);
    }

    public function testClearRemovesOnlyThatUsersRows(): void
    {
        $owner = $this->makeCrmUser();
        $other = $this->makeCrmUser();

        $this->history->append($owner, 'user', 'Θα σβηστεί.');
        $this->history->append($other, 'user', 'Θα μείνει.');

        $this->history->clear($owner);

        self::assertSame([], $this->history->recentFor($owner));
        self::assertCount(1, $this->history->recentFor($other));
    }

    public function testEmptyContentIsNotStored(): void
    {
        $user = $this->makeCrmUser();

        $this->history->append($user, 'user', '   ');

        self::assertSame([], $this->history->recentFor($user));
    }
}
