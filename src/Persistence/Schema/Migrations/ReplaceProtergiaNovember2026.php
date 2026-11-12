<?php

/**
 * (371) Protergia, 12/11/2026: νέες εκδόσεις τριών προγραμμάτων ρεύματος και
 * κατάργηση του σταθερού αερίου.
 *
 * - Value Sure 4.0 12M → Value Sure 5.0 12M (οικιακό)
 * - Value Balance 12M → Value Balance 2.0 12M (οικιακό)
 * - Επαγγελματικό 1 Value Balance 12M → Value Balance 2.0 12M (Ε1)
 * - Gas Sure 2.0: καταργήθηκε, χωρίς αντικαταστάτη.
 *
 * Το νέο πρόγραμμα γεννιέται ως αντίγραφο της γραμμής του παλιού: κρατά
 * κατηγορία, σειρά, ενέργεια και ό,τι χρώμα έδωσε ο διαχειριστής, και παίρνει
 * το δικό του όνομα, κωδικό, τιμή και ρήτρα (`ProtergiaPrices2026`). Μαζί
 * αντιγράφονται οι ενεργοί κανόνες προμήθειας και εγγύησης του παλιού, που
 * δένονται με το id του προγράμματος: χωρίς αυτό, κάθε πώληση του νέου θα
 * έβγαινε με προμήθεια 0 ώσπου να τους ξαναγράψει κάποιος.
 *
 * Το παλιό απενεργοποιείται, δεν σβήνεται: οι αιτήσεις του τυπώνουν το φύλλο
 * τους όπως πριν. Αν το νέο υπάρχει ήδη (νέα εγκατάσταση, ή ο διαχειριστής
 * το έφτιαξε), δεν αντιγράφεται τίποτα· παίρνει μόνο τιμή και ρήτρα όπου
 * λείπουν.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Domain\Forms\ProtergiaBizCatalogue;
use EnergyCRM\Domain\Forms\ProtergiaCatalogue;
use EnergyCRM\Domain\Pricing\ProtergiaPrices2026;
use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class ReplaceProtergiaNovember2026 implements Migration
{
    public function id(): string
    {
        return '0058_replace_protergia_november_2026';
    }

    public function description(): string
    {
        return 'Protergia 11/2026: Value Sure 5.0 και Value Balance 2.0 (οικιακό, Ε1) '
            . 'στη θέση των παλιών· τέλος το Gas Sure 2.0 (371)';
    }

    /**
     * Παλιό => [νέο, όνομα νέου, κατηγορία]· νέο null = καταργήθηκε.
     *
     * @return array<string, array{0: string|null, 1: string, 2: string}>
     */
    public static function plan(): array
    {
        $out = [];

        foreach (ProtergiaCatalogue::REPLACED as $old => $new) {
            $label     = $new !== null ? ProtergiaCatalogue::added()[$new]['label'] : '';
            $out[$old] = [$new, $label, 'home'];
        }

        foreach (ProtergiaBizCatalogue::REPLACED as $old => $new) {
            $out[$old] = [$new, ProtergiaBizCatalogue::all()[$new]['label'], 'business'];
        }

        return $out;
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $providers = Tables::name(Tables::PROVIDERS);
        $programs  = Tables::name(Tables::PROGRAMS);

        if (! $schema->hasTable($providers) || ! $schema->hasTable($programs)) {
            return;
        }

        foreach (['code', 'price_kwh', 'fixed_charge', 'pricing', 'price_updated', 'exit_fees'] as $column) {
            if (! $schema->hasColumn($programs, $column)) {
                return;
            }
        }

        $providerId = (int) $wpdb->get_var(
            $wpdb->prepare('SELECT id FROM %i WHERE slug = %s', $providers, 'protergia')
        );

        if ($providerId <= 0) {
            return;
        }

        foreach (self::plan() as $old => [$new, $label, $category]) {
            if ($new !== null) {
                $this->bringIn($schema, $providerId, $old, $new, $label, $category);
            }

            $wpdb->update($programs, ['active' => 0], ['provider_id' => $providerId, 'code' => $old]);
        }
    }

    private function bringIn(
        SchemaInspector $schema,
        int $providerId,
        string $old,
        string $new,
        string $label,
        string $category
    ): void {
        global $wpdb;

        $programs = Tables::name(Tables::PROGRAMS);
        $newId    = $this->idOf($providerId, $new);

        if ($newId === 0) {
            /** @var array<string, mixed>|null $from */
            $from = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE provider_id = %d AND code = %s ORDER BY id LIMIT 1',
                    $programs,
                    $providerId,
                    $old
                ),
                ARRAY_A
            );

            $row = is_array($from) ? $from : [
                'provider_id' => $providerId,
                'energy_type' => 'power',
                'category'    => $category,
                'sort_order'  => 0,
            ];
            unset($row['id']);

            $kept = (string) ($row['price_type'] ?? '');

            $row['name']          = $label;
            $row['code']          = $new;
            $row['active']        = 1;
            $row['price_type']    = $kept !== '' ? $kept : 'fixed';
            $row['price_kwh']     = null;
            $row['fixed_charge']  = null;
            $row['pricing']       = null;
            $row['price_updated'] = null;
            $row['exit_fees']     = null;

            $wpdb->insert($programs, $row);
            $newId = (int) $wpdb->insert_id;

            if ($newId > 0 && is_array($from)) {
                $this->copyRules($schema, Tables::name(Tables::COMMISSION_RULES), (int) $from['id'], $newId);
                $this->copyRules($schema, Tables::name(Tables::GUARANTEE_RULES), (int) $from['id'], $newId);
            }
        }

        if ($newId <= 0) {
            return;
        }

        $price = ProtergiaPrices2026::all()[$new];

        $wpdb->update(
            $programs,
            [
                'price_kwh'     => $price['price_kwh'],
                'fixed_charge'  => $price['fixed_charge'],
                'pricing'       => (string) json_encode($price['pricing'], JSON_UNESCAPED_UNICODE),
                'price_updated' => ProtergiaPrices2026::AS_OF_NOV,
            ],
            ['id' => $newId, 'price_updated' => null]
        );

        $wpdb->update(
            $programs,
            ['exit_fees' => (string) json_encode(ProtergiaPrices2026::exitFees()[$new])],
            ['id' => $newId, 'exit_fees' => null]
        );
    }

    /** Οι ενεργοί κανόνες του παλιού προγράμματος, ξανά, για το νέο. */
    private function copyRules(SchemaInspector $schema, string $table, int $fromId, int $toId): void
    {
        global $wpdb;

        if (! $schema->hasTable($table)) {
            return;
        }

        /** @var list<array<string, mixed>> $rules */
        $rules = (array) $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM %i WHERE program_id = %d AND active = 1', $table, $fromId),
            ARRAY_A
        );

        foreach ($rules as $rule) {
            unset($rule['id'], $rule['created_at']);
            $rule['program_id'] = $toId;
            $wpdb->insert($table, $rule);
        }
    }

    private function idOf(int $providerId, string $code): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM %i WHERE provider_id = %d AND code = %s ORDER BY id LIMIT 1',
                Tables::name(Tables::PROGRAMS),
                $providerId,
                $code
            )
        );
    }
}
