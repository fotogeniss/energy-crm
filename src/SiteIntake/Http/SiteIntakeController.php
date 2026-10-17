<?php

/**
 * POST /site-intake               μια αίτηση από το site → υποψήφιος
 * POST /site-intake/{ref}/file    ένα έγγραφο της αίτησης, ένα τη φορά
 *
 * Δεν υπάρχει συνδεδεμένος χρήστης: καλεί ο server του site, όχι browser. Η
 * πόρτα είναι η υπογραφή HMAC (SiteSignature) πάνω στο ΑΚΡΙΒΕΣ σώμα του
 * αιτήματος, με το μυστικό που όρισε ο admin. Χωρίς μυστικό, κλειστή.
 *
 * Ίδιες άμυνες με τον «σύνδεσμό μου» (`ECRM_Intake`) για τα αρχεία: λίστα
 * τύπων, magic bytes, όριο μεγέθους, προστατευμένος φάκελος. Διαφορές, και
 * γιατί:
 *
 * - Όριο ρυθμού ανά ΠΗΓΗ (το site), όχι ανά IP: όλες οι αιτήσεις έρχονται
 *   από μία IP, και το 4 ανά τέταρτο του συνδέσμου θα έκοβε την τρίτη
 *   αίτηση μιας καλής μέρας. Οι λάθος υπογραφές μετρούν χωριστά, ανά IP.
 * - Idempotent με το `ref` της αίτησης (`leads.external_ref`, UNIQUE), όχι με
 *   «ίδιο κινητό τις τελευταίες 12 ώρες»: το site ξαναστέλνει μέχρι να πάρει
 *   απάντηση, και κάθε επανάληψη πρέπει να βρει τον ίδιο υποψήφιο.
 * - Ο υποψήφιος έχει από την αρχή ονοματεπώνυμο και πηγή «Από το site».
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\SiteIntake\Http;

use ECRM_Files;
use ECRM_Messaging;
use ECRM_RateLimit;
use ECRM_Tracking;
use EnergyCRM\Http\Controller;
use EnergyCRM\Http\Router;
use EnergyCRM\Infrastructure\UploadCheck;
use EnergyCRM\Persistence\NotificationRepository;
use EnergyCRM\SiteIntake\Domain\InvalidSiteApplication;
use EnergyCRM\SiteIntake\Domain\SiteApplication;
use EnergyCRM\SiteIntake\Domain\SiteDocumentKind;
use EnergyCRM\SiteIntake\Domain\SiteSignature;
use EnergyCRM\SiteIntake\Infrastructure\SiteIntakeConfig;
use EnergyCRM\SiteIntake\Persistence\SiteLeadRepository;
use WP_REST_Request;
use WP_REST_Response;

final class SiteIntakeController implements Controller
{
    public function __construct(
        private readonly SiteIntakeConfig $config,
        private readonly SiteLeadRepository $leads,
        private readonly NotificationRepository $notifications,
    ) {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/site-intake', [
            'methods'             => 'POST',
            'callback'            => [$this, 'create'],
            'permission_callback' => [$this, 'signed'],
        ]);

        register_rest_route(Router::NAMESPACE, '/site-intake/(?P<ref>[A-Za-z0-9\-]{8,64})/file', [
            'methods'             => 'POST',
            'callback'            => [$this, 'file'],
            'permission_callback' => [$this, 'signed'],
        ]);
    }

    /** Η πόρτα: σωστή υπογραφή πάνω στο ακριβές σώμα, μέσα σε πέντε λεπτά. */
    public function signed(WP_REST_Request $request): bool
    {
        $ok = SiteSignature::verify(
            $this->config->secret(),
            (string) $request->get_header('x_ecrm_timestamp'),
            (string) $request->get_header('x_ecrm_signature'),
            (string) $request->get_body(),
            time()
        );

        if (! $ok) {
            // Μετρά μόνο τις αποτυχίες, ανά IP. Το HMAC δεν μαντεύεται, αλλά ένα
            // script που χτυπά ασταμάτητα δεν χρειάζεται να φτάνει ούτε ως εδώ.
            ECRM_RateLimit::allow('site_intake_denied', 30, 600);
        }

        return $ok;
    }

    public function create(WP_REST_Request $request): WP_REST_Response
    {
        if (! ECRM_RateLimit::allow('site_intake', 300, 3600, 'site')) {
            return new WP_REST_Response(['ok' => false, 'error' => 'too_many'], 429);
        }

        if (! $this->config->ownerIsActive()) {
            return new WP_REST_Response([
                'ok'    => false,
                'error' => 'Δεν έχει οριστεί ενεργός χρήστης για τις αιτήσεις του site (Energy CRM → Site σύγκρισης).',
            ], 503);
        }

        $payload = json_decode((string) $request->get_body(), true);

        try {
            $app = SiteApplication::fromPayload(is_array($payload) ? $payload : []);
        } catch (InvalidSiteApplication $e) {
            return new WP_REST_Response(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        // Ο ένας κανόνας του CRM για τα τηλέφωνα. Κενό = δεν στέκει.
        $phone = ECRM_Messaging::normalize_phone($app->phone);
        if ($phone === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'Το κινητό δεν είναι έγκυρο.'], 422);
        }

        $owner  = $this->config->ownerId();
        $result = $this->leads->createOnce($app, $phone, $owner);

        if ($result['id'] <= 0) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Σφάλμα αποθήκευσης.'], 500);
        }

        if (! $result['existing']) {
            $this->notifications->add(
                $owner,
                'site_lead',
                'Νέα αίτηση από το site',
                $app->fullName . ' · ' . $app->interest
            );
        }

        return new WP_REST_Response([
            'ok'       => true,
            'lead_id'  => $result['id'],
            'existing' => $result['existing'],
        ], $result['existing'] ? 200 : 201);
    }

    public function file(WP_REST_Request $request): WP_REST_Response
    {
        if (! ECRM_RateLimit::allow('site_intake_file', 600, 3600, 'site')) {
            return new WP_REST_Response(['ok' => false, 'error' => 'too_many'], 429);
        }

        $lead = $this->leads->findByRef((string) $request['ref']);
        if ($lead === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Η αίτηση δεν υπάρχει στο CRM.'], 404);
        }

        $p     = json_decode((string) $request->get_body(), true);
        $p     = is_array($p) ? $p : [];
        $docId = (int) ($p['doc_id'] ?? 0);

        if ($docId <= 0) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Λείπει το doc_id.'], 422);
        }

        $siteKind = sanitize_key((string) ($p['kind'] ?? ''));
        $kind     = SiteDocumentKind::toCrm($siteKind);

        // Σταθερό όνομα ανά έγγραφο του site: η επανάληψη το βρίσκει και δεν το ξαναγράφει.
        $base = 'site-' . $docId . '-' . ($siteKind !== '' ? $siteKind : 'doc');

        $data = (string) ($p['data'] ?? '');
        if (preg_match('#^data:([a-zA-Z0-9./+\-]+);base64,#', $data, $m) !== 1) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Μη έγκυρο αρχείο.'], 422);
        }

        $bytes = base64_decode(substr($data, strlen($m[0])), true);
        if ($bytes === false) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Το αρχείο είναι κατεστραμμένο.'], 422);
        }

        $problem = UploadCheck::sizeProblem(strlen($bytes));
        if ($problem !== '') {
            return new WP_REST_Response(['ok' => false, 'error' => $problem], 422);
        }

        // Ο τύπος από τα ίδια τα bytes, ποτέ από τη δήλωση του αιτήματος.
        $mime = UploadCheck::sniff(substr($bytes, 0, UploadCheck::HEAD_BYTES));
        if ($mime === '' || ! isset(ECRM_Tracking::upload_types()[$mime])) {
            return new WP_REST_Response(
                ['ok' => false, 'error' => 'Επιτρέπονται μόνο JPG, PNG, WEBP, HEIC ή PDF.'],
                422
            );
        }

        $ext      = UploadCheck::extensionFor($mime);
        $filename = $base . '.' . $ext;

        if ($this->leads->hasFile($lead['id'], $filename)) {
            return new WP_REST_Response(['ok' => true, 'existing' => true], 200);
        }

        if ($this->leads->fileCount($lead['id']) >= SiteLeadRepository::MAX_FILES) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Πάρα πολλά έγγραφα για μία αίτηση.'], 422);
        }

        $stored = ECRM_Files::put_bytes($bytes, $ext, $mime, $filename);
        if ($stored === null || ! $this->leads->addFile($lead['id'], $lead['contract_id'], $kind, $stored)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Σφάλμα αποθήκευσης.'], 500);
        }

        return new WP_REST_Response(['ok' => true, 'existing' => false], 201);
    }
}
