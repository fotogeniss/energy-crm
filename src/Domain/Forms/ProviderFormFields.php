<?php

/**
 * Which CRM inputs a given provider application actually needs.
 *
 * Every provider asks for a different subset, under its own wording: NRG wants
 * "ΙΔΙΟΚΤΗΤΗΣ / ΜΙΣΘΩΤΗΣ", Protergia writes "Ονοματεπώνυμο / επωνυμία
 * επιχείρησης", Volton "ΟΝΟΜΑΤΕΠΩΝΥΜΟ/ΕΠΩΝΥΜΙΑ". Showing an agent one generic
 * form and hoping they know which boxes matter for today's provider is how
 * applications come back rejected.
 *
 * The map files carry a `labels` block written from the provider's own PDF, so
 * the label an agent reads on screen is the label printed on the application
 * they are filling.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Forms;

final class ProviderFormFields
{
    /**
     * Fill keys that come from customer or contract columns and are already on
     * the main form. They never appear in the provider-specific section.
     *
     * @var list<string>
     */
    private const FROM_COLUMNS = [
        'onomateponymo', 'eponymo', 'onoma', 'patronymo', 'eponymia',
        'afm', 'afm_etaireias', 'doy', 'adt', 'birth_date',
        'tilefono', 'kinito', 'email', 'epaggelma',
        'odos', 'dieuthynsi', 'arithmos', 'poli', 'tk', 'nomos',
        'odos_paroxis', 'poli_paroxis', 'tk_paroxis',
        'ar_paroxis', 'hkasp', 'ar_metriti', 'timologio', 'programma',
        'diarkeia', 'ar_aitisis', 'imerominia', 'end_date', 'topos',
        'topos_imerominia', 'synergatis', 'politis', 'kod_synergati',
        'cat_idiotis', 'cat_atomiki', 'cat_etaireia', 'cat_oikiaki',
        'cat_epaggelmatiki', 'act_change', 'act_succession', 'act_reconnection',
        'act_renewal', 'act_new', 'act_program_change', 'act_any',
        'dur_aoristou', 'dur_6', 'dur_12', 'dur_18', 'dur_24', 'dur_36',
        'metr_imerisia', 'metr_imer_nyxt',
    ];

    /**
     * Fill key => the CRM input that supplies it.
     *
     * Several fill keys share one input: a single-choice group on paper is one
     * dropdown on screen, and a person's name is two boxes.
     *
     * @var array<string, list<string>>
     */
    private const INPUTS = [
        'kad'                      => ['kad'],
        'gemi'                     => ['gemi'],
        'nomiki_morfi'             => ['company_type'],
        'antikeimeno'              => ['activity'],
        'eidiki_katigoria'         => ['eidiki_katigoria'],
        'iban'                     => ['iban'],
        'anotato_orio'             => ['anotato_orio'],
        'ar_koinoxristou'          => ['ar_koinoxristou'],
        'isxis_paroxis'            => ['agreed_power'],
        'teleftaia_endeixi_imeras' => ['day_indication'],
        'poso_eggiisis'            => ['guarantee'],
        'ipistamenos_promitheftis' => ['previous_provider'],
        'own_idioktitis'           => ['capacity_role'],
        'own_misthotis'            => ['capacity_role'],
        'metr_esoterikos'          => ['meter_position'],
        'metr_exoterikos'          => ['meter_position'],
        'pagia_entoli'             => ['payment_method'],
        'nomimos_ekprosopos'       => ['rep_first_name', 'rep_last_name'],
        'contact_onomateponymo'    => ['contact_first_name', 'contact_last_name'],
        'contact_adt'              => ['contact_adt'],
        'contact_afm'              => ['contact_afm'],
        'contact_tilefono'         => ['contact_phone'],
        'contact_kinito'           => ['contact_mobile'],
        'contact_email'            => ['contact_email'],
    ];

    private function __construct()
    {
    }

    /**
     * Inputs required by a template, keyed by input name.
     *
     * @return array<string, array{label: string, source: string}>
     */
    public static function forTemplate(string $key, string $formsDir): array
    {
        $path = rtrim($formsDir, '/\\') . '/' . $key . '.json';

        if ($key === '' || ! is_readable($path)) {
            return [];
        }

        $map = json_decode((string) file_get_contents($path), true);

        if (! is_array($map)) {
            return [];
        }

        /** @var array<string, string> $labels */
        $labels = is_array($map['labels'] ?? null) ? $map['labels'] : [];
        $needed = array_keys(is_array($map['fields'] ?? null) ? $map['fields'] : []);
        $out    = [];

        foreach ($needed as $fillKey) {
            if (in_array($fillKey, self::FROM_COLUMNS, true)) {
                continue;
            }

            foreach (self::INPUTS[$fillKey] ?? [] as $input) {
                // First label wins: two fill keys sharing an input (ΙΔΙΟΚΤΗΤΗΣ
                // and ΜΙΣΘΩΤΗΣ) would otherwise fight over the caption.
                if (isset($out[$input])) {
                    continue;
                }

                $out[$input] = [
                    'label'  => (string) ($labels[$fillKey] ?? $fillKey),
                    'source' => $fillKey,
                ];
            }
        }

        return $out;
    }
}
