<?php

/**
 * Which CRM inputs a given provider application actually needs, and what to
 * call them on screen.
 *
 * Every provider asks for a different subset under its own wording. Showing an
 * agent one generic form and hoping they know which boxes matter for today's
 * provider is how applications come back rejected.
 *
 * Two rules govern the caption:
 *
 *   1. A field never shows its internal key. If nothing better is known, it
 *      falls back to the Greek name in LABELS — a screen that reads
 *      "contact_onomateponymo" is a bug, not a label.
 *   2. Where the provider's own wording is unambiguous it wins, because the
 *      point is that the agent reads on screen what is printed on the paper.
 *      For contact and legal-representative boxes it does not win: the form
 *      says plain "Τηλέφωνο" and that could be anyone's.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Forms;

final class ProviderFormFields
{
    /**
     * Fill keys backed by customer or contract columns. Already on the main
     * form, so never repeated in the provider-specific section.
     *
     * @var list<string>
     */
    private const FROM_COLUMNS = [
        'onomateponymo_pelati', 'eponymo_pelati', 'onoma_pelati', 'patronymo_pelati', 'eponymia_etaireias',
        'afm_pelati', 'afm_etaireias', 'doy_pelati', 'adt_pelati', 'imerominia_gennisis',
        'tilefono_pelati', 'kinito_pelati', 'email_pelati', 'epaggelma_pelati',
        'odos_arithmos_katoikias', 'dieuthynsi_katoikias', 'arithmos_odou_katoikias', 'poli_katoikias', 'tk_katoikias', 'nomos_katoikias',
        'dieuthynsi_paroxis', 'odos_arithmos_paroxis', 'odos_paroxis',
        'arithmos_odou_paroxis', 'poli_paroxis', 'tk_paroxis', 'nomos_paroxis',
        'dieuthynsi_apostolis', 'odos_arithmos_apostolis', 'odos_apostolis',
        'arithmos_odou_apostolis', 'poli_apostolis', 'tk_apostolis', 'nomos_apostolis',
        'arithmos_paroxis', 'hkasp', 'arithmos_metriti', 'kodikos_timologiou', 'onoma_programmatos',
        'diarkeia_symvasis', 'arithmos_aitisis', 'imerominia_aitisis', 'imerominia_liksis', 'topos_aitisis',
        'topos_imerominia_aitisis', 'eponymia_etaireias_mas', 'onomateponymo_politi', 'kodikos_synergati',
        'typos_pelati_idiotis', 'typos_pelati_atomiki', 'typos_pelati_etaireia', 'katigoria_paroxis_oikiaki',
        'katigoria_paroxis_epaggelmatiki', 'energopoiisi_allagi_paroxou', 'energopoiisi_diadoxi', 'energopoiisi_epanasyndesi',
        'energopoiisi_ananeosi', 'energopoiisi_nea_syndesi', 'energopoiisi_allagi_programmatos', 'energopoiisi_apaiteitai',
        'diarkeia_aoristou', 'diarkeia_6_mines', 'diarkeia_12_mines', 'diarkeia_18_mines', 'diarkeia_24_mines', 'diarkeia_36_mines',
        'ypovoli_ilektronika', 'ypovoli_taxydromika',
    ];

    /**
     * Fill key => the CRM inputs that supply it.
     *
     * Several fill keys share one input: a single-choice group on paper is one
     * dropdown on screen. And one fill key can need two inputs: a person's
     * name is a first and a last.
     *
     * @var array<string, list<string>>
     */
    private const INPUTS = [
        'kad'                        => ['kad'],
        'gemi'                       => ['gemi'],
        'nomiki_morfi'               => ['company_type'],
        'antikeimeno_epixeirisis'    => ['activity'],
        'eidiki_katigoria'           => ['eidiki_katigoria'],
        'iban'                       => ['iban'],
        'anotato_orio'               => ['anotato_orio'],
        'arithmos_koinoxristou'      => ['ar_koinoxristou'],
        'isxis_paroxis'              => ['agreed_power'],
        'teleftaia_endeixi_metriti'  => ['day_indication'],
        'poso_eggiisis'              => ['guarantee'],
        'ipistamenos_promitheftis'   => ['previous_provider'],
        'idiotita_idioktitis'        => ['capacity_role'],
        'idiotita_misthotis'         => ['capacity_role'],
        'thesi_metriti_esoterikos'   => ['meter_position'],
        'thesi_metriti_exoterikos'   => ['meter_position'],
        'metrisi_imerisia'           => ['meter_reading_type'],
        'metrisi_imerisia_nyxterini' => ['meter_reading_type'],
        'metrisi_tilemetroumeni'     => ['meter_reading_type'],
        'pliromi_pagia_entoli'       => ['payment_method'],
        'onomateponymo_ekprosopou'   => ['rep_first_name', 'rep_last_name'],
        'onomateponymo_epikoinonias' => ['contact_first_name', 'contact_last_name'],
        'adt_epikoinonias'           => ['contact_adt'],
        'afm_epikoinonias'           => ['contact_afm'],
        'tilefono_epikoinonias'      => ['contact_phone'],
        'kinito_epikoinonias'        => ['contact_mobile'],
        'email_epikoinonias'         => ['contact_email'],
    ];

    /**
     * What each input is called in the CRM's own words.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        'kad'                => 'Κ.Α.Δ.',
        'gemi'               => 'Αρ. Γ.Ε.ΜΗ.',
        'company_type'       => 'Νομική Μορφή',
        'activity'           => 'Αντικείμενο Δραστηριότητας',
        'eidiki_katigoria'   => 'Ειδική Κατηγορία (Ευάλωτος / Κ.Ο.Τ.)',
        'iban'               => 'IBAN Πάγιας Εντολής',
        'anotato_orio'       => 'Ανώτατο Όριο Λογαριασμού (€)',
        'ar_koinoxristou'    => 'Αρ. Κοινόχρηστου Μετρητή',
        'agreed_power'       => 'Συμφωνημένη Ισχύς (kVA)',
        'day_indication'     => 'Τελευταία Ένδειξη Μετρητή',
        'guarantee'          => 'Εγγύηση (€)',
        'previous_provider'  => 'Υφιστάμενος Πάροχος',
        'capacity_role'      => 'Ιδιότητα (Ιδιοκτήτης / Ενοικιαστής)',
        'meter_position'     => 'Θέση Μετρητή (Εσωτερικός / Εξωτερικός)',
        'meter_reading_type' => 'Είδος Μέτρησης',
        'payment_method'     => 'Τρόπος Πληρωμής',
        'rep_first_name'     => 'Όνομα Νόμιμου Εκπροσώπου',
        'rep_last_name'      => 'Επώνυμο Νόμιμου Εκπροσώπου',
        'contact_first_name' => 'Όνομα Υπεύθυνου Επικοινωνίας',
        'contact_last_name'  => 'Επώνυμο Υπεύθυνου Επικοινωνίας',
        'contact_adt'        => 'Α.Δ.Τ. Υπεύθυνου Επικοινωνίας',
        'contact_afm'        => 'ΑΦΜ Υπεύθυνου Επικοινωνίας',
        'contact_phone'      => 'Τηλέφωνο Υπεύθυνου Επικοινωνίας',
        'contact_mobile'     => 'Κινητό Υπεύθυνου Επικοινωνίας',
        'contact_email'      => 'Email Υπεύθυνου Επικοινωνίας',
    ];

    /**
     * Inputs rendered as a single-choice dropdown.
     *
     * On paper these are a row of boxes, so the caption next to any one of them
     * is an *option* — "ΜΙΣΘΩΤΗΣ", "Ημερήσια & Νυχτερινή" — never the question
     * being asked. Using it as the field label would tell the agent to enter
     * one specific answer.
     *
     * @var list<string>
     */
    private const DROPDOWNS = [
        'capacity_role', 'meter_position', 'meter_reading_type', 'payment_method',
    ];

    private function __construct()
    {
    }

    /**
     * Inputs required by a template, keyed by input name.
     *
     * @return array<string, array{label: string, onForm: string, source: string}>
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

        /** @var array<string, string> $printed */
        $printed = is_array($map['labels'] ?? null) ? $map['labels'] : [];
        $needed  = array_keys(is_array($map['fields'] ?? null) ? $map['fields'] : []);
        $out     = [];

        foreach ($needed as $fillKey) {
            if (in_array($fillKey, self::FROM_COLUMNS, true)) {
                continue;
            }

            $inputs = self::INPUTS[$fillKey] ?? [];

            foreach ($inputs as $input) {
                // Two fill keys can share an input — ΙΔΙΟΚΤΗΤΗΣ and ΜΙΣΘΩΤΗΣ
                // are one dropdown — so the first caption wins.
                if (isset($out[$input])) {
                    continue;
                }

                $out[$input] = [
                    'label'  => self::caption($input, $inputs, (string) ($printed[$fillKey] ?? '')),
                    'onForm' => (string) ($printed[$fillKey] ?? ''),
                    'source' => $fillKey,
                ];
            }
        }

        return $out;
    }

    /**
     * @param list<string> $siblings Inputs sharing the same fill key.
     */
    private static function caption(string $input, array $siblings, string $printed): string
    {
        $ours = self::LABELS[$input] ?? $input;

        // One paper box split across two inputs: the provider's single caption
        // cannot tell them apart, so ours has to.
        if (count($siblings) > 1) {
            return $ours;
        }

        // "Τηλέφωνο" on a contact-person line is ambiguous once it sits in a
        // section of its own; keep the qualified name.
        if (str_starts_with($input, 'contact_') || str_starts_with($input, 'rep_')) {
            return $ours;
        }

        if (in_array($input, self::DROPDOWNS, true)) {
            return $ours;
        }

        $printed = trim($printed);

        if ($printed === '' || $printed === $input) {
            return $ours;
        }

        // A whole sentence scraped off the page, or a quoted clause, is not a
        // caption. Forty-five characters is roughly where a label stops being
        // readable in a form grid.
        if (mb_strlen($printed) > 45 || str_contains($printed, '“') || str_contains($printed, '"')) {
            return $ours;
        }

        // Fragments like "AM (kWh)" carry no Greek at all and mean nothing on
        // their own; three Greek letters is the floor for a real caption.
        if (preg_match_all('/\p{Greek}/u', $printed) < 3) {
            return $ours;
        }

        // "ΕΓΓΥΗΣΗΣ" is the tail of "ΠΟΣΟ ΕΓΓΥΗΣΗΣ" that wrapped onto its own
        // line. A caption is a thing, not a genitive hanging off one.
        if (! str_contains($printed, ' ') && preg_match('/(ΗΣ|ΟΥ|ΩΝ)$/u', $printed) === 1) {
            return $ours;
        }

        return $printed;
    }
}
