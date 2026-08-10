<?php

/**
 * Registers every controller on rest_api_init.
 *
 * That is the whole job. Which controllers exist, and what each one needs to be
 * built, is ControllerFactory's business — this class only knows that it holds
 * some and that WordPress wants them at a particular moment.
 *
 * A route must never live both here and in ECRM_REST: WordPress silently keeps
 * whichever registered last.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

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
    }
}
