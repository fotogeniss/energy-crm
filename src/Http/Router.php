<?php

/**
 * Registers every controller on rest_api_init.
 *
 * That is the whole job. Which controllers exist, and what each one needs to be
 * built, is ControllerFactory's business — this class only knows that it holds
 * some and that WordPress wants them at a particular moment.
 *
 * Two routes with the same path must never both be registered: WordPress
 * silently keeps whichever came last. That used to be a real risk while
 * ECRM_REST still declared routes of its own; it is now a rule about not
 * declaring the same path in two controllers.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use WP_REST_Request;
use WP_REST_Response;

final class Router
{
    public const NAMESPACE = 'ecrm/v1';

    /** @var list<Controller> */
    private array $controllers;

    /**
     * Variadic rather than an array so the type is checked per controller: a
     * list<Controller> promised in a docblock is a promise, and this is a fact.
     */
    public function __construct(Controller ...$controllers)
    {
        $this->controllers = array_values($controllers);
    }

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            foreach ($this->controllers as $controller) {
                $controller->routes();
            }
        });

        add_filter('rest_post_dispatch', [$this, 'noCacheHeaders'], 10, 3);
    }

    /**
     * Nothing this API returns may sit in a browser or proxy cache.
     *
     * Moved here from ECRM_REST when that file was deleted: it is an HTTP
     * concern about our namespace, and the Router is the one thing that owns
     * the namespace. The behaviour is unchanged, including the two loose
     * instanceof checks — the filter is public, and a third party is free to
     * hand us something that is neither.
     *
     * The prefix test keeps the headers off every other REST API on the site.
     * See ARCHITECTURE, "Χωρητικότητα": this is also why the notification bell
     * cannot be answered with a 304, and where the next ceiling is.
     *
     * @param mixed $response
     * @param mixed $server   Unused; the signature comes from WordPress.
     * @param mixed $request
     * @return mixed
     */
    public function noCacheHeaders($response, $server, $request)
    {
        unset($server);

        if (
            $request instanceof WP_REST_Request
            && $response instanceof WP_REST_Response
            && strpos((string) $request->get_route(), '/' . self::NAMESPACE) === 0
        ) {
            $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            $response->header('Pragma', 'no-cache');
            $response->header('Expires', '0');
        }

        return $response;
    }
}
