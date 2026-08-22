<?php

/**
 * POST /contracts/{id}/sign-link — send the customer away to sign.
 *
 * The link itself is the ordinary tracking URL: the customer follows their
 * application and signs from the same page, so there is no second token to
 * expire or leak. What this endpoint really does is move the contract to
 * "awaiting the customer's signature" and deliver the link.
 *
 * ## Το κανάλι, από 21/08 (B4)
 *
 * Μέχρι τότε ήταν ΜΟΝΟ email, με σκληρό `{ email: true }` από την οθόνη. Την
 * ίδια ώρα το `ECRM_Messaging` έστελνε Viber-με-πτώση-σε-SMS και είχε ήδη
 * γραμμένο πρότυπο για το `pending_signature` με τον σύνδεσμο μέσα — πρότυπο
 * που δεν είχε σταλεί ποτέ σε κανέναν, γιατί κανείς δεν το ζήτησε από εδώ.
 *
 * ## Και γιατί καταγράφεται
 *
 * Η κατάσταση προχωρούσε ακόμη κι όταν το email αποτύγχανε σιωπηλά: το
 * `emailed:false` έφτανε στον browser και πετιόταν. Το ιστορικό έλεγε
 * «Αποστολή για υπογραφή» χωρίς να πει πού, ούτε αν. Τώρα κάθε αποστολή
 * αφήνει γεγονός `sign_sent_*` ή `sign_failed_*` — και ο διάλογος της
 * επόμενης φοράς το διαβάζει, ώστε να μη στέλνεις δεύτερη φορά στα τυφλά.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use ECRM_Admin;
use ECRM_Tracking;
use EnergyCRM\Access\NotAuthenticated;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Domain\Contract\ContractLifecycle;
use EnergyCRM\Infrastructure\DocumentQueue;
use ECRM_Messaging;
use EnergyCRM\Persistence\ContractDetails;
use EnergyCRM\Persistence\EventRepository;
use WP_REST_Request;
use WP_REST_Response;

final class SignLinkController implements Controller
{
    private const TARGET_STATUS = 'pending_signature';

    public const CHANNEL_LINK = 'link';

    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_SMS = 'sms';

    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly ContractDetails $details,
        private readonly DocumentQueue $documents,
        private readonly ContractLifecycle $lifecycle,
        private readonly EventRepository $events,
    ) {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/contracts/(?P<id>\d+)/sign-link', [
            'methods'             => 'POST',
            'callback'            => [$this, 'create'],
            'permission_callback' => Guards::crmUser(),
            'args'                => [
                'id'      => ['type' => 'integer', 'required' => true],
                'channel' => [
                    'type'    => 'string',
                    'enum'    => [self::CHANNEL_LINK, self::CHANNEL_EMAIL, self::CHANNEL_SMS],
                    'default' => self::CHANNEL_LINK,
                ],
                // Παλιά μορφή, από πριν υπάρξει επιλογή. Κρατιέται ώστε ένας
                // καλών που δεν ξέρει για κανάλια να μη σπάσει σιωπηλά — δες
                // resolveChannel(). Ο σύνδεσμος φτιάχνεται έτσι κι αλλιώς.
                'email'   => ['type' => 'boolean', 'default' => false],
            ],
        ]);
    }

    public function create(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $scope = $this->scopes->forCurrentUser();
        } catch (NotAuthenticated) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Απαιτείται σύνδεση.'], 401);
        }

        $id       = (int) $request['id'];
        $contract = $this->details->findDetailed($id, $scope);

        if ($contract === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Δεν βρέθηκε η σύμβαση.'], 404);
        }

        $moved = $this->lifecycle->moveTo($id, self::TARGET_STATUS, [
            'user_id' => $scope->actorId(),
            'message' => 'Αποστολή για υπογραφή — αναμονή υπογραφής πελάτη',
        ]);

        // The old handler ignored this and handed back a working signing link
        // for a contract the pipeline had refused to move — a cancelled one,
        // most obviously. A customer could then sign something already dead.
        if (! $moved) {
            return new WP_REST_Response([
                'ok'    => false,
                'error' => 'Η σύμβαση δεν μπορεί να σταλεί για υπογραφή από την τρέχουσα κατάστασή της.',
            ], 409);
        }

        // The one moment the stored document has to exist: the customer is
        // about to be sent to a page that shows it. Saving only schedules the
        // render, so this closes the window — and does nothing when the cron
        // already got there first.
        $this->documents->ensure($id);

        $url     = ECRM_Tracking::url($id);
        $channel = $this->resolveChannel($request);

        $delivery = $this->deliver($channel, $id, $contract, $url);

        // Καταγράφεται ΠΑΝΤΑ, και για τα τρία κανάλια — ακόμη και για το
        // «μόνο ο σύνδεσμος», που είναι κι αυτό απόφαση: κάποιος είπε «θα τον
        // στείλω εγώ». Χωρίς αυτό, το ιστορικό δεν ξεχωρίζει τον συνεργάτη που
        // έστειλε Viber από εκείνον που έκλεισε την καρτέλα και το ξέχασε.
        $this->events->record(
            $id,
            $scope->actorId(),
            ($delivery['ok'] ? 'sign_sent_' : 'sign_failed_') . $channel,
            ['message' => $this->historyLine($channel, $delivery)]
        );

        return new WP_REST_Response([
            'ok'        => true,
            'url'       => $url,
            'channel'   => $channel,
            'delivered' => $delivery['ok'],
            'why'       => $delivery['error'] ?? null,
            // Παλιό όνομα, ίδιο νόημα: το κρατά ο,τιδήποτε δεν έμαθε για κανάλια.
            'emailed'   => $channel === self::CHANNEL_EMAIL && $delivery['ok'],
        ], 200);
    }

    /**
     * Το κανάλι που ζητήθηκε, με τη μία παλιά μορφή μεταφρασμένη.
     *
     * Το `email:true` σήμαινε ακριβώς «στείλ' το με email». Δεν αγνοείται και
     * δεν σβήνει επιλογή: μετράει μόνο όταν δεν δόθηκε ρητό κανάλι.
     */
    private function resolveChannel(WP_REST_Request $request): string
    {
        $channel = (string) ($request['channel'] ?? self::CHANNEL_LINK);

        if ($channel === self::CHANNEL_LINK && (bool) $request['email']) {
            return self::CHANNEL_EMAIL;
        }

        return $channel;
    }

    /**
     * Παραδίδει τον σύνδεσμο από το κανάλι που ζητήθηκε.
     *
     * Το «μόνο σύνδεσμος» ΕΙΝΑΙ επιτυχία, όχι απουσία ενέργειας: ο συνεργάτης
     * τον αντιγράφει και τον στέλνει ο ίδιος. Το να επιστρέφει `false` θα
     * έγραφε «απέτυχε» για κάτι που πήγε ακριβώς όπως ζητήθηκε.
     *
     * @param array<string, mixed> $contract
     *
     * @return array{ok:bool, error?:string, channel?:string, to?:string}
     */
    private function deliver(string $channel, int $id, array $contract, string $url): array
    {
        if ($channel === self::CHANNEL_SMS) {
            // Το πρότυπο και ο σύνδεσμος υπάρχουν ήδη εκεί μέσα· εδώ δίνεται
            // μόνο η εντολή. Δες ECRM_Messaging::send_for_status().
            return class_exists(ECRM_Messaging::class)
                ? ECRM_Messaging::send_for_status($id, self::TARGET_STATUS)
                : ['ok' => false, 'error' => 'messaging_unavailable'];
        }

        if ($channel === self::CHANNEL_EMAIL) {
            $to = (string) ($contract['email'] ?? '');

            if (! is_email($to)) {
                return ['ok' => false, 'error' => 'no_email'];
            }

            return $this->email($contract, $url)
                ? ['ok' => true, 'channel' => 'email', 'to' => $to]
                : ['ok' => false, 'error' => 'mail_failed'];
        }

        return ['ok' => true, 'channel' => 'link'];
    }

    /**
     * Η γραμμή που θα διαβάσει άνθρωπος στο ιστορικό, σε έξι μήνες.
     *
     * @param array{ok:bool, error?:string, channel?:string, to?:string} $delivery
     */
    private function historyLine(string $channel, array $delivery): string
    {
        if ($channel === self::CHANNEL_LINK) {
            return 'Σύνδεσμος υπογραφής — αντιγράφηκε για χειροκίνητη αποστολή';
        }

        $where = isset($delivery['to']) && $delivery['to'] !== ''
            ? ' προς ' . $delivery['to']
            : '';

        $name = $channel === self::CHANNEL_SMS
            ? ($delivery['channel'] ?? 'SMS')
            : 'email';

        if ($delivery['ok']) {
            return 'Σύνδεσμος υπογραφής στάλθηκε με ' . $name . $where;
        }

        return 'Ο σύνδεσμος υπογραφής ΔΕΝ στάλθηκε με ' . $name . $where
            . ' — ' . self::reason($delivery['error'] ?? '');
    }

    /**
     * Ο λόγος στα ελληνικά. Ένας κωδικός στο ιστορικό είναι κωδικός που
     * κάποιος θα ψάξει στον κώδικα αντί να διαβάσει την οθόνη.
     */
    private static function reason(string $code): string
    {
        return match ($code) {
            'no_email'              => 'ο πελάτης δεν έχει email',
            'no_mobile'             => 'ο πελάτης δεν έχει κινητό',
            'mail_failed'           => 'ο διακομιστής email δεν το δέχτηκε',
            'messaging_disabled'    => 'δεν έχει ρυθμιστεί πάροχος μηνυμάτων',
            'messaging_unavailable' => 'δεν έχει ρυθμιστεί πάροχος μηνυμάτων',
            'no_template'           => 'λείπει το πρότυπο μηνύματος',
            'no_number'             => 'ο αριθμός δεν είναι έγκυρος για αποστολή',
            default                 => $code !== '' ? $code : 'άγνωστη αιτία',
        };
    }

    /**
     * @param array<string, mixed> $contract Joined with the customer.
     */
    private function email(array $contract, string $url): bool
    {
        $to = (string) ($contract['email'] ?? '');

        if (! is_email($to)) {
            return false;
        }

        $company = (string) ECRM_Admin::get('company_name', get_bloginfo('name'));

        return wp_mail(
            $to,
            'Υπογραφή σύμβασης - ' . $company,
            sprintf(
                "Αγαπητέ/ή %s,\n\n"
                . "Παρακαλούμε υπογράψτε τη σύμβασή σας ηλεκτρονικά στον παρακάτω σύνδεσμο:\n%s\n\n"
                . "Με εκτίμηση,\n%s",
                $this->customerName($contract) ?: 'πελάτη',
                $url,
                $company
            )
        );
    }

    /**
     * @param array<string, mixed> $contract
     */
    private function customerName(array $contract): string
    {
        $company = trim((string) ($contract['company_name'] ?? ''));

        if ($company !== '') {
            return $company;
        }

        return trim(
            (string) ($contract['first_name'] ?? '') . ' ' . (string) ($contract['last_name'] ?? '')
        );
    }
}
