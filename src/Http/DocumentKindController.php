<?php

/**
 * POST /contracts/{id}/files/review          διάβασε τα έγγραφα, διόρθωσε είδη
 * POST /contracts/{id}/files/{file}/unkind   ανέτρεψε μια αυτόματη διόρθωση
 *
 * Δύο άκρα της ίδιας ιστορίας: το ένα αφήνει την ανάγνωση να διορθώσει, το άλλο
 * δίνει στον άνθρωπο τον τελευταίο λόγο. Και τα δύο περνούν πρώτα από τη
 * σύμβαση με την εμβέλεια του χρήστη -- ένα αρχείο δεν είναι ποτέ αρκετό
 * αναγνωριστικό από μόνο του, γιατί τότε ο έλεγχος πρόσβασης θα εξαρτιόταν από
 * το να μαντέψει κάποιος ένα id.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use ECRM_Docs;
use ECRM_RateLimit;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Infrastructure\DocumentKindReview;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\FileRepository;
use WP_REST_Request;
use WP_REST_Response;

final class DocumentKindController implements Controller
{
    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly ContractRepository $contracts,
        private readonly FileRepository $files,
        private readonly DocumentKindReview $review,
    ) {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/contracts/(?P<id>\d+)/files/review', [
            'methods'             => 'POST',
            'callback'            => [$this, 'review'],
            'permission_callback' => Guards::crmUser(),
            'args'                => ['id' => ['type' => 'integer', 'required' => true]],
        ]);

        register_rest_route(Router::NAMESPACE, '/contracts/(?P<id>\d+)/files/(?P<file>\d+)/unkind', [
            'methods'             => 'POST',
            'callback'            => [$this, 'revert'],
            'permission_callback' => Guards::crmUser(),
            'args'                => [
                'id'   => ['type' => 'integer', 'required' => true],
                'file' => ['type' => 'integer', 'required' => true],
            ],
        ]);
    }

    /**
     * Διάβασε ό,τι δεν έχει κριθεί σε αυτή την αίτηση.
     *
     * Καλείται από τον browser αμέσως μετά από ανέβασμα, και μία φορά όταν
     * ανοίγει μια καρτέλα με αδιάβαστα έγγραφα. Οι επόμενες κλήσεις κοστίζουν
     * μηδέν: ό,τι κρίθηκε είναι ήδη σημειωμένο και δεν ξαναδιαβάζεται.
     */
    public function review(WP_REST_Request $request): WP_REST_Response
    {
        // Κάθε ανάγνωση στοιχίζει. Το ίδιο όριο με το /extract, για τον ίδιο
        // λόγο: ένας κολλημένος browser δεν πρέπει να ανεβάζει λογαριασμό.
        if (! ECRM_RateLimit::allow('doc_kind_review', 60, 300)) {
            return ECRM_RateLimit::too_many();
        }

        $contractId = (int) $request['id'];

        if (! $this->contracts->exists($contractId, $this->scopes->forCurrentUser())) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Δεν βρέθηκε η σύμβαση.'], 404);
        }

        $result = $this->review->run($contractId);

        return new WP_REST_Response([
            'ok'      => true,
            'checked' => $result['checked'],
            'busy'    => $result['busy'],
            'fixed'   => array_map(
                static fn (array $f): array => [
                    'id'        => $f['id'],
                    'from'      => $f['from'],
                    'to'        => $f['to'],
                    'from_label' => ECRM_Docs::label($f['from']),
                    'to_label'  => ECRM_Docs::label($f['to']),
                ],
                $result['fixed']
            ),
        ], 200);
    }

    /**
     * Επανάφερε την ετικέτα που είχε διαλέξει ο άνθρωπος.
     *
     * Και κλείδωσέ την: το αρχείο σημειώνεται ως «αποφάσισε άνθρωπος» και δεν
     * ξαναδιορθώνεται ποτέ αυτόματα. Χωρίς αυτό, η επόμενη ανάγνωση θα ξανα-
     * άλλαζε ακριβώς ό,τι μόλις ανέτρεψε ο συνεργάτης, και εκείνος θα έβλεπε
     * την επιλογή του να εξαφανίζεται χωρίς εξήγηση.
     */
    public function revert(WP_REST_Request $request): WP_REST_Response
    {
        $contractId = (int) $request['id'];
        $fileId     = (int) $request['file'];

        if (! $this->contracts->exists($contractId, $this->scopes->forCurrentUser())) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Δεν βρέθηκε η σύμβαση.'], 404);
        }

        $kind = $this->files->revertKind($fileId, $contractId);

        if ($kind === null) {
            return new WP_REST_Response(
                ['ok' => false, 'error' => 'Δεν υπάρχει αυτόματη διόρθωση για αναίρεση.'],
                404
            );
        }

        return new WP_REST_Response(
            ['ok' => true, 'kind' => $kind, 'label' => ECRM_Docs::label($kind)],
            200
        );
    }
}
