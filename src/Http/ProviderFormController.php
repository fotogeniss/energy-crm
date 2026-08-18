<?php

/**
 * GET /forms/fields — which inputs the selected provider's application needs.
 *
 * The first controller written the way the rest will be: one resource, an
 * explicit argument schema, and validation handled by WordPress before the
 * callback runs rather than by hand inside it.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use ECRM_FormFill;
use EnergyCRM\Domain\Forms\ProviderFormFields;
use EnergyCRM\Plugin;
use WP_REST_Request;
use WP_REST_Response;

final class ProviderFormController implements Controller
{
    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/forms/fields', [
            'methods'             => 'GET',
            'callback'            => [$this, 'fields'],
            'permission_callback' => Guards::crmUser(),
            'args'                => [
                'provider' => [
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'energy' => [
                    'type'    => 'string',
                    'default' => 'power',
                    'enum'    => ['power', 'gas', 'mobile'],
                ],
                'customer_type' => [
                    'type'    => 'string',
                    'default' => 'individual',
                    'enum'    => ['individual', 'sole_prop', 'company'],
                ],
                'program' => [
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'activation_type' => [
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_key',
                ],
            ],
        ]);
    }

    public function fields(WP_REST_Request $request): WP_REST_Response
    {
        $template = ECRM_FormFill::template_key(
            (string) $request['provider'],
            (string) $request['energy'],
            (string) $request['customer_type'],
            (string) $request['program'],
            (string) $request['activation_type']
        );

        // Άγνωστος συνδυασμός: ΚΑΝΕΝΑ φιλτράρισμα. Το κενό main_inputs σημαίνει
        // «δεν ξέρω τι τυπώνεται», και η οθόνη πρέπει να δείξει τα πάντα — ποτέ
        // «δεν χρειάζεται τίποτα». Ένας πάροχος χωρίς έντυπο δίνει τη σημερινή
        // φόρμα ολόκληρη, όχι μια φόρμα που δεν συμπληρώνεται.
        if ($template === '') {
            return new WP_REST_Response([
                'ok'          => true,
                'template'    => '',
                'fields'      => [],
                'main_inputs' => [],
            ], 200);
        }

        $dir = (Plugin::instance()?->dir() ?? '') . 'assets/forms';

        return new WP_REST_Response([
            'ok'       => true,
            'template' => $template,

            // Τα ΕΞΤΡΑ του παρόχου, που δεν υπάρχουν στην κύρια φόρμα.
            'fields'   => ProviderFormFields::forTemplate($template, $dir),

            // Και ο καθρέφτης τους: ποια από τα πεδία της ΚΥΡΙΑΣ φόρμας
            // καταλήγουν στο χαρτί. Ταξιδεύει στο ίδιο αίτημα επίτηδες — η JS
            // το ζητάει ήδη σε κάθε αλλαγή παρόχου, προγράμματος ή τύπου
            // πελάτη, που είναι ακριβώς οι στιγμές που αλλάζει η απάντηση.
            'main_inputs' => ProviderFormFields::mainFormInputsForTemplate($template, $dir),
        ], 200);
    }
}
