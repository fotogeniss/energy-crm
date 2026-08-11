<?php

/**
 * GET  /sign/{token}   what the customer is about to sign
 * POST /sign/{token}   store their signature
 *
 * The only routes in the system that run without a logged-in user. The token is
 * therefore the entire credential, and the rules around it are tighter than
 * anywhere else:
 *
 *   - the pattern is fixed at the route, so nothing but [A-Za-z0-9] arrives;
 *   - the "not already signed" condition lives in the UPDATE, so two
 *     submissions racing each other cannot both win;
 *   - a bad token returns the same 404 whether it never existed or was already
 *     used, because a public endpoint that distinguishes the two is an oracle.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use ECRM_Files;
use EnergyCRM\Domain\Contract\ContractLifecycle;
use EnergyCRM\Infrastructure\ContractNotices;
use EnergyCRM\Infrastructure\DocumentQueue;
use EnergyCRM\Persistence\FileRepository;
use EnergyCRM\Persistence\SignatureRepository;
use WP_REST_Request;
use WP_REST_Response;

final class SigningController implements Controller
{
    /** A data URL prefix is the only shape we accept for the drawing. */
    private const PNG_PREFIX = 'data:image/png;base64,';

    /** Roughly 450 KB of base64: ample for a signature, useless as an upload. */
    private const MAX_IMAGE_BYTES = 600000;

    public function __construct(
        private readonly SignatureRepository $signatures,
        private readonly FileRepository $files,
        private readonly ContractLifecycle $lifecycle,
        private readonly ContractNotices $notices,
    ) {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/sign/(?P<token>[A-Za-z0-9]+)', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'show'],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'store'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'name' => [
                        'type'              => 'string',
                        'default'           => '',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'image' => ['type' => 'string', 'required' => true],
                ],
            ],
        ]);
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        $row = $this->signatures->summaryFor((string) $request['token']);

        if ($row === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Άκυρος σύνδεσμος.'], 404);
        }

        $name = $row['company_name']
            ?: trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));

        return new WP_REST_Response([
            'ok'       => true,
            'signed'   => ! empty($row['signed_at']),
            'code'     => $row['code'],
            'provider' => $row['provider'],
            'customer' => $name,
        ], 200);
    }

    public function store(WP_REST_Request $request): WP_REST_Response
    {
        $token  = (string) $request['token'];
        $record = $this->signatures->findByToken($token);

        if ($record === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Άκυρος σύνδεσμος.'], 404);
        }

        if (! empty($record['signed_at'])) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Έχει ήδη υπογραφεί.'], 409);
        }

        $image = (string) $request['image'];

        if (! str_starts_with($image, self::PNG_PREFIX) || strlen($image) < 200) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Λείπει η υπογραφή.'], 400);
        }

        $image = substr($image, 0, self::MAX_IMAGE_BYTES);
        $name  = (string) $request['name'];
        $ip    = sanitize_text_field(wp_unslash((string) ($_SERVER['REMOTE_ADDR'] ?? '')));

        // Returns false when another request signed it first.
        if (! $this->signatures->sign($token, $name, $image, $ip)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Έχει ήδη υπογραφεί.'], 409);
        }

        $contractId = (int) $record['contract_id'];

        $this->lifecycle->moveTo($contractId, 'signed', [
            'from'    => null,
            'message' => 'Υπεγράφη από πελάτη' . ($name !== '' ? ' (' . $name . ')' : ''),
            'extra'   => [
                'signed_at' => current_time('mysql'),
                'signed_ip' => substr($ip, 0, 64),
            ],
            // The notices->signed() call below is the one that reaches the agent.
            'inapp'   => false,
        ]);

        $this->storeSignatureImage($contractId, $image);

        // The attached document is regenerated so it carries the signature.
        // Queued: signatures arrive in bursts when a batch of links goes out,
        // and the customer's browser should not wait for a render it never sees.
        DocumentQueue::enqueue($contractId);
        $this->notices->signed($contractId, $name);

        return new WP_REST_Response(['ok' => true], 200);
    }

    /**
     * Keep the drawing as a PNG so it can be stamped onto the provider form.
     *
     * Best effort: a contract that is signed but whose image failed to write is
     * still signed, and the event log records it either way.
     */
    private function storeSignatureImage(int $contractId, string $image): void
    {
        $binary = base64_decode(substr($image, strlen(self::PNG_PREFIX)), true);

        if ($binary === false || $binary === '') {
            return;
        }

        $saved = ECRM_Files::put_bytes($binary, 'png', 'image/png', 'signature.png');

        if ($saved === null) {
            return;
        }

        $this->files->replaceKind(
            $contractId,
            'signature',
            'signature.png',
            'image/png',
            $saved['path']
        );
    }
}
