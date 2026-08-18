<?php

/**
 * POST /contracts/{id}/files  attach scanned documents
 * GET  /file/{id}             stream one back, behind a signed token
 *
 * These carry identity documents, so two rules apply throughout: the contract
 * is resolved through a scoped repository before anything is written, and the
 * bytes never become reachable by URL — ECRM_Files::serve checks a signed token
 * and the requester's scope before it reads from disk.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use ECRM_Files;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Infrastructure\ErrorLog;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\FileRepository;
use RuntimeException;
use WP_REST_Request;
use WP_REST_Response;

final class DocumentsController implements Controller
{
    /** What a scanned ID or bill may be. Anything else is not stored. */
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    /**
     * Πόσα αρχεία δέχεται μία αίτηση.
     *
     * Μια σύμβαση θέλει ταυτότητα μπρος-πίσω, λογαριασμό και το έντυπο — δέκα
     * είναι ήδη γενναιόδωρο. Χωρίς όριο, ένα request με χίλια αρχεία γράφει
     * χίλιες φορές στον δίσκο και στη βάση πριν προλάβει να πει όχι.
     */
    private const MAX_FILES = 10;

    /**
     * Γιατί δεν μπήκε ένα αρχείο, στη γλώσσα του χρήστη.
     *
     * Όλα εδώ είναι πράγματα που τα φτιάχνει ο ίδιος: άλλο αρχείο, μικρότερο,
     * ξανά. Το τεχνικό (store_failed) δεν είναι εδώ — παίρνει κωδικό αναφοράς.
     *
     * @var array<string, string>
     */
    private const REASONS = [
        'bad_type'      => 'Δεκτά μόνο JPG, PNG, WEBP ή PDF.',
        'too_large'     => 'Ξεπερνά τα 12MB — δοκίμασε μικρότερη ανάλυση.',
        'empty'         => 'Το αρχείο είναι άδειο ή κατεστραμμένο.',
        'upload_failed' => 'Το ανέβασμα δεν ολοκληρώθηκε — δοκίμασε ξανά.',
        'too_many'      => 'Δέχεται μέχρι %d αρχεία τη φορά.',
    ];

    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly ContractRepository $contracts,
        private readonly FileRepository $files,
    ) {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/contracts/(?P<id>\d+)/files', [
            'methods'             => 'POST',
            'callback'            => [$this, 'upload'],
            'permission_callback' => Guards::crmUser(),
            'args'                => ['id' => ['type' => 'integer', 'required' => true]],
        ]);

        register_rest_route(Router::NAMESPACE, '/file/(?P<id>\d+)', [
            'methods'  => 'GET',
            'callback' => [ECRM_Files::class, 'serve'],
            // Deliberately open: serve() verifies a short-lived signed token and
            // that the user it was issued to may still see the contract. A login
            // check here would add nothing and break the emailed links.
            'permission_callback' => '__return_true',
            'args'                => ['id' => ['type' => 'integer', 'required' => true]],
        ]);
    }

    public function upload(WP_REST_Request $request): WP_REST_Response
    {
        $scope      = $this->scopes->forCurrentUser();
        $contractId = (int) $request['id'];

        if (! $this->contracts->exists($contractId, $scope)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Δεν βρέθηκε η σύμβαση.'], 404);
        }

        $uploads = $request->get_file_params()['files'] ?? null;

        if (empty($uploads)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Δεν ανέβηκαν αρχεία.'], 400);
        }

        $kinds    = (array) $request->get_param('kinds');
        $saved    = [];
        $rejected = [];
        $why      = null;

        foreach (self::normalise($uploads) as $index => $upload) {
            $name = sanitize_file_name((string) ($upload['name'] ?? ''));

            if (count($saved) + count($rejected) >= self::MAX_FILES) {
                $rejected[] = ['filename' => $name, 'reason' => $this->explain('too_many', $name)];
                continue;
            }

            $stored = ECRM_Files::store($upload, self::ALLOWED_MIMES, $why);

            // Μέχρι τις 2026-08-18 τα απορριφθέντα έφευγαν σιωπηλά: ο συνεργάτης
            // ανέβαζε ταυτότητα, δεν έβλεπε μήνυμα, και η σύμβαση έμενε χωρίς
            // δικαιολογητικό. Τώρα κάθε ένα λέει γιατί.
            if ($stored === null) {
                $rejected[] = ['filename' => $name, 'reason' => $this->explain((string) $why, $name)];
                continue;
            }

            $kind   = sanitize_text_field((string) ($kinds[$index] ?? 'other'));
            $fileId = $this->files->attach(
                $contractId,
                $kind,
                $stored['filename'],
                $stored['mime'],
                $stored['path']
            );

            if ($fileId <= 0) {
                $rejected[] = ['filename' => $name, 'reason' => $this->explain('store_failed', $name)];
                continue;
            }

            $saved[] = [
                'id'       => $fileId,
                'filename' => $stored['filename'],
                'url'      => ECRM_Files::url($fileId),
                'kind'     => $kind,
            ];
        }

        return new WP_REST_Response(
            ['ok' => true, 'saved' => count($saved), 'files' => $saved, 'rejected' => $rejected],
            200
        );
    }

    /**
     * Ο λόγος απόρριψης σε μια πρόταση που καταλαβαίνει ο συνεργάτης.
     *
     * Ό,τι δεν είναι στο REASONS είναι δικό μας πρόβλημα, όχι δικό του: μπαίνει
     * στο ημερολόγιο σφαλμάτων και εκείνος παίρνει τον κωδικό για να τον πει.
     *
     * Στο ημερολόγιο μπαίνει μόνο η κατάληξη, όχι το όνομα του αρχείου: οι
     * συνεργάτες τα ονομάζουν «ΠΑΠΑΔΟΠΟΥΛΟΣ_ΤΑΥΤΟΤΗΤΑ.jpg» και το ημερολόγιο
     * δεν είναι μέρος για ονόματα πελατών.
     */
    private function explain(string $reason, string $filename): string
    {
        if (isset(self::REASONS[$reason])) {
            // Τα μηνύματα χωρίς placeholder περνούν από τη sprintf αμετάβλητα.
            return sprintf(self::REASONS[$reason], self::MAX_FILES);
        }

        $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));

        $code = (new ErrorLog())->recordThrowable(
            new RuntimeException(sprintf(
                'Αποτυχία αποθήκευσης εγγράφου (%s), κατάληξη: %s',
                $reason !== '' ? $reason : 'unknown',
                $ext !== '' ? $ext : 'χωρίς'
            ))
        );

        return sprintf('Δεν αποθηκεύτηκε — τεχνικό πρόβλημα, κωδικός %s.', $code);
    }

    /**
     * PHP hands multiple uploads back as parallel arrays rather than a list of
     * files; this turns them into one entry per file.
     *
     * @param array<string, mixed> $uploads
     *
     * @return list<array<string, mixed>>
     */
    private static function normalise(array $uploads): array
    {
        if (! is_array($uploads['name'] ?? null)) {
            return [$uploads];
        }

        $out = [];

        foreach (array_keys($uploads['name']) as $index) {
            $out[] = [
                'name'     => $uploads['name'][$index] ?? '',
                'type'     => $uploads['type'][$index] ?? '',
                'tmp_name' => $uploads['tmp_name'][$index] ?? '',
                'error'    => $uploads['error'][$index] ?? UPLOAD_ERR_OK,
                'size'     => $uploads['size'][$index] ?? 0,
            ];
        }

        return $out;
    }
}
