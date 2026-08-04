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
use EnergyCRM\Access\Capability;
use EnergyCRM\Domain\Forms\ProviderFormFields;
use EnergyCRM\Plugin;
use WP_REST_Request;
use WP_REST_Response;

final class ProviderFormController
{
    private const NAMESPACE = 'ecrm/v1';

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        register_rest_route(self::NAMESPACE, '/forms/fields', [
            'methods'             => 'GET',
            'callback'            => [$this, 'fields'],
            'permission_callback' => static fn (): bool =>
                is_user_logged_in() && current_user_can(Capability::USE_CRM),
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

        if ($template === '') {
            return new WP_REST_Response(['ok' => true, 'template' => '', 'fields' => []], 200);
        }

        $dir = (Plugin::instance()?->dir() ?? '') . 'assets/forms';

        return new WP_REST_Response([
            'ok'       => true,
            'template' => $template,
            'fields'   => ProviderFormFields::forTemplate($template, $dir),
        ], 200);
    }
}
