<?php

/**
 * PHPUnit bootstrap.
 *
 * These are *unit* tests: no WordPress, no database. Classes under test must
 * therefore be free of global WP calls — which is exactly the constraint that
 * keeps the new architecture honest. Anything needing a live WordPress belongs
 * in a separate integration suite with its own bootstrap.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
