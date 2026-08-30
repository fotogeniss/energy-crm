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
    /**
     * Absolute time (`microtime(true)`) the budget we last set expires.
     *
     * Null means "we have never touched the limit in this process" -- the
     * only state in which `ini_get('max_execution_time') === 0` is trusted to
     * mean genuinely unlimited (see the guard below). Once we call
     * `set_time_limit()` ourselves, `ini_get()` reports back whatever we just
     * set rather than 0, so it can no longer answer "how much is actually
     * left" -- only this class's own record of the deadline it last granted
     * can.
     */
    private static ?float $deadline = null;

    private function __construct()
    {
    }

    /**
     * Ask for at least this many seconds of execution time.
     *
     * AUDIT 30/08: the class docblock and this method's own name promise
     * "never less", but the body called `set_time_limit($seconds)`
     * unconditionally whenever a limit was in effect at all -- which resets
     * the timer to exactly `$seconds` from *this* call, discarding whatever
     * was left of a larger budget an earlier call already granted. Two calls
     * in the same request, the second asking for less than the first still
     * had remaining, silently shrank the deadline. Every caller today passes
     * 60, so the shape of the bug never showed up in practice -- but that is
     * luck in the call sites, not a guarantee in this method.
     *
     * The fix tracks the deadline this class itself has granted, in
     * `self::$deadline`, and only calls `set_time_limit()` when the request
     * genuinely needs more than what remains of it. `ini_get()` is trusted
     * only once, before this class has ever touched the limit -- afterwards
     * it reflects our own last write, not the original budget, so it cannot
     * tell us what is left.
     *
     * A limit of zero, seen before this class has touched anything, means
     * unlimited -- the CLI's normal state and what the test suite relies on
     * — and is left alone for the rest of the process; this class never calls
     * `set_time_limit()` at all in that case, so it can never be the one that
     * takes an unlimited budget away.
     *
     * Safe to call where `set_time_limit()` is disabled by the host: the
     * function is checked rather than assumed, and a failure to extend is not
     * worth an exception in the middle of building a document.
     */
    public static function atLeast(int $seconds): void
    {
        if (! function_exists('set_time_limit')) {
            return;
        }

        if ((int) ini_get('max_execution_time') === 0 && self::$deadline === null) {
            return;
        }

        $now = microtime(true);

        $remaining = self::$deadline !== null
            ? self::$deadline - $now
            : (float) ini_get('max_execution_time');

        if ($remaining >= $seconds) {
            // Already have at least this much left -- calling set_time_limit()
            // here would reset the clock to $seconds from now, which is a cut,
            // not an extension.
            return;
        }

        @set_time_limit($seconds); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        self::$deadline = $now + $seconds;
    }

    /**
     * Forget whatever deadline this class has granted itself.
     *
     * Test-only: the deadline is per-process state, and PHPUnit's process
     * does not restart between tests. Without this, a test that grants sixty
     * seconds would make every later test in the same run see fifty-nine
     * point something already "remaining" and skip its own extension.
     */
    public static function resetForTests(): void
    {
        self::$deadline = null;
    }
}
