<?php

/**
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Forms;

use EnergyCRM\Domain\Forms\ProviderFormFields;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The map that lets the form ask only what the paper prints.
 *
 * The two lists it guards — FROM_COLUMNS and COLUMN_INPUTS — describe the same
 * fill keys from two angles, and nothing but this file stops them drifting.
 * A key added to one and forgotten in the other fails silently in the worst
 * possible way: the field quietly disappears from the screen and the
 * application comes back from the provider with an empty box.
 */
final class ProviderFormFieldsColumnsTest extends TestCase
{
    private static function formsDir(): string
    {
        return dirname(__DIR__, 4) . '/assets/forms';
    }

    /**
     * Every fill key the class calls "already on the main form" must say WHERE
     * on the main form. Without this, a new key defaults to «no input supplies
     * it» and the field it needs vanishes.
     */
    public function testEveryColumnFillKeyKnowsItsInputs(): void
    {
        $map     = ProviderFormFields::columnInputMap();
        $missing = [];

        foreach (ProviderFormFields::columnFillKeys() as $key) {
            if (! array_key_exists($key, $map)) {
                $missing[] = $key;
            }
        }

        self::assertSame([], $missing, 'FROM_COLUMNS keys with no COLUMN_INPUTS entry');
    }

    /** And nothing in the map that the class does not claim as a column key. */
    public function testTheMapHasNoStrangers(): void
    {
        $known  = ProviderFormFields::columnFillKeys();
        $stray  = [];

        foreach (array_keys(ProviderFormFields::columnInputMap()) as $key) {
            if (! in_array($key, $known, true)) {
                $stray[] = $key;
            }
        }

        self::assertSame([], $stray, 'COLUMN_INPUTS keys missing from FROM_COLUMNS');
    }

    /**
     * An unknown template must mean «I don't know», never «nothing is needed».
     * The caller shows everything on an empty list, so a missing JSON degrades
     * to today's behaviour instead of an unfillable form.
     */
    public function testAnUnknownTemplateAsksForNothing(): void
    {
        self::assertSame([], ProviderFormFields::mainFormInputsForTemplate('', self::formsDir()));
        self::assertSame([], ProviderFormFields::mainFormInputsForTemplate('no_such_form', self::formsDir()));
    }

    /**
     * The whole point, on the smallest real template: Orizon Family prints 13
     * boxes and needs a handful of typed fields, not the sixty-three the form
     * shows today.
     */
    public function testTheSmallestTemplateNeedsAHandfulOfInputs(): void
    {
        $inputs = ProviderFormFields::mainFormInputsForTemplate('orizon_family', self::formsDir());

        self::assertContains('afm', $inputs);
        self::assertContains('adt', $inputs);
        self::assertLessThan(12, count($inputs), 'Orizon Family should stay a short form');
    }

    /** The largest energy template still lands far below the full form. */
    public function testTheLargestEnergyTemplateStaysUnderThirty(): void
    {
        $inputs = ProviderFormFields::mainFormInputsForTemplate('protergia_he', self::formsDir());

        self::assertContains('afm', $inputs);
        self::assertContains('supply_number', $inputs);
        self::assertLessThan(30, count($inputs), 'protergia_he should need far fewer than the 63 shown today');
    }

    /**
     * No template may ask for an input twice, whatever the paper does. Several
     * fill keys share one input — ΟΔΟΣ and ΔΙΕΥΘΥΝΣΗ both come from `street` —
     * and a duplicate would show the same box twice on screen.
     *
     * @return list<array{string}>
     */
    public static function templates(): array
    {
        $out = [];

        foreach ((array) glob(dirname(__DIR__, 4) . '/assets/forms/*.json') as $path) {
            $out[] = [basename((string) $path, '.json')];
        }

        return $out;
    }

    #[DataProvider('templates')]
    public function testATemplateNeverAsksForTheSameInputTwice(string $template): void
    {
        $inputs = ProviderFormFields::mainFormInputsForTemplate($template, self::formsDir());

        self::assertSame(array_values(array_unique($inputs)), $inputs, $template . ' repeats an input');
    }

    /**
     * Every real template must resolve to at least one typed input. A template
     * that needs none is either a JSON we failed to read or a mapping we forgot
     * — and both look identical to «this provider asks for nothing».
     */
    #[DataProvider('templates')]
    public function testEveryRealTemplateNeedsSomethingTyped(string $template): void
    {
        self::assertNotSame(
            [],
            ProviderFormFields::mainFormInputsForTemplate($template, self::formsDir()),
            $template . ' resolves to no inputs at all'
        );
    }
}
