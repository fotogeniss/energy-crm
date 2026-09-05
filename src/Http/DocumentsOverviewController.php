<?php

/**
 * GET  /documents-overview          η οθόνη «Έγγραφα» (243): τι έχει ανέβει,
 *                                    τι έχει διαβαστεί, τι λείπει -- σε ΟΛΕΣ
 *                                    τις αιτήσεις με έγγραφα, όχι μία-μία.
 * POST /documents-overview/review   «Ελεγξε τώρα»: διάβασε σε παρτίδα ό,τι
 *                                    εκκρεμεί στη λίστα.
 *
 * Δεν υπάρχει νέα πηγή αλήθειας εδώ. Η λίστα διαβάζει τις ίδιες συμβάσεις
 * (`ContractQueries::withDocuments()`) και τα ίδια αρχεία (`FileRepository`)
 * που ήδη υπάρχουν, και το ίδιο `ECRM_Docs::checklist()` που ήδη κρίνει αν
 * μια αίτηση έχει ό,τι χρειάζεται -- μόνο συγκεντρωμένα σε μία οθόνη αντί να
 * ανοίγεις μία αίτηση τη φορά. Το «Ελεγξε τώρα» καλεί τον ίδιο
 * `DocumentKindReview::run()` που ήδη τρέχει μόνος του σε κάθε καρτέλα (242)
 * και στο background sweep (243) -- σε παρτίδα, όχι δεύτερη υλοποίηση.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use ECRM_DB;
use ECRM_Docs;
use ECRM_RateLimit;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Infrastructure\DocumentKindReview;
use EnergyCRM\Persistence\ContractQueries;
use EnergyCRM\Persistence\FileRepository;
use WP_REST_Request;
use WP_REST_Response;

final class DocumentsOverviewController implements Controller
{
    /**
     * Πόσες αιτήσεις διαβάζει ΤΟ ΠΟΛΥ ένα «Ελεγξε τώρα» -- αυτό είναι ένα
     * request browser, όχι cron: πρέπει να προλάβει να απαντήσει πριν λήξει
     * ο χρόνος του. Ό,τι μείνει πάνω από αυτό το ταβάνι δεν χάνεται -- το
     * παίρνει το background sweep στο επόμενο πέρασμά του, ή ένα δεύτερο
     * πάτημα του ίδιου κουμπιού.
     */
    private const REVIEW_CAP = 15;

    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly ContractQueries $queries,
        private readonly FileRepository $files,
        private readonly DocumentKindReview $review,
    ) {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/documents-overview', [
            'methods'             => 'GET',
            'callback'            => [$this, 'index'],
            'permission_callback' => Guards::crmUser(),
            'args'                => [
                'scope' => ['type' => 'string', 'default' => 'own', 'enum' => ['own', 'team']],
            ],
        ]);

        register_rest_route(Router::NAMESPACE, '/documents-overview/review', [
            'methods'             => 'POST',
            'callback'            => [$this, 'reviewAll'],
            'permission_callback' => Guards::crmUser(),
            'args'                => [
                'scope' => ['type' => 'string', 'default' => 'own', 'enum' => ['own', 'team']],
            ],
        ]);
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $rows  = $this->queries->withDocuments($this->scopeFor($request));
        $ids   = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $kinds = $this->files->kindsForContracts($ids);
        $mimes = $this->review->readableMimes();
        $labels = ECRM_DB::statuses();

        $missing = 0;
        $pending = 0;
        $out     = [];

        foreach ($rows as $row) {
            $id    = (int) $row['id'];
            $files = $kinds[$id] ?? [];

            $checklist = ECRM_Docs::checklist($id, $row['activation_type'] ?? null, $row['energy_type'] ?? null);
            $name      = $row['company_name']
                ?: trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));

            $hasPending = false;
            $docs       = [];

            foreach ($files as $f) {
                $isPending = $f['kind_source'] === null && in_array($f['mime'], $mimes, true);
                $hasPending = $hasPending || $isPending;

                $docs[] = [
                    'kind'    => $f['doc_kind'],
                    'label'   => ECRM_Docs::label($f['doc_kind']),
                    'source'  => $f['kind_source'],
                    'pending' => $isPending,
                ];
            }

            if (! $checklist['complete']) {
                ++$missing;
            }

            if ($hasPending) {
                ++$pending;
            }

            $out[] = [
                'id'           => $id,
                'code'         => $row['code'],
                'customer'     => $name ?: '—',
                'status'       => $row['status'],
                'status_label' => $labels[$row['status']] ?? $row['status'],
                'missing'      => array_map([ECRM_Docs::class, 'label'], $checklist['missing']),
                'complete'     => $checklist['complete'],
                'docs'         => $docs,
                'pending'      => $hasPending,
            ];
        }

        return new WP_REST_Response([
            'ok'      => true,
            'count'   => count($out),
            'missing' => $missing,
            'pending' => $pending,
            'rows'    => $out,
        ], 200);
    }

    /**
     * «Ελεγξε τώρα»: ίδιο όριο ρυθμού με το per-contract review() του
     * `DocumentKindController` -- ένα κολλημένο κλικ δεν πρέπει να ανοίγει
     * δεκάδες αναγνώσεις.
     */
    public function reviewAll(WP_REST_Request $request): WP_REST_Response
    {
        if (! ECRM_RateLimit::allow('documents_review_all', 5, 300)) {
            return ECRM_RateLimit::too_many();
        }

        $rows  = $this->queries->withDocuments($this->scopeFor($request));
        $ids   = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $kinds = $this->files->kindsForContracts($ids);
        $mimes = $this->review->readableMimes();

        // Μόνο όσες αιτήσεις έχουν πραγματικά κάτι αδιάβαστο -- όχι τις πρώτες
        // REVIEW_CAP της λίστας, αλλιώς μια σελίδα γεμάτη ήδη-διαβασμένες
        // αιτήσεις θα έτρωγε το ταβάνι χωρίς να διορθώσει τίποτα.
        $pendingIds = array_values(array_filter($ids, static function (int $id) use ($kinds, $mimes): bool {
            foreach ($kinds[$id] ?? [] as $f) {
                if ($f['kind_source'] === null && in_array($f['mime'], $mimes, true)) {
                    return true;
                }
            }

            return false;
        }));

        $checked = 0;
        $fixed   = 0;
        $done    = 0;

        foreach (array_slice($pendingIds, 0, self::REVIEW_CAP) as $id) {
            $result   = $this->review->run($id);
            $checked += $result['checked'];
            $fixed   += count($result['fixed']);
            ++$done;
        }

        return new WP_REST_Response([
            'ok'                 => true,
            'contracts_checked'  => $done,
            'checked'            => $checked,
            'fixed'              => $fixed,
            'more'               => count($pendingIds) > self::REVIEW_CAP,
        ], 200);
    }

    private function scopeFor(WP_REST_Request $request): UserScope
    {
        $scope = $this->scopes->forCurrentUser();

        return $request['scope'] === 'team' ? $scope : $scope->toSelfOnly();
    }
}
