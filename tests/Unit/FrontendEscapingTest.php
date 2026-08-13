<?php

/**
 * Nothing reaches innerHTML without esc().
 *
 * The frontend was swept by hand on 2026-08-13 and was clean: 78 innerHTML
 * sites against 146 esc() calls, no unescaped customer data anywhere. That is
 * not the problem. The problem is *why* it was clean — because somebody
 * remembered, 146 times, without missing one. Nothing enforced it: no test, no
 * lint, no type. Whoever forgets one on a customer name ships stored XSS into a
 * system holding ΑΦΜ, ΑΔΤ and scanned ID cards, and the suite stays green —
 * exactly how the three defects a printed PDF caught survived for months.
 *
 * So this is the enforcement. It is a structural test in the mould of
 * RestRouteGuardTest: it asserts a property of the source tree, not a behaviour.
 *
 * ## It is an approval list, not a clever detector
 *
 * The scanner finds interpolated **property accesses** — `+ obj.prop +` inside
 * a string that looks like HTML — and reports the ones not wrapped in esc().
 * Whatever it reports must equal APPROVED exactly. A new site fails the suite
 * until a human looks at it and either wraps it or writes down why not.
 *
 * Property access is the narrow target on purpose. A broader scan for every
 * interpolation returned 101 sites here, nearly all of them counters and local
 * literals; an approval list that long is one nobody reads, and a list nobody
 * reads is worse than none because it looks like coverage. Server data arrives
 * as properties of a decoded JSON row, so that is where the scan looks.
 *
 * ## What this does NOT catch, said plainly
 *
 * **It does not follow variables.** `var sub = [t.customer, link].join(' · ')`
 * followed by `'<div>' + sub + '</div>'` is invisible to it — the interpolation
 * it sees is a bare local. That exact shape exists today at
 * `ecrm-app.js:1486`; it is harmless only because /tasks never sends those
 * fields, which is a fact about the API and not something this test knows.
 * Catching it would need real dataflow analysis. Read the diff of a render
 * function; do not assume a green suite read it for you.
 *
 * It also does not judge *context*: esc() covers `& < > "`, which is right for
 * text and for double-quoted attributes, and wrong for an unquoted attribute,
 * a `javascript:` URL, or anything inside a `<script>` or `style=`. None of
 * those shapes exist in these files today.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class FrontendEscapingTest extends TestCase
{
    /**
     * Every unescaped interpolation that a human has looked at and cleared.
     *
     * Format: `file.js expression`. Deliberately without a line number: one
     * would churn every time an unrelated edit shifted the file, and a list
     * that cries wolf is a list people silence. Grep for the expression.
     *
     * Keep the reason beside each one — a bare list decays into a place to
     * append and move on.
     */
    private const APPROVED = [
        // Both are keys of an array literal written three lines above the
        // render, holding hardcoded Greek labels and CSS class names. No
        // server data can reach them.
        'ecrm-app.js x.cls',
        'ecrm-app.js x.k',
    ];

    /**
     * Property names that are structurally numeric or opaque ids in this
     * codebase, and cannot carry text a person typed.
     *
     * This list is the scanner's only shortcut, so it is deliberately short.
     * When in doubt leave a name out: a false alarm costs one reading, a
     * wrongly-skipped field costs an XSS.
     */
    private const NUMERIC_PROPERTIES = [
        'id', 'contract_id', 'customer_id', 'lead_id', 'file_id', 'provider_id',
        'program_id', 'count', 'c', 'length', 'today', 'month', 'pending',
        'routed', 'open_tasks', 'contracts', 'matched', 'updated', 'unchanged',
        'unmatched_total', 'threshold', 'age_days', 'days_left', 'amount',
        'window', 'pending_est', 'total', 'page', 'pages', 'sort_order', 'v', 'n',
    ];

    /** The one module that may define the shared helpers. */
    private const UTIL_MODULE = 'ecrm-util.js';

    /** A line is only inspected if it is building markup. */
    private const HTML_HINT = '/[\'"]<|<\/|<div|<span|<option|<button|<li|<img'
        . '|<td|<tr|<a |<strong|<select|<ul|<details|<summary|<input|<p |<h\d/';

    /** `+ obj.prop +` and `+ obj.prop.sub +`. */
    private const INTERPOLATION = '/\+\s*((?:[A-Za-z_$][\w$]*)(?:\.[A-Za-z_$][\w$]*)+)\s*\+/';

    /** Calls whose argument is already safe by the time it lands in HTML. */
    private const SANITISERS = '/(?:esc|encodeURIComponent|Number|parseInt|parseFloat)\s*\(\s*([^)]*?)\s*\)/';

    public function testNoUnescapedPropertyReachesInnerHtml(): void
    {
        $found = [];

        foreach ($this->scriptFiles() as $name => $path) {
            foreach ($this->unescapedIn($name, $path) as $hit) {
                $found[] = $hit;
            }
        }

        sort($found);
        $approved = self::APPROVED;
        sort($approved);

        self::assertSame(
            $approved,
            $found,
            "The set of unescaped interpolations changed.\n\n"
            . "If you added one: wrap it in esc(), or — if it genuinely cannot carry\n"
            . "text a person typed — add it to APPROVED with the reason beside it.\n"
            . "If you removed one: delete its line from APPROVED.\n"
        );
    }

    /**
     * The sweep is actually looking at something.
     *
     * Without this, a regex that silently stops matching turns this file into a
     * test that always passes — the most expensive kind of green. Borrowed
     * straight from RestRouteGuardTest, which needs it for the same reason.
     */
    public function testTheSweepIsActuallyLookingAtSomething(): void
    {
        $innerHtmlSites = 0;
        $escCalls       = 0;

        foreach ($this->scriptFiles() as $path) {
            $source          = (string) file_get_contents($path);
            $innerHtmlSites += substr_count($source, 'innerHTML');
            $escCalls       += substr_count($source, 'esc(');
        }

        self::assertGreaterThan(50, $innerHtmlSites, 'The scanner found almost no innerHTML — did the files move?');
        self::assertGreaterThan(100, $escCalls, 'The scanner found almost no esc() — did the helper get renamed?');
    }

    /**
     * esc() is defined once, and still escapes what every caller assumes.
     *
     * It used to be defined three times, one copy per file, and this test
     * asserted the copies had not drifted — a guard papering over a
     * duplication instead of the duplication being fixed. ecrm-util.js is the
     * fix; this is what keeps it the only copy.
     */
    public function testEscIsDefinedExactlyOnceAndStillCoversTheSameCharacters(): void
    {
        $definitions = [];

        foreach ($this->scriptFiles() as $name => $path) {
            $source = (string) file_get_contents($path);

            if (preg_match('/function esc\s*\(/', $source) === 1) {
                $definitions[] = $name;
            }
        }

        self::assertSame(
            [self::UTIL_MODULE],
            $definitions,
            'esc() must be defined in ' . self::UTIL_MODULE . ' and imported everywhere else. '
            . 'A second copy is a copy that can drift.'
        );

        self::assertStringContainsString(
            'replace(/[&<>"]/g',
            (string) file_get_contents($this->scriptFiles()[self::UTIL_MODULE]),
            'esc() no longer covers & < > " — every innerHTML in the codebase assumed it did.'
        );
    }

    /** Whatever calls esc() must have imported it, not found it on a global. */
    public function testEveryScriptThatEscapesImportsTheHelper(): void
    {
        foreach ($this->scriptFiles() as $name => $path) {
            if ($name === self::UTIL_MODULE) {
                continue;
            }

            $source = (string) file_get_contents($path);

            if (! str_contains($source, 'esc(')) {
                continue;
            }

            self::assertMatchesRegularExpression(
                '/^import \{[^}]*\besc\b[^}]*\} from \x27\.\/' . preg_quote(self::UTIL_MODULE, '/') . '\x27;/m',
                $source,
                "{$name} calls esc() without importing it from " . self::UTIL_MODULE . '.'
            );
        }
    }

    /**
     * No second way of putting a string into the DOM as markup.
     *
     * The whole guard is scoped to innerHTML. If one of these appears, the
     * scope is wrong and the guard is quietly covering less than it claims.
     */
    public function testInnerHtmlIsStillTheOnlyWayMarkupEntersTheDom(): void
    {
        $forbidden = ['insertAdjacentHTML', 'document.write', 'outerHTML', 'createContextualFragment', 'new Function'];

        foreach ($this->scriptFiles() as $name => $path) {
            $source = (string) file_get_contents($path);

            foreach ($forbidden as $needle) {
                self::assertStringNotContainsString(
                    $needle,
                    $source,
                    "{$name} uses {$needle}, which this guard does not inspect. Either "
                    . 'remove it or widen the scanner — do not leave it unwatched.'
                );
            }
        }
    }

    /**
     * @return array<string, string> filename => absolute path
     */
    private function scriptFiles(): array
    {
        $dir   = dirname(__DIR__, 2) . '/public/assets';
        $found = glob($dir . '/*.js');

        self::assertNotEmpty($found, "No scripts found in {$dir} — the path is wrong, not the code.");

        $files = [];

        foreach ($found === false ? [] : $found as $path) {
            $files[basename($path)] = $path;
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    private function unescapedIn(string $name, string $path): array
    {
        $lines = explode("\n", (string) file_get_contents($path));
        $hits  = [];

        foreach ($lines as $index => $line) {
            if (preg_match(self::HTML_HINT, $line) !== 1) {
                continue;
            }

            $safe = [];

            if (preg_match_all(self::SANITISERS, $line, $wrapped) !== false) {
                foreach ($wrapped[1] as $argument) {
                    $safe[trim($argument)] = true;
                }
            }

            if (preg_match_all(self::INTERPOLATION, $line, $matches) === false) {
                continue;
            }

            foreach ($matches[1] as $expression) {
                if (isset($safe[$expression])) {
                    continue;
                }

                $parts    = explode('.', $expression);
                $property = end($parts);

                if (in_array($property, self::NUMERIC_PROPERTIES, true)) {
                    continue;
                }

                $hit = $name . ' ' . $expression;

                // The same expression twice on one line is one thing to review.
                if (! in_array($hit, $hits, true)) {
                    $hits[] = $hit;
                }
            }
        }

        return $hits;
    }
}
