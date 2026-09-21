<?php

/**
 * GET /providers  the provider and tariff catalogue the form is built from
 * GET /search     the top bar's quick search across contracts
 *
 * Reference data and lookups: nothing here writes. Ο κατάλογος ΗΤΑΝ ίδιος για
 * όλους ως το (278)· από εκεί και πέρα ο καθένας παίρνει μόνο τους παρόχους που
 * του έχουν δοθεί (`Access\ProviderVisibility`) -- και τα προγράμματά τους, και
 * το «συνήθως βάζεις» μόνο αν δείχνει σε πάροχο που βλέπει.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use ECRM_DB;
use EnergyCRM\Access\ProviderVisibility;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Domain\Forms\MobilePlans;
use EnergyCRM\Persistence\ContractQueries;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\ProviderRepository;
use EnergyCRM\Providers\Domain\ProviderAccess;
use EnergyCRM\Providers\Persistence\UsualChoiceRepository;
use WP_REST_Request;
use WP_REST_Response;

final class CatalogueController implements Controller
{
    /** Shorter than this and every contract matches; the UI waits too. */
    private const MIN_SEARCH_LENGTH = 2;

    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly ProviderRepository $providers,
        private readonly ContractQueries $queries,
        private readonly UsualChoiceRepository $usual,
        private readonly ProviderVisibility $visibility,
        private readonly ContractRepository $contracts,
    ) {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/providers', [
            'methods'             => 'GET',
            'callback'            => [$this, 'catalogue'],
            'permission_callback' => Guards::crmUser(),
            'args'                => [
                // Επεξεργασία υπάρχουσας αίτησης: ο πάροχός της μένει στη
                // λίστα ακόμα κι αν ο χρήστης τον έχασε στο μεταξύ -- δες
                // keptProvider().
                'contract' => ['type' => 'integer', 'default' => 0],
            ],
        ]);

        register_rest_route(Router::NAMESPACE, '/search', [
            'methods'             => 'GET',
            'callback'            => [$this, 'search'],
            'permission_callback' => Guards::crmUser(),
            'args'                => [
                'q' => [
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    public function catalogue(WP_REST_Request $request): WP_REST_Response
    {
        $scope  = $this->scopes->forCurrentUser();
        $access = $this->visibility->forScope($scope);
        $kept   = $this->keptProvider($scope, (int) $request['contract'], $access);

        $shows = static fn (int $providerId): bool => $access->allows($providerId) || $providerId === $kept;

        // Ενα «συνήθως βάζεις Χ» για πάροχο που δεν βλέπει πια θα ήταν κουμπί
        // προς κάτι που η αποθήκευση θα αρνιόταν.
        $usual = $this->usual->forPartner($scope->actorId())->toArray();

        if ($usual !== null && ! $access->allows($usual['provider_id'])) {
            $usual = null;
        }

        return new WP_REST_Response([
            'providers'        => array_values(array_filter(
                $this->providers->active(),
                static fn (array $row): bool => $shows((int) $row['id'])
            )),
            'programs'         => array_values(array_filter(
                $this->providers->activePrograms(),
                static fn (array $row): bool => $shows((int) $row['provider_id'])
            )),
            // Ο πάροχος που μπήκε μόνο επειδή τον έχει ήδη η αίτηση -- η φόρμα
            // μπορεί να τον σημαδέψει. null όταν δεν υπάρχει τέτοιος.
            'kept_provider'    => $kept > 0 ? $kept : null,
            'statuses'         => ECRM_DB::statuses(),
            'activation_types' => ECRM_DB::activation_types(),
            // The published Orizon price list, so the screen can show what a
            // mobile plan actually prints instead of leaving an editable box
            // next to a figure the paper form fixes in advance. No PII, no DB
            // read — the same static table the renderer already uses.
            'mobile_pricing'   => MobilePlans::pricingTable(),
            // Τι βάζει συνήθως ΑΥΤΟΣ ο πωλητής -- ταξιδεύει εδώ και όχι σε δική
            // του διαδρομή επειδή η φόρμα ζητά ούτως ή άλλως τον κατάλογο στο
            // άνοιγμα: δεύτερη κλήση θα ήταν δεύτερη αναμονή σε κινητό, για ένα
            // ερώτημα που κοιτάζει είκοσι γραμμές. `null` όταν δεν υπάρχει
            // αρκετά καθαρή συνήθεια -- τότε η οθόνη δεν δείχνει τίποτα.
            'usual'            => $usual,
        ], 200);
    }

    /**
     * Ο πάροχος μιας υπάρχουσας αίτησης που ο χρήστης ΔΕΝ βλέπει πια.
     *
     * Απόφαση ιδιοκτήτη 21/09: ο περιορισμός αφορά μόνο ΝΕΕΣ αιτήσεις. Μια
     * παλιά αίτηση σε πάροχο που του αφαιρέθηκε μένει δική του και
     * επεξεργάσιμη. Χωρίς αυτό, η φόρμα θα άνοιγε την αίτηση με άδειο πάροχο
     * -- κανένα κουμπί να σημαδευτεί -- και θα έχανε και τα προγράμματά του.
     * Η αποθήκευση το δέχεται ήδη όσο ο πάροχος δεν αλλάζει
     * (`ContractSaveController::refuseProvider()`).
     *
     * Μόνο αίτηση που βρίσκεται μέσα στο scope του: αλλιώς το `?contract=` θα
     * ήταν τρόπος να δει κανείς τον κατάλογο ενός παρόχου βάζοντας τυχαίο id.
     */
    private function keptProvider(UserScope $scope, int $contractId, ProviderAccess $access): int
    {
        if ($contractId <= 0) {
            return 0;
        }

        $row        = $this->contracts->find($contractId, $scope);
        $providerId = (int) ($row['provider_id'] ?? 0);

        return $providerId > 0 && ! $access->allows($providerId) ? $providerId : 0;
    }

    public function search(WP_REST_Request $request): WP_REST_Response
    {
        $term = trim((string) $request['q']);

        if (mb_strlen($term) < self::MIN_SEARCH_LENGTH) {
            return new WP_REST_Response(['ok' => true, 'results' => []], 200);
        }

        $statuses = ECRM_DB::statuses();

        $results = array_map(
            static function (array $row) use ($statuses): array {
                $customer = $row['company_name']
                    ?: trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));

                return [
                    'id'           => (int) $row['id'],
                    'code'         => $row['code'],
                    'customer'     => $customer !== '' ? $customer : '—',
                    'afm'          => $row['afm'],
                    'provider'     => $row['provider_name'],
                    'status'       => $row['status'],
                    'status_label' => $statuses[$row['status']] ?? $row['status'],
                ];
            },
            $this->queries->quickSearch($this->scopes->forCurrentUser(), $term)
        );

        return new WP_REST_Response(['ok' => true, 'results' => $results], 200);
    }
}
