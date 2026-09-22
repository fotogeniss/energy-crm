<?php

/**
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Forms;

use EnergyCRM\Domain\Forms\FormTemplates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FormTemplatesTest extends TestCase
{
    private static function formsDir(): string
    {
        return dirname(__DIR__, 4) . '/assets/forms';
    }

    public function testTheProviderIsWhateverPrecedesTheFirstUnderscore(): void
    {
        self::assertSame('protergia', FormTemplates::provider('protergia_oik_sure12'));
        self::assertSame('nrg', FormTemplates::provider('nrg_he_biz'));
        self::assertSame('orizon', FormTemplates::provider('orizon_mobile'));
    }

    /**
     * Το αποτέλεσμα γίνεται κομμάτι διαδρομής αρχείου. Κάτι που δεν μοιάζει
     * με κλειδί εντύπου δεν πρέπει να βγάζει ΚΑΝΕΝΑ φάκελο.
     */
    public function testAnythingThatIsNotAKeyHasNoFolder(): void
    {
        foreach (['', 'protergia', '../etc_passwd', 'a/b_c', 'Protergia_he', '_he', 'protergia_'] as $bad) {
            self::assertSame('', FormTemplates::provider($bad), $bad);
        }
    }

    public function testPathsLandInTheProvidersFolder(): void
    {
        self::assertSame('/x/forms/volton/volton_he.json', FormTemplates::mapPath('/x/forms/', 'volton_he'));
        self::assertSame('/x/forms/volton/volton_he-2.jpg', FormTemplates::pagePath('/x/forms', 'volton_he', 2));
    }

    /**
     * Ενα αρχείο ξεχασμένο στη ρίζα του assets/forms/ δεν το βρίσκει πια η
     * εκτύπωση -- το έντυπο θα «έλειπε» ενώ κάθεται στον δίσκο.
     */
    public function testNothingIsLeftLooseAtTheTop(): void
    {
        $loose = array_merge(glob(self::formsDir() . '/*.json') ?: [], glob(self::formsDir() . '/*.jpg') ?: []);

        self::assertSame([], $loose);
    }

    /** @return list<array{string}> */
    public static function folders(): array
    {
        $out = [];

        foreach (glob(self::formsDir() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $out[] = [basename($dir)];
        }

        return $out;
    }

    /**
     * Κάθε αρχείο στον φάκελο του ΣΩΣΤΟΥ παρόχου. Ενα volton_he.json μέσα
     * στο protergia/ δεν θα τυπωνόταν ποτέ.
     */
    #[DataProvider('folders')]
    public function testEveryFileSitsInItsOwnProvidersFolder(string $folder): void
    {
        foreach (glob(self::formsDir() . '/' . $folder . '/*') ?: [] as $path) {
            $key = (string) preg_replace('/-\d+\.jpg$|\.json$/', '', basename($path));

            self::assertSame($folder, FormTemplates::provider($key), basename($path));
        }
    }

    /**
     * Κάθε χάρτης με το υπόβαθρο της σελ. 1 δίπλα του -- αλλιώς το
     * `ECRM_FormFill::load_map()` απαντά «λείπει το αρχείο προτύπου».
     */
    public function testEveryMapHasItsFirstPage(): void
    {
        $keys = FormTemplates::keys(self::formsDir());

        self::assertNotEmpty($keys);

        foreach ($keys as $key) {
            self::assertFileExists(FormTemplates::pagePath(self::formsDir(), $key, 1));
        }
    }

    /**
     * Τα έντυπα που τυπώνονται σήμερα, ονομαστικά -- λίστα παραγόμενη από
     * τον δίσκο θα συμφωνούσε με ό,τι βρει, ακόμα κι αν η μετακίνηση
     * «έχασε» ένα.
     */
    public function testEveryKnownTemplateIsFound(): void
    {
        $keys = FormTemplates::keys(self::formsDir());

        foreach (
            [
                'elpedison_he', 'enerwave_fa', 'enerwave_he', 'nrg_fa', 'nrg_he', 'nrg_he_biz',
                'orizon_combo', 'orizon_family', 'orizon_mobile', 'orizon_portability',
                'protergia_epag_seasonal', 'protergia_epag_simple2', 'protergia_epag_sure12',
                'protergia_fa', 'protergia_he', 'protergia_he_biz',
                'protergia_oik_bright', 'protergia_oik_lite2', 'protergia_oik_sure12', 'protergia_oik_sure18',
                'volton_fa', 'volton_he', 'zenith_he',
            ] as $key
        ) {
            self::assertContains($key, $keys);
        }
    }
}
