<?php

/**
 * POST /providers/{id}/status-map/resolve  τι σημαίνουν αυτές οι τιμές του παρόχου
 * PUT  /providers/{id}/status-map          αποθήκευσε τον χάρτη
 *
 * Δύο διαδρομές, ένας κανόνας από κάτω. Και οι δύο υπάρχουν ώστε **ο browser
 * να ζωγραφίζει και ο server να αποφασίζει**: ως τις 28/08 η αντιστοίχιση και
 * οι ευρετικές ζούσαν σε JavaScript, δηλαδή ήταν απρόσιτες σε οτιδήποτε δεν
 * είναι ανοιχτή καρτέλα browser. Το `HANDOVER.md` §1.13 το ονομάζει ρητά: ένα
 * μελλοντικό integration με το CRM του παρόχου καλεί **αυτές ακριβώς** τις
 * διαδρομές, χωρίς να ξαναγραφτεί ούτε μία ευρετική.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Providers\Http;

use EnergyCRM\Access\Capability;
use EnergyCRM\Http\Controller;
use EnergyCRM\Http\Guards;
use EnergyCRM\Http\Router;
use EnergyCRM\Providers\Domain\ProviderStatusMap;
use EnergyCRM\Providers\Persistence\ProviderStatusMapRepository;
use WP_REST_Request;
use WP_REST_Response;

final class ProviderStatusMapController implements Controller
{
    /**
     * Πόσες τιμές δέχεται μία κλήση.
     *
     * Ίδιος λόγος με το `MAX_ROWS` του εισαγωγέα: αρχείο με χαλασμένη στήλη
     * κατάστασης μπορεί να έχει χιλιάδες διακριτές τιμές, και ένα αίτημα που
     * τις στέλνει όλες κρατά έναν worker για τίποτα.
     */
    private const MAX_VALUES = 500;

    public function __construct(
        private readonly ProviderStatusMapRepository $maps,
    ) {
    }

    public function routes(): void
    {
        $guard = Guards::needs(Capability::IMPORT_DATA);

        register_rest_route(Router::NAMESPACE, '/providers/(?P<id>\d+)/status-map/resolve', [
            'methods'             => 'POST',
            'callback'            => [$this, 'resolve'],
            'permission_callback' => $guard,
            'args'                => [
                'values' => [
                    'type'     => 'array',
                    'required' => true,
                    'maxItems' => self::MAX_VALUES,
                ],
            ],
        ]);

        register_rest_route(Router::NAMESPACE, '/providers/(?P<id>\d+)/status-map', [
            'methods'             => 'PUT',
            'callback'            => [$this, 'save'],
            'permission_callback' => $guard,
        ]);
    }

    /**
     * Τι σημαίνει κάθε τιμή, και από πού το ξέρουμε.
     *
     * Η προέλευση επιστρέφεται μαζί με την απάντηση επίτηδες: η οθόνη πρέπει να
     * ξεχωρίζει «το αποφάσισε άνθρωπος» από «το μάντεψε η μηχανή», αλλιώς
     * δείχνει πέντε συμπληρωμένα κουτιά και κανείς δεν ξέρει ποιο να ελέγξει.
     */
    public function resolve(WP_REST_Request $request): WP_REST_Response
    {
        $providerId = (int) $request['id'];

        /** @var list<string> $values */
        $values = array_values(array_filter(
            array_map(
                static fn ($value): string => is_scalar($value) ? (string) $value : '',
                (array) $request->get_param('values')
            ),
            static fn (string $value): bool => trim($value) !== ''
        ));

        $map      = $this->maps->find($providerId);
        $resolved = $map->resolve(array_slice($values, 0, self::MAX_VALUES));

        return new WP_REST_Response([
            'ok'          => true,
            'provider_id' => $providerId,
            'has_saved'   => ! $map->isEmpty(),
        ] + $resolved, 200);
    }

    public function save(WP_REST_Request $request): WP_REST_Response
    {
        $providerId = (int) $request['id'];
        $map        = ProviderStatusMap::fromArray((array) $request->get_param('map'));

        if (! $this->maps->save($providerId, $map)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Δεν βρέθηκε ο πάροχος.'], 404);
        }

        return new WP_REST_Response(['ok' => true, 'saved' => count($map->toArray())], 200);
    }
}
