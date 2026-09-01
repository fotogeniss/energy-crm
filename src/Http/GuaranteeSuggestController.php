<?php

/**
 * GET /guarantee/suggest — προτεινόμενο ποσό εγγύησης για τον τρέχοντα
 * συνδυασμό πάροχου/προγράμματος/κατηγορίας/ισχύος, δίπλα στο πεδίο της
 * φόρμας. Ίδιο σχήμα με το `/forms/fields` (ProviderFormController): ένα
 * resource, ρητό args schema, καμία εγγραφή στη βάση.
 *
 * Στάδιο 3 της μηχανής εγγυήσεων (CHANGELOG (210), (211)). Η επιλογή κανόνα
 * ζει στο `Domain\Guarantee\GuaranteeMatch` -- εδώ μένει μόνο η μετάφραση
 * αιτήματος→κλήση και η σειρά που ζήτησε ο ιδιοκτήτης: πρόταση δίπλα στο
 * πεδίο με κουμπί «Χρήση», ΠΟΤΕ αυτόματο γέμισμα. Το endpoint γι' αυτό δεν
 * γράφει ΠΟΥΘΕΝΑ -- ο πωλητής είναι αυτός που αποφασίζει αν θα χρησιμοποιήσει
 * την τιμή, με το ίδιο του το κλικ, όχι ο server.
 *
 * `amount: null` (καμία πρόταση) και `amount: 0` (πρόταση μηδέν) είναι δύο
 * διαφορετικές απαντήσεις σκόπιμα -- δες `GuaranteeMatch` για το γιατί.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use EnergyCRM\Domain\Guarantee\GuaranteeMatch;
use EnergyCRM\Persistence\GuaranteeRuleRepository;
use WP_REST_Request;
use WP_REST_Response;

final class GuaranteeSuggestController implements Controller
{
    public function __construct(private GuaranteeRuleRepository $rules)
    {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/guarantee/suggest', [
            'methods'             => 'GET',
            'callback'            => [$this, 'suggest'],
            'permission_callback' => Guards::crmUser(),
            'args'                => [
                'provider_id' => [
                    'type'    => 'integer',
                    'default' => 0,
                    'minimum' => 0,
                ],
                'program_id' => [
                    'type'    => 'integer',
                    'default' => 0,
                    'minimum' => 0,
                ],
                'energy_type' => [
                    'type'    => 'string',
                    'default' => 'power',
                    'enum'    => ['power', 'gas', 'mobile'],
                ],
                'category' => [
                    'type'    => 'string',
                    'default' => 'home',
                    'enum'    => ['home', 'business', 'communal'],
                ],
                // Ελεύθερο κείμενο -- ό,τι έχει γράψει μέχρι στιγμής ο πωλητής
                // στο «Συμφωνημένη Ισχύς (kVA)». Ο μετατροπέας του GuaranteeMatch
                // διαβάζει «8», «8,5» και «8 kVA» το ίδιο· εδώ δεν χρειάζεται
                // αυστηρότερο σχήμα απ' αυτό.
                'agreed_power' => [
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    public function suggest(WP_REST_Request $request): WP_REST_Response
    {
        $contract = [
            'provider_id'  => (int) $request['provider_id'],
            'program_id'   => (int) $request['program_id'],
            'energy_type'  => (string) $request['energy_type'],
            'category'     => (string) $request['category'],
            'agreed_power' => (string) $request['agreed_power'],
        ];

        $amount = GuaranteeMatch::amountFor($this->rules->active(), $contract);

        return new WP_REST_Response([
            'ok'     => true,
            // null ξεχωριστό από 0.0 στο JSON -- η JS το βλέπει ως `null` έναντι `0`.
            'amount' => $amount,
        ], 200);
    }
}
