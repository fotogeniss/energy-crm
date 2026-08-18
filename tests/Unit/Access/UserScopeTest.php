<?php

/**
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Access;

use EnergyCRM\Access\UserScope;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class UserScopeTest extends TestCase
{
    public function testSelfScopeCoversOnlyTheActor(): void
    {
        $scope = UserScope::forSelf(7);

        self::assertSame([7], $scope->userIds());
        self::assertTrue($scope->includes(7));
        self::assertFalse($scope->includes(8));
        self::assertFalse($scope->isTeamWide());
    }

    public function testTeamScopeAlwaysContainsTheActor(): void
    {
        $scope = UserScope::forTeam(7, [12, 15]);

        self::assertContains(7, $scope->userIds());
        self::assertTrue($scope->isTeamWide());
    }

    public function testTeamScopeDeduplicatesAndDropsInvalidIds(): void
    {
        $scope = UserScope::forTeam(7, [12, 12, 7, 0, -3]);

        self::assertSame([7, 12], $scope->userIds());
    }

    public function testAnOutsiderIsNeverIncluded(): void
    {
        $scope = UserScope::forTeam(7, [12, 15]);

        self::assertFalse($scope->includes(99));
    }

    public function testAdministratorScopeIncludesEveryone(): void
    {
        $scope = UserScope::forAdministrator(1);

        self::assertTrue($scope->isAdministrator());
        self::assertTrue($scope->includes(4242));
    }

    /**
     * Η παγίδα, δηλωμένη ως γεγονός αντί να ανακαλύπτεται.
     *
     * Ένα scope διαχειριστή λέει «περιλαμβάνει τους πάντες» ΚΑΙ «η λίστα μου
     * είναι ένα άτομο». Και τα δύο σωστά: το `userIds()` απαντά «ποιος είναι ο
     * δράστης», όχι «τι βλέπει».
     *
     * Διαβασμένο ως το δεύτερο, δίνει άδεια εξαγωγή και κενές ειδοποιήσεις —
     * αποτυχία που δεν φωνάζει, γιατί λιγότερα δεδομένα δεν μοιάζουν με σφάλμα.
     * Τρεις controllers το είχαν διαβάσει έτσι, και το πάτησα κι εγώ γράφοντας
     * τη διόρθωση του ευρήματος 5.
     *
     * Όποιος χρειάζεται «τι βλέπει» ρωτά τον `ScopeResolver::visibleUserIds()`.
     */
    public function testAnAdministratorScopeStillListsOnlyTheActor(): void
    {
        $scope = UserScope::forAdministrator(1);

        self::assertSame(
            [1],
            $scope->userIds(),
            'Αν αυτό γίνει ποτέ «όλοι», ο ScopeResolver::visibleUserIds() και οι '
            . 'έλεγχοι isAdministrator() στα αποθετήρια περισσεύουν — και το '
            . 'AdministratorScopeIsNotATeamTest φυλάει κάτι που δεν ισχύει πια.'
        );

        self::assertTrue(
            $scope->includes(4242),
            'Ταυτόχρονα περιλαμβάνει τους πάντες. Η αντίφαση ΕΙΝΑΙ το σχέδιο: '
            . 'το ScopeClause δεν εκπέμπει συνθήκη για διαχειριστή, οπότε δεν '
            . 'χρειάζεται ποτέ τη λίστα.'
        );
    }

    public function testPlaceholdersMatchTheNumberOfIds(): void
    {
        $scope = UserScope::forTeam(7, [12, 15]);

        self::assertSame('%d,%d,%d', $scope->placeholders());
        self::assertCount(
            substr_count($scope->placeholders(), '%d'),
            $scope->userIds()
        );
    }

    public function testPlaceholdersAreNeverEmpty(): void
    {
        self::assertSame('%d', UserScope::forSelf(7)->placeholders());
        self::assertSame('%d', UserScope::forTeam(7, [])->placeholders());
    }

    public function testNarrowingDropsTheDownline(): void
    {
        $scope = UserScope::forTeam(7, [12, 15])->toSelfOnly();

        self::assertSame([7], $scope->userIds());
    }

    public function testAScopeRequiresARealActor(): void
    {
        $this->expectException(InvalidArgumentException::class);

        UserScope::forSelf(0);
    }
}
