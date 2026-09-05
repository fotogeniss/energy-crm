<?php

/**
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Document;

use EnergyCRM\Domain\Document\KindVerdict;
use PHPUnit\Framework\TestCase;

final class KindVerdictTest extends TestCase
{
    public function testASureReadingOfADifferentKindCorrectsTheLabel(): void
    {
        self::assertSame(
            'provider_bill',
            KindVerdict::correction('id_card', 'provider_bill', 'high')
        );
    }

    public function testAReadingThatAgreesChangesNothing(): void
    {
        self::assertNull(KindVerdict::correction('id_card', 'id_card', 'high'));
    }

    public function testAnUnsureReadingLeavesTheHumanChoiceStanding(): void
    {
        self::assertNull(KindVerdict::correction('id_card', 'provider_bill', 'low'));
        self::assertNull(KindVerdict::correction('id_card', 'provider_bill', 'medium'));
        self::assertNull(KindVerdict::correction('id_card', 'provider_bill', null));
    }

    public function testAnUnreadableDocumentChangesNothing(): void
    {
        self::assertNull(KindVerdict::correction('id_card', null, 'high'));
        self::assertNull(KindVerdict::correction('id_card', '', 'high'));
        self::assertNull(KindVerdict::correction('id_card', '   ', 'high'));
    }

    /**
     * Μια διόρθωση ΠΡΟΣ κάτι που δεν αναγνωρίζεται οπτικά δεν κερδίζει τίποτα
     * και μπορεί να καταστρέψει σωστή χειροκίνητη επιλογή.
     */
    public function testItNeverCorrectsTowardsAKindItCannotRecognise(): void
    {
        self::assertNull(KindVerdict::correction('authorization', 'other', 'high'));
        self::assertNull(KindVerdict::correction('id_card', 'e9', 'high'));
        self::assertNull(KindVerdict::correction('id_card', 'gemi', 'high'));
    }

    public function testItCorrectsAwayFromAKindItCannotRecognise(): void
    {
        self::assertSame(
            'id_card',
            KindVerdict::correction('other', 'id_card', 'high')
        );
    }

    public function testOnlyAFileNobodyHasJudgedIsExamined(): void
    {
        self::assertTrue(KindVerdict::shouldExamine(null));
        self::assertTrue(KindVerdict::shouldExamine(''));
        self::assertFalse(KindVerdict::shouldExamine(KindVerdict::SOURCE_AI));
        self::assertFalse(KindVerdict::shouldExamine(KindVerdict::SOURCE_AI_OK));
        self::assertFalse(KindVerdict::shouldExamine(KindVerdict::SOURCE_HUMAN));
    }

    public function testAHumanDecisionLocksTheLabel(): void
    {
        self::assertTrue(KindVerdict::isLocked(KindVerdict::SOURCE_HUMAN));
        self::assertFalse(KindVerdict::isLocked(KindVerdict::SOURCE_AI));
        self::assertFalse(KindVerdict::isLocked(KindVerdict::SOURCE_AI_OK));
        self::assertFalse(KindVerdict::isLocked(null));
    }

    /**
     * Ακόμα και μια ανάγνωση που δεν άλλαξε τίποτα σημειώνεται -- αλλιώς κάθε
     * άνοιγμα της καρτέλας ξαναπληρώνει την ίδια ανάγνωση.
     */
    public function testEveryReviewMarksTheFileAsRead(): void
    {
        self::assertSame(KindVerdict::SOURCE_AI, KindVerdict::sourceAfterReview('provider_bill'));
        self::assertSame(KindVerdict::SOURCE_AI_OK, KindVerdict::sourceAfterReview(null));

        self::assertTrue(KindVerdict::isAutoCorrected(KindVerdict::sourceAfterReview('provider_bill')));
        self::assertFalse(KindVerdict::isAutoCorrected(KindVerdict::sourceAfterReview(null)));

        self::assertFalse(KindVerdict::shouldExamine(KindVerdict::sourceAfterReview(null)));
    }
}
