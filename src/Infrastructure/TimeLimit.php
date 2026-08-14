<?php

/**
 * Give slow work more time, never less.
 *
 * Rendering a provider form can take a while — tFPDF stamping Unicode text
 * over a JPEG background, once per sheet — so the code that does it asks for
 * sixty seconds. On a web request that is exactly right: the host's default is
 * usually thirty, and the alternative is a half-written PDF.
 *
 * On the command line it was a bug, and an expensive one to find. PHP's CLI
 * runs with no limit at all, and `set_time_limit(60)` does not mean "at least
 * sixty" — it means "sixty from this moment", so every call *lowered* an
 * unlimited budget and restarted the clock. The integration suite renders
 * documents in the middle of its run, and died three times at exactly sixty
 * seconds, in `pluggable.php`, in `class-wpdb.php` and in `meta.php`. Three
 * different places because the place is wherever the suite happens to be when
 * the last render's sixty seconds expire.
 *
 * Two earlier attempts at fixing it treated the symptom: passing
 * `-d max_execution_time=0` to PHPUnit, and then to the PHP binary. The second
 * looked like it worked, because it happened to run on a quiet machine.
 * Neither could work, because the limit was being reset from inside the code
 * under test.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

final class TimeLimit
{
    private function __construct()
    {
    }

    /**
     * Ask for at least this many seconds of execution time.
     *
     * A limit of zero means unlimited, and unlimited is not something to
     * improve on — that is the CLI's normal state and what the test suite
     * relies on. Any other value is refreshed, which is the behaviour the web
     * request wanted in the first place.
     *
     * Safe to call where `set_time_limit()` is disabled by the host: the
     * function is checked rather than assumed, and a failure to extend is not
     * worth an exception in the middle of building a document.
     */
    public static function atLeast(int $seconds): void
    {
        if ((int) ini_get('max_execution_time') === 0) {
            return;
        }

        if (! function_exists('set_time_limit')) {
            return;
        }

        @set_time_limit($seconds); // phpcs:ignore WordPress.PHP.NoSilencedErrors
    }
}
