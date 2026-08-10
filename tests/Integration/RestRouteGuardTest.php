<?php

/**
 * The HTTP surface, swept the way the browser reaches it.
 *
 * Every other test in this suite calls a repository directly. That leaves one
 * layer untested and it happens to be the outermost one: a repository can
 * enforce scope perfectly while the route above it is registered without a
 * permission_callback, or with one that no longer exists. Neither mistake is
 * visible from below.
 *
 * So this test does not name endpoints. It asks WP_REST_Server what is actually
 * registered and holds every answer to the same rule: guarded, unless it is on
 * the short list below with a reason next to it. A route added tomorrow is
 * covered the day it is written, which is the only way a list like this stays
 * true.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use WP_REST_Request;

final class RestRouteGuardTest extends IntegrationTestCase
{
    /** Our namespace, as WP_REST_Server spells it in its route table. */
    private const ROOT = '/ecrm/v1';

    /**
     * If the sweep ever sees fewer than this, something stopped registering and
     * every assertion below would pass by looking at nothing.
     */
    private const LEAST_PLAUSIBLE_ROUTE_COUNT = 25;

    /**
     * The routes that are open on purpose, each with the reason it may be.
     *
     * Anything not listed here must refuse an anonymous caller. Adding an entry
     * is therefore a deliberate act with a justification attached, which is the
     * point of writing the reason in the value rather than a bare list.
     *
     * @var array<string, string>
     */
    private const PUBLIC_ROUTES = [
        self::ROOT =>
            'The namespace index, registered by WordPress itself. Route discovery, no data of ours.',

        self::ROOT . '/sign/(?P<token>[A-Za-z0-9]+)' =>
            'The customer signs without an account. The token is the whole credential.',

        self::ROOT . '/file/(?P<id>\d+)' =>
            'ECRM_Files::serve checks a signed short-lived token and the scope it was issued to.',

        self::ROOT . '/track/(?P<token>[A-Za-z0-9\-]+)' =>
            'The customer tracking page, same token rule as signing.',

        self::ROOT . '/track/(?P<token>[A-Za-z0-9\-]+)/sign' =>
            'Signing from the tracking page.',

        self::ROOT . '/track/(?P<token>[A-Za-z0-9\-]+)/upload' =>
            'The customer uploading their own documents from the tracking page.',
    ];

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    /**
     * Guards the sweep itself.
     *
     * Every test here iterates the registered routes. An empty iteration passes
     * silently, so the suite would go green precisely when the plugin stopped
     * registering anything at all.
     */
    public function testTheSweepIsActuallyLookingAtSomething(): void
    {
        $routes = $this->ourRoutes();

        self::assertGreaterThan(
            self::LEAST_PLAUSIBLE_ROUTE_COUNT,
            count($routes),
            'Too few routes registered for this to be the real surface — has rest_api_init run?'
        );
    }

    /** A route with no permission_callback is served to anyone who asks. */
    public function testEveryRouteDeclaresAPermissionCallback(): void
    {
        $missing = [];

        foreach ($this->ourHandlers() as $entry) {
            if (isset(self::PUBLIC_ROUTES[$entry['route']])) {
                continue;
            }

            if (empty($entry['handler']['permission_callback'])) {
                $missing[] = $entry['method'] . ' ' . $entry['route'];
            }
        }

        self::assertSame([], $missing, $this->explain('Registered with no permission_callback', $missing));
    }

    /**
     * A permission_callback that cannot be called is not a check.
     *
     * WordPress hands it straight to call_user_func, which on PHP 8 throws a
     * TypeError — so the endpoint answers 500 to everyone and the failure looks
     * like a server problem rather than a missing guard. This is exactly how
     * ECRM_REST::can_use was left dangling when the routes moved to src/Http.
     */
    public function testEveryPermissionCallbackCanActuallyBeCalled(): void
    {
        $broken = [];

        foreach ($this->ourHandlers() as $entry) {
            $callback = $entry['handler']['permission_callback'] ?? null;

            if (empty($callback) || is_callable($callback)) {
                continue;
            }

            $broken[] = $entry['method'] . ' ' . $entry['route'] . ' → ' . $this->describe($callback);
        }

        self::assertSame([], $broken, $this->explain('Permission callback does not exist', $broken));
    }

    /** The list is only honest if every route on it is still registered. */
    public function testThePublicListHasNoEntriesThatNoLongerExist(): void
    {
        $registered = $this->ourRoutes();
        $stale      = array_values(array_diff(array_keys(self::PUBLIC_ROUTES), $registered));

        self::assertSame(
            [],
            $stale,
            $this->explain('Allowed to be public, but no such route is registered', $stale)
        );
    }

    /** Nothing but the listed routes may be reached without logging in. */
    public function testAnonymousCallersAreTurnedAwayFromEverythingElse(): void
    {
        wp_set_current_user(0);

        $reached = $this->refusalsOtherThan(401);

        self::assertSame([], $reached, $this->explain('Reachable without logging in', $reached));
    }

    /**
     * A WordPress account is not a CRM account.
     *
     * The floor every guard shares is `logged in AND holds ecrm_use`. A guard
     * that checked only the first half would pass the test above and fail here,
     * which is the whole reason this second pass exists.
     */
    public function testALoggedInStrangerWithoutACrmRoleIsTurnedAwayToo(): void
    {
        wp_set_current_user($this->makePartner());

        self::assertFalse(
            current_user_can('ecrm_use'),
            'The stranger in this test is supposed to have no CRM capability.'
        );

        $reached = $this->refusalsOtherThan(403);

        self::assertSame([], $reached, $this->explain(
            'Reachable by a logged-in user with no CRM role',
            $reached
        ));
    }

    /**
     * Dispatch every guarded route and report the ones that did not answer with
     * the status a refusal should carry.
     *
     * 401 when nobody is logged in, 403 when somebody is: that is
     * rest_authorization_required_code(), and the caller passes the one it
     * expects for the user it set up.
     *
     * @return list<string>
     */
    private function refusalsOtherThan(int $expected): array
    {
        $reached = [];

        foreach ($this->ourHandlers() as $entry) {
            if (isset(self::PUBLIC_ROUTES[$entry['route']])) {
                continue;
            }

            // A callback that is not callable would fatal here rather than fail;
            // the test above is the one that reports it.
            if (! is_callable($entry['handler']['permission_callback'] ?? null)) {
                continue;
            }

            $status = rest_do_request($this->minimalRequest($entry))->get_status();

            if ($status !== $expected) {
                $reached[] = $entry['method'] . ' ' . $entry['route'] . ' → ' . $status;
            }
        }

        return $reached;
    }

    /**
     * A request the schema will accept, so the guard is what answers it.
     *
     * WordPress validates `args` *before* it calls the permission callback: a
     * missing required parameter is a 400 and the guard never runs. Asserting
     * on that 400 would prove nothing — a route stripped of its guard would
     * still produce it — so every required argument is filled in first, from
     * the route's own schema rather than a hand-written table.
     *
     * A 400 surviving this is therefore a finding in its own right: the schema
     * is rejecting a request that satisfies it, which means it rejects every
     * caller. That is how /contracts/export was found answering 400 to
     * everyone because its own defaults failed its own pattern.
     *
     * @param array{route: string, method: string, handler: array<string, mixed>} $entry
     */
    private function minimalRequest(array $entry): WP_REST_Request
    {
        $request = new WP_REST_Request($entry['method'], self::concretePath($entry['route']));

        foreach ((array) ($entry['handler']['args'] ?? []) as $name => $schema) {
            if (($schema['required'] ?? false) === true) {
                $request->set_param((string) $name, self::sampleFor((array) $schema));
            }
        }

        return $request;
    }

    /**
     * The least interesting value a schema will accept.
     *
     * Only has to survive validation — the guard refuses long before anything
     * looks at what it means.
     *
     * @param array<string, mixed> $schema
     */
    private static function sampleFor(array $schema): mixed
    {
        if (isset($schema['enum'][0])) {
            return $schema['enum'][0];
        }

        return match ($schema['type'] ?? 'string') {
            'integer', 'number' => 1,
            'boolean'           => true,
            // minItems is commonly 1, and an extra element never hurts.
            'array'             => [1],
            'object'            => ['sample' => 1],
            // `format` is checked on top of the type, so 'sample' is not
            // always enough — an e-mail field is the one that bites.
            default             => ($schema['format'] ?? '') === 'email'
                ? 'sample@example.test'
                : 'sample',
        };
    }

    /**
     * Route patterns carry their placeholders; a request needs a path.
     *
     * The sample value only has to satisfy the pattern so the route matches —
     * whether the id exists is beside the point, because a guarded route must
     * refuse before it ever looks.
     */
    private static function concretePath(string $route): string
    {
        return (string) preg_replace_callback(
            '#\(\?P<[^>]+>([^)]*)\)#',
            static fn (array $match): string => str_contains($match[1], '\d') ? '1' : 'sample',
            $route
        );
    }

    /**
     * One entry per route *and method*, because a single route can register a
     * generous GET next to a guarded POST.
     *
     * @return list<array{route: string, method: string, handler: array<string, mixed>}>
     */
    private function ourHandlers(): array
    {
        $entries = [];

        foreach (rest_get_server()->get_routes() as $route => $handlers) {
            if (! self::isOurs($route)) {
                continue;
            }

            foreach ($handlers as $handler) {
                foreach (array_keys(array_filter((array) ($handler['methods'] ?? []))) as $method) {
                    $entries[] = [
                        'route'   => $route,
                        'method'  => (string) $method,
                        'handler' => $handler,
                    ];
                }
            }
        }

        return $entries;
    }

    /**
     * @return list<string>
     */
    private function ourRoutes(): array
    {
        return array_values(array_filter(
            array_keys(rest_get_server()->get_routes()),
            static fn (string $route): bool => self::isOurs($route)
        ));
    }

    /**
     * Prefix matching, but not so loose that a future `/ecrm/v10` would be
     * mistaken for ours and quietly inherit this file's approval.
     */
    private static function isOurs(string $route): bool
    {
        return $route === self::ROOT || str_starts_with($route, self::ROOT . '/');
    }

    /** A callback in a form that can be printed in a failure message. */
    private function describe(mixed $callback): string
    {
        if (is_string($callback)) {
            return $callback;
        }

        if (is_array($callback) && count($callback) === 2) {
            $class = is_object($callback[0]) ? $callback[0]::class : (string) $callback[0];

            return $class . '::' . (string) $callback[1];
        }

        return get_debug_type($callback);
    }

    /**
     * @param list<string> $offenders
     */
    private function explain(string $problem, array $offenders): string
    {
        return $problem . ":\n  " . implode("\n  ", $offenders)
            . "\n\nIf one of these is meant to be open, add it to PUBLIC_ROUTES with the reason."
            . "\nA 400 means something else: the args schema rejected the request before the"
            . "\nguard ran, so the route rejects every caller. Look at the schema, not at this test.";
    }
}
