<?php

/**
 * A group of REST routes for one resource.
 *
 * Controllers are registered by the Router and nothing else, so adding an
 * endpoint means adding a class to one list rather than another entry in a
 * two-hundred-line routes() method.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

interface Controller
{
    /** Called inside rest_api_init. */
    public function routes(): void;
}
