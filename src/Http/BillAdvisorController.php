<?php

/**
 * (328) Πρόταση προγράμματος από λογαριασμό.
 *
 * POST /bill/read     -- ένας λογαριασμός → κατανάλωση, περίοδος, τιμές, και
 *                        τα στοιχεία πελάτη για τη φόρμα αν γίνει αίτηση.
 * GET  /bill/compare  -- ετήσια kWh → όλα τα ενεργά προγράμματα με τιμή,
 *                        φθηνότερο πρώτο.
 *
 * GDPR: ο λογαριασμός ΔΕΝ αποθηκεύεται. Το ανέβασμα μένει στο προσωρινό αρχείο
 * της PHP, που σβήνεται στο τέλος του αιτήματος· τίποτα δεν γράφεται σε πίνακα
 * ή σε φάκελο, και η απάντηση πάει μόνο στον browser του πωλητή. Αν γίνει
 * αίτηση, ο λογαριασμός ανεβαίνει τότε, με την αίτηση, όπως κάθε έγγραφο.
 * Χωρίς τη ρητή συναίνεση (`consent=1`) το αρχείο δεν φεύγει προς το AI.
 *
 * Η σύγκριση βλέπει μόνο τους παρόχους που βλέπει ο χρήστης στη φόρμα: ένα
 * πρόγραμμα που δεν μπορεί να πουλήσει δεν είναι πρόταση.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use ECRM_Extractor;
use ECRM_RateLimit;
use EnergyCRM\Access\NotAuthenticated;
use EnergyCRM\Access\ProviderVisibility;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Domain\Pricing\BillComparison;
use EnergyCRM\Domain\Pricing\BillReading;
use EnergyCRM\Domain\Pricing\ProgramPricing;
use EnergyCRM\Infrastructure\ExtractionGate;
use EnergyCRM\Persistence\ProviderRepository;
use WP_REST_Request;
use WP_REST_Response;

final class BillAdvisorController implements Controller
{
    private const ALLOWED_MIMES = ['application/pdf', 'image/jpeg', 'image/png'];

    public function __construct(
        private readonly ExtractionGate $gate,
        private readonly ScopeResolver $scopes,
        private readonly ProviderVisibility $providerAccess,
        private readonly ProviderRepository $providers,
    ) {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/bill/read', [
            'methods'             => 'POST',
            'callback'            => [$this, 'read'],
            'permission_callback' => Guards::crmUser(),
        ]);

        register_rest_route(Router::NAMESPACE, '/bill/compare', [
            'methods'             => 'GET',
            'callback'            => [$this, 'compare'],
            'permission_callback' => Guards::crmUser(),
            'args'                => [
                'kwh'         => ['type' => 'number', 'required' => true],
                'energy'      => ['type' => 'string', 'enum' => ['power', 'gas'], 'default' => 'power'],
                'category'    => ['type' => 'string', 'enum' => ['home', 'business', ''], 'default' => ''],
                'price_kwh'   => ['type' => 'number', 'default' => 0],
                'fixed_month' => ['type' => 'number', 'default' => 0],
            ],
        ]);
    }

    public function read(WP_REST_Request $request): WP_REST_Response
    {
        if ((string) $request->get_param('consent') !== '1') {
            return new WP_REST_Response(
                ['ok' => false, 'error' => 'Χρειάζεται το τικ ότι ο πελάτης ενημερώθηκε.'],
                400
            );
        }

        // Ίδιο όριο με την εξαγωγή: κάθε κλήση κοστίζει.
        if (! ECRM_RateLimit::allow('extract', 60, 300)) {
            return ECRM_RateLimit::too_many();
        }

        $upload = $request->get_file_params()['file'] ?? null;

        if (! is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Δεν ανέβηκε λογαριασμός.'], 400);
        }

        $mime = self::mimeOf($upload);

        if ($mime === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Μόνο PDF, JPG ή PNG.'], 400);
        }

        if (! $this->gate->enter()) {
            return new WP_REST_Response([
                'ok'          => false,
                'queued'      => true,
                'retry_after' => $this->gate->retryAfter(),
                'error'       => 'Γίνονται ήδη αρκετές αναγνώσεις. Δοκίμασε σε λίγο.',
            ], 503);
        }

        try {
            $result = ECRM_Extractor::read_bill(['path' => (string) $upload['tmp_name'], 'mime' => $mime]);
        } finally {
            $this->gate->leave();
        }

        if (! ($result['ok'] ?? false)) {
            return new WP_REST_Response(['ok' => false, 'error' => (string) ($result['error'] ?? 'Αποτυχία.')], 502);
        }

        $bill = BillReading::from((array) ($result['bill'] ?? []));

        return new WP_REST_Response([
            'ok'         => true,
            'bill'       => $bill,
            'annual_kwh' => $bill['kwh'] !== null && $bill['period_days'] !== null
                ? ProgramPricing::annualise($bill['kwh'], $bill['period_days'])
                : null,
            'customer'   => (array) ($result['customer'] ?? []),
        ], 200);
    }

    public function compare(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $scope = $this->scopes->forCurrentUser();
        } catch (NotAuthenticated) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Απαιτείται σύνδεση.'], 401);
        }

        $access = $this->providerAccess->forScope($scope);
        $kwh    = max(0.0, (float) $request->get_param('kwh'));
        $price  = (float) $request->get_param('price_kwh');
        $fixed  = (float) $request->get_param('fixed_month');

        $names = [];

        foreach ($this->providers->active() as $provider) {
            if ($access->allows((int) $provider['id'])) {
                $names[(int) $provider['id']] = (string) $provider['name'];
            }
        }

        $programs = array_values(array_filter(
            $this->providers->activePrograms(),
            static fn (array $row): bool => isset($names[(int) $row['provider_id']])
        ));

        $current = BillComparison::currentAnnual($kwh, $price > 0 ? $price : null, $fixed);
        $today   = current_time('Y-m-d');

        $result = BillComparison::rank(
            $programs,
            $names,
            $kwh,
            $current,
            (string) $request->get_param('energy'),
            (string) $request->get_param('category'),
            $today
        );

        return new WP_REST_Response([
            'ok'             => true,
            'annual_kwh'     => $kwh,
            'current_annual' => $current,
            'today'          => $today,
            'stale_days'     => ProgramPricing::STALE_DAYS,
            'ranked'         => $result['ranked'],
            'unpriced'       => $result['unpriced'],
        ], 200);
    }

    /**
     * @param array<string, mixed> $upload
     */
    private static function mimeOf(array $upload): ?string
    {
        $declared = (string) ($upload['type'] ?? '');

        if (in_array($declared, self::ALLOWED_MIMES, true)) {
            return $declared;
        }

        $guessed = wp_check_filetype((string) ($upload['name'] ?? ''))['type'] ?? '';

        return in_array($guessed, self::ALLOWED_MIMES, true) ? $guessed : null;
    }
}
