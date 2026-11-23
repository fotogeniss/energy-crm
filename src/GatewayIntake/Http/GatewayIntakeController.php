<?php

/**
 * POST /gateway-intake   ένας υποψήφιος από το AI τηλεφωνικό κέντρο
 *
 * Χωρίς login: καλεί ο server του Call Gateway, αφού κλείσει η κλήση. Η πόρτα
 * έχει τρεις φράχτες, με αυτή τη σειρά:
 *
 *  1. Είναι ρυθμισμένη (μυστικό ≥ 32, διαφορετικό από του site, λίστα IP).
 *     Αλλιώς κλειστή για όλους.
 *  2. Η IP του αιτήματος είναι στη λίστα.
 *  3. Η υπογραφή HMAC (ίδιο σχήμα με το site, `SiteSignature`) πάνω στο ΑΚΡΙΒΕΣ σώμα.
 *
 * Ό,τι αποτυγχάνει μετρά στο όριο αποτυχιών ανά IP.
 *
 * Idempotent με το `ref` του Gateway. 422 = ο υποψήφιος δεν στέκει και το
 * Gateway δεν τον ξαναστέλνει· κάθε άλλο σφάλμα, ξαναδοκιμάζει.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\GatewayIntake\Http;

use ECRM_Messaging;
use ECRM_RateLimit;
use EnergyCRM\GatewayIntake\Domain\GatewayAccess;
use EnergyCRM\GatewayIntake\Domain\GatewayLead;
use EnergyCRM\GatewayIntake\Domain\InvalidGatewayLead;
use EnergyCRM\GatewayIntake\Infrastructure\GatewayIntakeConfig;
use EnergyCRM\GatewayIntake\Persistence\GatewayLeadRepository;
use EnergyCRM\Http\Controller;
use EnergyCRM\Http\Router;
use EnergyCRM\Infrastructure\RequestIp;
use EnergyCRM\Persistence\NotificationRepository;
use EnergyCRM\SiteIntake\Domain\SiteSignature;
use WP_REST_Request;
use WP_REST_Response;

final class GatewayIntakeController implements Controller
{
    /** Ο τύπος της ειδοποίησης. Ανοίγει τους Υποψηφίους (NotificationsController). */
    public const NOTICE = 'ai_call_lead';

    public function __construct(
        private readonly GatewayIntakeConfig $config,
        private readonly GatewayLeadRepository $leads,
        private readonly NotificationRepository $notifications,
    ) {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/gateway-intake', [
            'methods'             => 'POST',
            'callback'            => [$this, 'create'],
            'permission_callback' => [$this, 'signed'],
        ]);
    }

    public function signed(WP_REST_Request $request): bool
    {
        $ok = $this->config->closedBecause() === ''
            && GatewayAccess::ipAllowed(RequestIp::current(), $this->config->allowedIps())
            && SiteSignature::verify(
                $this->config->secret(),
                (string) $request->get_header('x_ecrm_timestamp'),
                (string) $request->get_header('x_ecrm_signature'),
                (string) $request->get_body(),
                time()
            );

        if (! $ok) {
            ECRM_RateLimit::allow('gateway_intake_denied', 30, 600);
        }

        return $ok;
    }

    public function create(WP_REST_Request $request): WP_REST_Response
    {
        if (! ECRM_RateLimit::allow('gateway_intake', 600, 3600, 'gateway')) {
            return new WP_REST_Response(['ok' => false, 'error' => 'too_many'], 429);
        }

        // 503, όχι 422: διορθώνεται από τον admin, και το Gateway πρέπει να ξαναδοκιμάσει.
        $owner = $this->config->ownerProblem();
        if ($owner !== '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'Gateway: ' . $owner], 503);
        }

        $payload = json_decode((string) $request->get_body(), true);

        try {
            $lead = GatewayLead::fromPayload(is_array($payload) ? $payload : []);
        } catch (InvalidGatewayLead $e) {
            return new WP_REST_Response(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        $phone = ECRM_Messaging::normalize_phone($lead->phone);
        if ($phone === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'Το τηλέφωνο δεν είναι έγκυρο.'], 422);
        }

        // UTC εδώ· το repository τη μετατρέπει σε ώρα site (K12).
        $consentAt = $lead->consentGivenAt === null ? null : gmdate('Y-m-d H:i:s', $lead->consentGivenAt);

        $ownerId = $this->config->ownerId();
        $result  = $this->leads->createOnce($lead, $phone, $ownerId, $consentAt);

        if ($result['id'] <= 0) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Σφάλμα αποθήκευσης.'], 500);
        }

        if (! $result['existing']) {
            $this->notifications->add(
                $ownerId,
                self::NOTICE,
                $lead->transferred ? 'Κλήση AI: ο πελάτης μεταφέρθηκε σε πωλητή' : 'Νέος υποψήφιος από κλήση AI',
                $lead->fullName . ' · ' . $lead->interest
            );
        }

        return new WP_REST_Response([
            'ok'       => true,
            'lead_id'  => $result['id'],
            'existing' => $result['existing'],
        ], $result['existing'] ? 200 : 201);
    }
}
