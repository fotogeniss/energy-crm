<?php

/**
 * (348) Τα 8 οικιακά Picasso (S 49,90 / 64,90, M, L): ήρθαν τα έντυπα
 * (Protergia, 03/11/2026).
 *
 * Η 0046 τα είχε κάνει ανενεργά ώσπου να έρθουν. Τώρα ξαναενεργοποιούνται·
 * οι τιμές τους μπήκαν ήδη από την 0050 και δεν αγγίζονται εδώ. Δεν
 * ξαναγράφεται η 0046: τρέχει πάντα όπως έτρεξε.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class ActivateProtergiaHomePicasso implements Migration
{
    private const CODES = [
        'protergia_pic_h_s2',
        'protergia_pic_h_s3',
        'protergia_pic_h_m1',
        'protergia_pic_h_m2',
        'protergia_pic_h_m3',
        'protergia_pic_h_l1',
        'protergia_pic_h_l2',
        'protergia_pic_h_l3',
    ];

    public function id(): string
    {
        return '0056_activate_protergia_home_picasso';
    }

    public function description(): string
    {
        return 'Protergia: ενεργά τα 8 οικιακά Picasso, ήρθαν τα έντυπά τους (348)';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $providers = Tables::name(Tables::PROVIDERS);
        $programs  = Tables::name(Tables::PROGRAMS);

        if (! $schema->hasTable($providers) || ! $schema->hasTable($programs)) {
            return;
        }

        if (! $schema->hasColumn($programs, 'code')) {
            return;
        }

        $providerId = (int) $wpdb->get_var(
            $wpdb->prepare('SELECT id FROM %i WHERE slug = %s', $providers, 'protergia')
        );

        if ($providerId <= 0) {
            return;
        }

        foreach (self::CODES as $code) {
            $wpdb->update($programs, ['active' => 1], ['provider_id' => $providerId, 'code' => $code]);
        }
    }
}
