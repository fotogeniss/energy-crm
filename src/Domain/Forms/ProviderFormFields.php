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
        'odos_arithmos_katoikias', 'dieuthynsi_katoikias', 'arithmos_odou_katoikias', 'odos_katoikias',
        'poli_katoikias', 'tk_katoikias', 'nomos_katoikias',
        'dieuthynsi_paroxis', 'odos_arithmos_paroxis', 'odos_paroxis',
        'arithmos_odou_paroxis', 'poli_paroxis', 'tk_paroxis', 'nomos_paroxis',
        'dieuthynsi_apostolis', 'dieuthynsi_apostolis_etiketa', 'odos_arithmos_apostolis', 'odos_apostolis',
        'arithmos_odou_apostolis', 'poli_apostolis', 'tk_apostolis', 'nomos_apostolis',
        'arithmos_paroxis', 'hkasp', 'arithmos_metriti', 'kodikos_timologiou', 'onoma_programmatos',
        'diarkeia_symvasis', 'arithmos_aitisis', 'imerominia_aitisis', 'imerominia_liksis', 'topos_aitisis',
        'topos_imerominia_aitisis', 'eponymia_etaireias_mas', 'onomateponymo_politi', 'kodikos_synergati',
        'typos_pelati_idiotis', 'typos_pelati_atomiki', 'typos_pelati_etaireia', 'katigoria_paroxis_oikiaki',
        'katigoria_paroxis_epaggelmatiki', 'energopoiisi_allagi_paroxou',
        'energopoiisi_diadoxi', 'energopoiisi_epanasyndesi', 'energopoiisi_ananeosi',
        'energopoiisi_nea_syndesi', 'energopoiisi_allagi_programmatos', 'energopoiisi_apaiteitai',
        'diarkeia_aoristou', 'diarkeia_6_mines', 'diarkeia_12_mines',
        'diarkeia_18_mines', 'diarkeia_24_mines', 'diarkeia_36_mines',
        'ypovoli_ilektronika', 'ypovoli_taxydromika',

        // Κινητή τηλεφωνία -- έλειπαν εντελώς από εδώ (214). Τα τρία έντυπα
        // Orizon (family/combo/mobile) τα τυπώνουν ήδη μέσω του
        // class-ecrm-formfill.php· το φίλτρο απλώς δεν το ήξερε.
        'arithmos_kinitou', 'deuteros_arithmos_kinitou', 'arithmos_sim',
        'typos_epidotisis', 'poso_eggiisis', 'tropos_apostolis_logariasmou',
        'combo_arithmos_paroxis', 'combo_onoma_programmatos',
        'energopoiisi_foritotita',
        'programma_5gb', 'programma_10gb_5gb', 'programma_40gb', 'programma_unlimited',
        'xristis_kyrios', 'xristis_defterevon',

        // Το δεύτερο μπλοκ ταυτότητας του COMBO (219/220): «ΣΤΟΙΧΕΙΑ ΠΕΛΑΤΗ
        // ΕΝΕΡΓΕΙΑΣ», που μπορεί να είναι άλλο πρόσωπο από τον πελάτη κινητής.
        // Εδώ μέσα σημαίνει «μη ζητηθεί ξανά στο "Πάνω στο έντυπο"» -- η φόρμα
        // τα συλλέγει ήδη στην κάρτα Στοιχεία Κινητής.
        'onomateponymo_energeias', 'adt_energeias', 'afm_energeias', 'doy_energeias',
        'xristis_kyrios_energeias', 'xristis_defterevon_energeias',
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
        'tropos_apostolis_logariasmou' => ['bill_delivery'],
        'onomateponymo_ekprosopou'   => ['rep_first_name', 'rep_last_name'],
        'onomateponymo_epikoinonias' => ['contact_first_name', 'contact_last_name'],
        'adt_epikoinonias'           => ['contact_adt'],
        'afm_epikoinonias'           => ['contact_afm'],
        'tilefono_epikoinonias'      => ['contact_phone'],
        'kinito_epikoinonias'        => ['contact_mobile'],
        'email_epikoinonias'         => ['contact_email'],

        // Κινητή τηλεφωνία
        'arithmos_kinitou'           => ['mobile_msisdn'],
        'deuteros_arithmos_kinitou'  => ['mobile_msisdn_2'],
        'arithmos_sim'               => ['sim_number'],
        'typos_epidotisis'           => ['subsidy_type'],
        'arxiki_timi_pagiou'         => ['base_price'],
        'timi_prosforas'             => ['offer_price'],
        'pagio_meta_ti_prosfora'     => ['price_after'],

        // Ερωτήσεις προς τον πελάτη — κάθε Ναι/Όχι είναι δύο κουτιά στο
        // έντυπο αλλά μία ερώτηση στην οθόνη.
        'orio_logariasmou_nai'       => ['bill_cap'],
        'orio_logariasmou_oxi'       => ['bill_cap'],
        'mitroo_11_nai'              => ['no_marketing_calls'],
        'mitroo_11_oxi'              => ['no_marketing_calls'],
        'synainesi_omilou_nai'       => ['group_data_consent'],
        'synainesi_omilou_oxi'       => ['group_data_consent'],
        'synainesi_erevnas_nai'      => ['survey_consent'],
        'synainesi_erevnas_oxi'      => ['survey_consent'],
        'enarksi_stin_ypanaxorisi'   => ['waive_withdrawal'],
        'mi_katachorisi_katalogous'  => ['no_directory_listing'],
    ];

    /**
     * Fill keys backed by a column, mapped to the inputs of the MAIN form.
     *
     * FROM_COLUMNS answers «is this already on the main form?» so the ★ section
     * does not repeat it. That was the only question anyone asked, so the
     * answer stayed a flat list. But the list is only half the knowledge: it
     * knows the key is on the main form and not WHERE. That is why the main
     * form still shows all 63 of its inputs for a template that prints seven.
     *
     * This map is the other half, and it is what lets the form ask only what
     * will be printed. It duplicates on purpose what class-ecrm-formfill.php
     * does in the opposite direction — that file goes contract → paper, this
     * one goes paper → screen — and the two are kept honest by
     * ProviderFormFieldsColumnsTest, which fails if a FROM_COLUMNS key has no
     * entry here.
     *
     * Keys that no input can supply are mapped to an empty list rather than
     * left out: «the paper prints it, nobody types it» is a real answer, and
     * omitting them would make a missing entry indistinguishable from a
     * forgotten one.
     *
     * @var array<string, list<string>>
     */
    private const COLUMN_INPUTS = [
        // Πελάτης
        'onomateponymo_pelati'    => ['first_name', 'last_name'],
        'eponymo_pelati'          => ['last_name'],
        'onoma_pelati'            => ['first_name'],
        'patronymo_pelati'        => ['father_name'],
        'eponymia_etaireias'      => ['company_name'],
        'afm_pelati'              => ['afm'],
        'afm_etaireias'           => ['afm'],
        'doy_pelati'              => ['doy'],
        'adt_pelati'              => ['adt'],
        'imerominia_gennisis'     => ['birth_date'],
        'tilefono_pelati'         => ['phone'],
        'kinito_pelati'           => ['mobile'],
        'email_pelati'            => ['email'],
        'epaggelma_pelati'        => ['activity'],

        // Διεύθυνση κατοικίας
        'odos_arithmos_katoikias' => ['street', 'street_no'],
        'dieuthynsi_katoikias'    => ['street', 'street_no', 'city', 'postal_code'],
        'arithmos_odou_katoikias' => ['street_no'],
        'odos_katoikias'          => ['street'],
        'poli_katoikias'          => ['city'],
        'tk_katoikias'            => ['postal_code'],
        'nomos_katoikias'         => ['region'],

        // Διεύθυνση παροχής — εκεί που είναι ο μετρητής
        'dieuthynsi_paroxis'      => ['supply_street', 'supply_street_no', 'supply_city', 'supply_postal_code'],
        'odos_arithmos_paroxis'   => ['supply_street', 'supply_street_no'],
        'odos_paroxis'            => ['supply_street'],
        'arithmos_odou_paroxis'   => ['supply_street_no'],
        'poli_paroxis'            => ['supply_city'],
        'tk_paroxis'              => ['supply_postal_code'],
        'nomos_paroxis'           => ['supply_region'],

        // Διεύθυνση αποστολής λογαριασμού
        'dieuthynsi_apostolis'     => ['billing_street', 'billing_street_no', 'billing_city', 'billing_postal_code'],
        'dieuthynsi_apostolis_etiketa' => [
            'billing_street', 'billing_street_no', 'billing_city', 'billing_postal_code',
        ],
        'odos_arithmos_apostolis'  => ['billing_street', 'billing_street_no'],
        'odos_apostolis'           => ['billing_street'],
        'arithmos_odou_apostolis'  => ['billing_street_no'],
        'poli_apostolis'           => ['billing_city'],
        'tk_apostolis'             => ['billing_postal_code'],
        'nomos_apostolis'          => ['billing_region'],

        // Παροχή και μετρητής
        'arithmos_paroxis'        => ['supply_number'],
        'hkasp'                   => ['supply_number'],
        'arithmos_metriti'        => ['meter_number'],

        // Το τυπώνει η μηχανή, δεν το πληκτρολογεί κανείς: προκύπτει από τα
        // chips του βήματος 1, από τη βάση, ή από τον ίδιο τον λογαριασμό.
        'kodikos_timologiou'             => [],
        'onoma_programmatos'             => [],
        'diarkeia_symvasis'              => [],
        'arithmos_aitisis'               => [],
        'imerominia_aitisis'             => [],
        'imerominia_liksis'              => ['end_date'],
        'topos_aitisis'                  => [],
        'topos_imerominia_aitisis'       => [],
        'eponymia_etaireias_mas'         => [],
        'onomateponymo_politi'           => [],
        'kodikos_synergati'              => [],
        'typos_pelati_idiotis'           => [],
        'typos_pelati_atomiki'           => [],
        'typos_pelati_etaireia'          => [],
        'katigoria_paroxis_oikiaki'      => [],
        'katigoria_paroxis_epaggelmatiki' => [],
        'energopoiisi_allagi_paroxou'    => [],
        'energopoiisi_diadoxi'           => [],
        'energopoiisi_epanasyndesi'      => [],
        'energopoiisi_ananeosi'          => [],
        'energopoiisi_nea_syndesi'       => [],
        'energopoiisi_allagi_programmatos' => [],
        'energopoiisi_apaiteitai'        => [],
        'diarkeia_aoristou'              => [],
        'diarkeia_6_mines'               => [],
        'diarkeia_12_mines'              => [],
        'diarkeia_18_mines'              => [],
        'diarkeia_24_mines'              => [],
        'diarkeia_36_mines'              => [],
        'ypovoli_ilektronika'            => [],
        'ypovoli_taxydromika'            => [],

        // Κινητή τηλεφωνία (214) -- ίδιο σχόλιο με το FROM_COLUMNS παραπάνω.
        'arithmos_kinitou'               => ['mobile_msisdn'],
        'deuteros_arithmos_kinitou'      => ['mobile_msisdn_2'],
        'arithmos_sim'                   => ['sim_number'],
        'typos_epidotisis'               => ['subsidy_type'],
        'poso_eggiisis'                  => ['guarantee'],
        'tropos_apostolis_logariasmou'   => ['bill_delivery'],
        'combo_arithmos_paroxis'         => ['combo_supply_number'],
        'combo_onoma_programmatos'       => ['combo_energy_program'],
        // Παράγονται από το πρόγραμμα/τον ρόλο χρήστη που διαλέχτηκε στο
        // βήμα 1, κανείς δεν τα πληκτρολογεί -- ίδια λογική με το
        // 'onoma_programmatos' λίγο πιο πάνω.
        'energopoiisi_foritotita'        => [],
        'programma_5gb'                  => [],
        'programma_10gb_5gb'             => [],
        'programma_40gb'                 => [],
        'programma_unlimited'            => [],
        'xristis_kyrios'                 => [],
        'xristis_defterevon'             => [],

        // Όταν ο πελάτης ενέργειας είναι ο ίδιος (η προεπιλογή), αυτά
        // αντιγράφονται από τα στοιχεία του πελάτη κινητής και κανείς δεν
        // πληκτρολογεί τίποτα. Τα inputs που δηλώνονται εδώ είναι η ΑΛΛΗ
        // περίπτωση -- ο λόγος που τα κλειδιά υπάρχουν χωριστά.
        'onomateponymo_energeias'        => ['combo_energy_name'],
        'afm_energeias'                  => ['combo_energy_afm'],
        'adt_energeias'                  => ['combo_energy_adt'],
        'doy_energeias'                  => ['combo_energy_doy'],
        // Ανεστραμμένα από το ένα πεδίο ρόλου -- βλ. MobilePaperwork::energyUserTicks().
        'xristis_kyrios_energeias'       => [],
        'xristis_defterevon_energeias'   => [],
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
        'bill_delivery'      => 'Τρόπος Αποστολής Λογαριασμού',
        'rep_first_name'     => 'Όνομα Νόμιμου Εκπροσώπου',
        'rep_last_name'      => 'Επώνυμο Νόμιμου Εκπροσώπου',
        'contact_first_name' => 'Όνομα Υπεύθυνου Επικοινωνίας',
        'contact_last_name'  => 'Επώνυμο Υπεύθυνου Επικοινωνίας',
        'contact_adt'        => 'Α.Δ.Τ. Υπεύθυνου Επικοινωνίας',
        'contact_afm'        => 'ΑΦΜ Υπεύθυνου Επικοινωνίας',
        'contact_phone'      => 'Τηλέφωνο Υπεύθυνου Επικοινωνίας',
        'contact_mobile'     => 'Κινητό Υπεύθυνου Επικοινωνίας',
        'contact_email'      => 'Email Υπεύθυνου Επικοινωνίας',

        'mobile_msisdn'      => 'Αριθμός Κινητού',
        'mobile_msisdn_2'    => 'Αριθμός Κινητού (2ο, Συνδυαστικού)',
        'sim_number'         => 'Αριθμός Κάρτας SIM',
        'subsidy_type'       => 'Τύπος Επιδότησης',
        'base_price'         => 'Αρχική Τιμή Παγίου (€)',
        'offer_price'        => 'Τιμή Προσφοράς ανά Μήνα (€)',
        'price_after'        => 'Πάγιο μετά τη Λήξη της Προσφοράς (€)',

        // COMBO: τα στοιχεία του πελάτη ενέργειας, όταν είναι άλλο πρόσωπο από
        // τον πελάτη κινητής. Ονομάζονται με το «Ενέργειας» μπροστά ώστε στη
        // λίστα «Πάνω στο έντυπο» να μη μοιάζουν με δεύτερο ΑΦΜ του ίδιου.
        'combo_energy_name'  => 'Ονοματεπώνυμο Πελάτη Ενέργειας',
        'combo_energy_afm'   => 'ΑΦΜ Πελάτη Ενέργειας',
        'combo_energy_adt'   => 'Αρ. Ταυτότητας Πελάτη Ενέργειας',
        'combo_energy_doy'   => 'ΔΟΥ Πελάτη Ενέργειας',

        // Διατυπωμένες όπως τις θέτει ο συνεργάτης στον πελάτη, όχι όπως τις
        // γράφει το νομικό κείμενο του εντύπου.
        'bill_cap'           => 'Θέλει ανώτατο όριο λογαριασμού;',
        'no_marketing_calls' => 'Να μπει στο μητρώο του άρθρου 11 (να ΜΗΝ δέχεται προωθητικές κλήσεις);',
        'group_data_consent' => 'Συναινεί στην επεξεργασία δεδομένων από τον όμιλο;',
        'survey_consent'     => 'Δέχεται τηλεφωνικές έρευνες ικανοποίησης πελατών;',
        'waive_withdrawal'   => 'Θέλει άμεση έναρξη, παραιτούμενος από το δικαίωμα υπαναχώρησης;',
        'no_directory_listing' => 'Να ΜΗΝ καταχωρηθεί στους τηλεφωνικούς καταλόγους;',
    ];

    /**
     * Inputs that describe the supply, not the person.
     *
     * Used on erasure: the extras bag is free-form, so anything *not* named
     * here is treated as personal and removed. Getting this list wrong in the
     * safe direction costs a meter reading; getting it wrong the other way
     * leaves an IBAN behind, which is why the default is to delete.
     *
     * Deliberately absent, though they may look technical: ΚΑΔ, ΓΕΜΗ, νομική
     * μορφή and αντικείμενο δραστηριότητας identify a business customer the
     * same way a name identifies a private one; ειδική κατηγορία records that
     * someone is vulnerable or on a social tariff; and the yes/no answers are
     * that person's own choices.
     *
     * `request_type` and `mobile_offer` are product choices (new number vs.
     * porting, which discount route), not facts about a person — erasing them
     * destroys sales-history reporting for no privacy benefit. `combo_supply_number`
     * stays personal on purpose: it is the electricity meter's own supply
     * number, tied to an address (ORIZON-TODO.md #6).
     *
     * @var list<string>
     */
    private const NON_PERSONAL_INPUTS = [
        'anotato_orio', 'ar_koinoxristou', 'agreed_power', 'day_indication', 'guarantee',
        'previous_provider', 'capacity_role', 'meter_position', 'meter_reading_type', 'payment_method', 'bill_delivery',
        'subsidy_type', 'base_price', 'offer_price', 'price_after',
        'request_type', 'mobile_offer',
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
        'bill_cap', 'no_marketing_calls', 'group_data_consent', 'survey_consent',
        'waive_withdrawal', 'no_directory_listing',
    ];

    private function __construct()
    {
    }

    /** Whether an extras-bag key must not survive erasure. */
    public static function isPersonalInput(string $input): bool
    {
        return ! in_array($input, self::NON_PERSONAL_INPUTS, true);
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
     * The MAIN-form inputs a template actually prints, as a flat set.
     *
     * The mirror image of forTemplate(): that one answers «what extra does this
     * provider want?», this one answers «of everything the form already asks,
     * what will end up on the paper?». Both read the same JSON; they differ
     * only in which side of FROM_COLUMNS they keep.
     *
     * An unknown or unreadable template returns an EMPTY list, and the caller
     * must read that as «I don't know» and show everything — never as «nothing
     * is needed». Hiding the whole form because a JSON is missing would turn a
     * packaging mistake into an agent who cannot type an application.
     *
     * @return list<string> Input names, deduplicated, in template order.
     */
    public static function mainFormInputsForTemplate(string $key, string $formsDir): array
    {
        $path = rtrim($formsDir, '/\\') . '/' . $key . '.json';

        if ($key === '' || ! is_readable($path)) {
            return [];
        }

        $map = json_decode((string) file_get_contents($path), true);

        if (! is_array($map)) {
            return [];
        }

        $out = [];

        foreach (array_keys(is_array($map['fields'] ?? null) ? $map['fields'] : []) as $fillKey) {
            foreach (self::COLUMN_INPUTS[$fillKey] ?? [] as $input) {
                $out[$input] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * Every fill key this class claims to know, for the test that keeps
     * COLUMN_INPUTS and FROM_COLUMNS from drifting apart.
     *
     * @return list<string>
     */
    public static function columnFillKeys(): array
    {
        return self::FROM_COLUMNS;
    }

    /**
     * Raw field coordinates for a template -- «πάνω στο έντυπο», 30/08.
     *
     * Same JSON, same page-1-mm-from-top-left convention that
     * class-ecrm-formfill.php already trusts to PRINT the finished PDF; this
     * reads the identical file to POSITION live inputs on top of the page
     * image instead. One file, two consumers, no second measurement to drift
     * out of sync with the first.
     *
     * An unknown or unreadable template returns an empty array -- the caller
     * must read that as «no overlay for this template» and fall back to the
     * classic form, never as an error.
     *
     * @return array{
     *     pageSize: array{w: float, h: float},
     *     fields: array<string, array{page: int, x: float, y: float}>
     * }|array{}
     */
    public static function positionsForTemplate(string $key, string $formsDir): array
    {
        $path = rtrim($formsDir, '/\\') . '/' . $key . '.json';

        if ($key === '' || ! is_readable($path)) {
            return [];
        }

        $map = json_decode((string) file_get_contents($path), true);

        if (! is_array($map) || ! is_array($map['fields'] ?? null)) {
            return [];
        }

        return [
            'pageSize' => [
                'w' => (float) ($map['page_w'] ?? 210.0),
                'h' => (float) ($map['page_h'] ?? 297.0),
            ],
            'fields' => $map['fields'],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function columnInputMap(): array
    {
        return self::COLUMN_INPUTS;
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
