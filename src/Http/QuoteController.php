<?php

/**
 * POST /quote/pdf — the savings proposal handed to a prospective customer.
 *
 * Touches no table: numbers in, PDF out. The figures are typed by the agent
 * during the conversation, so the schema below is the only thing standing
 * between a mistyped tariff and a printed promise.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use ECRM_DB;
use ECRM_PDF;
use EnergyCRM\Domain\Quote\SavingsEstimate;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;

final class QuoteController implements Controller
{
    /** A household uses ~3.500 kWh a year; a large factory, millions. */
    private const MAX_CONSUMPTION = 100000000.0;

    /** No Greek tariff is anywhere near this. It exists to catch typos. */
    private const MAX_UNIT_PRICE = 10.0;

    private const MAX_FIXED_CHARGE = 10000.0;

    public function routes(): void
    {
        $price = [
            'type'    => 'number',
            'default' => 0,
            'minimum' => 0,
            'maximum' => self::MAX_UNIT_PRICE,
        ];

        $fixed = [
            'type'    => 'number',
            'default' => 0,
            'minimum' => 0,
            'maximum' => self::MAX_FIXED_CHARGE,
        ];

        register_rest_route(Router::NAMESPACE, '/quote/pdf', [
            'methods'             => 'POST',
            'callback'            => [$this, 'create'],
            'permission_callback' => Guards::crmUser(),
            'args'                => [
                'consumption'   => [
                    'type'    => 'number',
                    'default' => 0,
                    'minimum' => 0,
                    'maximum' => self::MAX_CONSUMPTION,
                ],
                'current_price' => $price,
                'offered_price' => $price,
                'current_fixed' => $fixed,
                'offered_fixed' => $fixed,
                'customer_name' => $this->text(),
                'provider_name' => $this->text(),
                'program_name'  => $this->text(),
                'energy'        => [
                    'type'    => 'string',
                    'default' => 'power',
                    'enum'    => ['power', 'gas', 'mobile'],
                ],
            ],
        ]);
    }

    public function create(WP_REST_Request $request): WP_REST_Response
    {
        $estimate = SavingsEstimate::compare(
            (float) $request['consumption'],
            (float) $request['current_price'],
            (float) $request['current_fixed'],
            (float) $request['offered_price'],
            (float) $request['offered_fixed'],
        );

        try {
            $bytes = ECRM_PDF::build_quote([
                'customer'       => (string) $request['customer_name'],
                'provider'       => (string) $request['provider_name'],
                'program'        => (string) $request['program_name'],
                'energy'         => ECRM_DB::energy_label((string) $request['energy']),
                'consumption'    => (float) $request['consumption'],
                'current_price'  => (float) $request['current_price'],
                'current_fixed'  => (float) $request['current_fixed'],
                'offered_price'  => (float) $request['offered_price'],
                'offered_fixed'  => (float) $request['offered_fixed'],
                'current_annual' => $estimate->currentAnnual,
                'offered_annual' => $estimate->offeredAnnual,
                'savings'        => $estimate->savings,
                'pct'            => $estimate->percentage,
            ]);
        } catch (Throwable $e) {
            // The message names a font or a temp path, so it stays in the log.
            error_log('ECRM quote PDF: ' . $e->getMessage());

            return new WP_REST_Response(
                ['ok' => false, 'error' => 'Δεν ήταν δυνατή η δημιουργία της προσφοράς.'],
                500
            );
        }

        return new WP_REST_Response([
            'ok'        => true,
            'b64'       => base64_encode($bytes),
            'filename'  => 'prosfora.pdf',
            'mime'      => 'application/pdf',
            'savings'   => round($estimate->savings, 2),
            // So the UI can warn before the agent prints a worse deal.
            'worse_off' => $estimate->isWorseOff(),
        ], 200);
    }

    /**
     * @return array<string, mixed>
     */
    private function text(): array
    {
        return [
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => 'sanitize_text_field',
        ];
    }
}
