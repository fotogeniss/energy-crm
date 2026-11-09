<?php

/**
 * (351) Τα Picasso της Protergia φαίνονται μόνο στο «Σταθερό» (μπλε).
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Persistence\Schema\Migrations\MarkProtergiaPicassoFixed;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class PicassoIsFixedOnlyTest extends IntegrationTestCase
{
    public function testEveryPicassoWithoutAColourBecomesFixed(): void
    {
        $provider = $this->protergia();

        foreach (['protergia_picasso2', 'protergia_pic_h_s1', 'protergia_pic_b_l3'] as $code) {
            $this->programWithColour($provider, $code, '');
        }

        (new MarkProtergiaPicassoFixed())->apply(new SchemaInspector());

        self::assertSame('fixed', $this->colourOf($provider, 'protergia_picasso2'));
        self::assertSame('fixed', $this->colourOf($provider, 'protergia_pic_h_s1'));
        self::assertSame('fixed', $this->colourOf($provider, 'protergia_pic_b_l3'));
    }

    /** Χρώμα που έδωσε ήδη ο διαχειριστής μένει· τα άλλα προγράμματα δεν αγγίζονται. */
    public function testAColourAlreadyGivenAndOtherProgramsStay(): void
    {
        $provider = $this->protergia();
        $this->programWithColour($provider, 'protergia_pic_h_m2', 'variable');
        $this->programWithColour($provider, 'ecrm_test_not_picasso', '');

        (new MarkProtergiaPicassoFixed())->apply(new SchemaInspector());

        self::assertSame('variable', $this->colourOf($provider, 'protergia_pic_h_m2'));
        self::assertSame('', $this->colourOf($provider, 'ecrm_test_not_picasso'));
    }

    public function testTheListCoversAllEighteenSizesAndThePicasso2(): void
    {
        self::assertCount(20, MarkProtergiaPicassoFixed::codes());
    }

    private function protergia(): int
    {
        global $wpdb;

        $table = Tables::name(Tables::PROVIDERS);
        $id    = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE slug = %s', $table, 'protergia'));

        if ($id > 0) {
            return $id;
        }

        $wpdb->insert($table, ['slug' => 'protergia', 'name' => 'Protergia']);

        return (int) $wpdb->insert_id;
    }

    private function programWithColour(int $provider, string $code, string $colour): void
    {
        global $wpdb;

        $table = Tables::name(Tables::PROGRAMS);
        $id    = (int) $wpdb->get_var(
            $wpdb->prepare('SELECT id FROM %i WHERE provider_id = %d AND code = %s', $table, $provider, $code)
        );

        if ($id > 0) {
            $wpdb->update($table, ['price_type' => $colour], ['id' => $id]);
            return;
        }

        $wpdb->insert($table, [
            'provider_id' => $provider, 'name' => $code, 'code' => $code, 'energy_type' => 'power',
            'category' => 'home', 'price_type' => $colour, 'active' => 1,
        ]);
    }

    private function colourOf(int $provider, string $code): string
    {
        global $wpdb;

        return (string) $wpdb->get_var($wpdb->prepare(
            'SELECT price_type FROM %i WHERE provider_id = %d AND code = %s',
            Tables::name(Tables::PROGRAMS),
            $provider,
            $code
        ));
    }
}
