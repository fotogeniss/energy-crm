<?php

/**
 * What the network does when the hierarchy gets deep, or loops.
 *
 * On 2026-08-04 this plugin exhausted PHP's 256 MB limit twenty-two times in
 * two hours, and every one of them died inside `NetworkPath`. The line the log
 * names is `ids()`, which is misleading in the way memory errors always are:
 * it reports where the last allocation failed, not where the memory went.
 * `ids()` calls `explode()` on the path, so a path that had grown without
 * bound became an array that could not be allocated. The path was the problem;
 * `explode()` was the victim.
 *
 * The answer was three guards, added the following day and never tested:
 * `MAX_DEPTH` in `NetworkRepository::computePath()`, the repeated-id check in
 * the same walk, and `NetworkPath::isValid()` rejecting duplicate segments.
 * The unit tests cover the third — the string rules — and nothing covered the
 * first two, which are the ones standing between a malformed parent chain and
 * a dead PHP worker.
 *
 * Ten days without a recurrence is not evidence. The deepest hierarchy any
 * other test builds is three levels, in ContractNotificationsTest, and three
 * levels cannot tell a working depth guard from an absent one.
 *
 * ## Why there are no real users here
 *
 * `wp_insert_user()` hashes a password, deliberately slowly, and this file
 * needs fifty-five partners. Every method under test reads `usermeta` and
 * nothing else — `computePath()` walks `ecrm_parent`, `subtreeIds()` matches
 * `ecrm_path` — so meta rows against synthetic ids exercise exactly the code
 * that mattered on 4 August, in a fraction of the time.
 *
 * Setting the parent is still the real trigger: `update_user_meta()` fires the
 * hook NetworkSync listens on, so these fixtures drive the same rebuild an
 * importer or an admin screen would.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\NetworkPath;
use EnergyCRM\Persistence\NetworkRepository;

final class NetworkDepthTest extends IntegrationTestCase
{
    /**
     * Mirrors NetworkRepository::MAX_DEPTH, which is private.
     *
     * Duplicated rather than read by reflection: if someone changes the guard,
     * this file should fail and make them read the consequence below, not
     * silently follow along.
     */
    private const MAX_DEPTH = 50;

    /** Far enough above the guard that the truncation is unambiguous. */
    private const DEEP = 55;

    /**
     * Ids nobody else will use. The suite runs in a transaction, but a
     * collision with a real fixture user would make a failure here look like a
     * failure somewhere else.
     */
    private const BASE_ID = 900_000;

    private NetworkRepository $network;

    protected function setUp(): void
    {
        parent::setUp();

        $this->network = new NetworkRepository();
    }

    // --- The guard between a bad chain and a dead worker --------------------

    /**
     * Fifty-five levels resolve, and do not eat the process.
     *
     * The assertion that matters is not the count — it is that this returns at
     * all, in bounded memory. Before the guard, a chain the walk could not
     * finish grew the path until `explode()` could no longer allocate.
     */
    public function testAChainDeeperThanTheGuardStillResolvesInBoundedMemory(): void
    {
        $chain   = $this->seedChain(self::DEEP);
        $deepest = $chain[self::DEEP - 1];

        $before = memory_get_usage();
        $path   = $this->network->pathFor($deepest);
        $spent  = memory_get_usage() - $before;

        self::assertTrue(NetworkPath::isValid($path), "A deep chain produced an unusable path: {$path}");
        self::assertLessThan(
            1_048_576,
            $spent,
            'Resolving one path allocated over a megabyte — the growth this guard exists to stop.'
        );
    }

    /**
     * The path stops at the guard, and the top of the tree is what falls off.
     *
     * This is the cost of bounding the walk, asserted rather than left to be
     * discovered: past fifty levels a partner's path no longer starts at the
     * real root, so **everyone above that cut stops seeing them**. A network
     * that deep needs a different design, not a larger number here.
     */
    public function testPastTheGuardThePathIsTruncatedAndLosesTheRealRoot(): void
    {
        $chain    = $this->seedChain(self::DEEP);
        $deepest  = $chain[self::DEEP - 1];
        $realRoot = $chain[0];

        $ids = NetworkPath::ids($this->network->pathFor($deepest));

        self::assertCount(self::MAX_DEPTH + 1, $ids, 'The walk did not stop where the guard says.');
        self::assertSame($deepest, $ids[count($ids) - 1], 'The subject must still be last on their own path.');
        self::assertNotContains(
            $realRoot,
            $ids,
            'The true root is expected to fall off past the guard. If this fails the guard moved — '
            . 'read the docblock before changing the number.'
        );
    }

    /**
     * A parent chain that loops does not hang the request.
     *
     * A cycle is not hypothetical: it takes one hand-edited row, or an import
     * that assigns two partners to each other. Before the repeated-id check
     * this walked forever.
     */
    public function testACycleInTheParentChainTerminates(): void
    {
        $a = self::BASE_ID + 1;
        $b = self::BASE_ID + 2;
        $c = self::BASE_ID + 3;

        $this->setParent($b, $a);
        $this->setParent($c, $b);
        $this->setParent($a, $c); // closes the loop

        $path = $this->network->pathFor($c);

        self::assertTrue(NetworkPath::isValid($path), "A cycle produced an unusable path: {$path}");
        self::assertSame($c, NetworkPath::subjectId($path));

        $ids = NetworkPath::ids($path);
        self::assertSame(count($ids), count(array_unique($ids)), 'A path may never repeat an id.');
    }

    // --- Scoping still has to be right at depth ----------------------------

    public function testSomeoneAtTheTopSeesEveryLevelBeneathThem(): void
    {
        $chain = $this->seedChain(8);
        $this->materialisePaths($chain);

        $visible = $this->network->subtreeIds($chain[0]);

        foreach ($chain as $level => $userId) {
            self::assertContains($userId, $visible, "Level {$level} is invisible from the top.");
        }
    }

    public function testSomeoneInTheMiddleSeesTheirBranchAndNothingAbove(): void
    {
        $chain = $this->seedChain(8);
        $this->materialisePaths($chain);

        $visible = $this->network->subtreeIds($chain[4]);

        self::assertContains($chain[4], $visible, 'A partner must see themselves.');
        self::assertContains($chain[7], $visible, 'A partner must see the bottom of their own branch.');

        self::assertNotContains($chain[3], $visible, 'Their manager is not beneath them.');
        self::assertNotContains($chain[0], $visible, 'Nor is the top of the tree.');
    }

    /**
     * Setting a parent still rebuilds the path on its own.
     *
     * The rest of this file seeds edges directly for speed, which means none
     * of it would notice if NetworkSync stopped listening. Three levels
     * through the real API costs nothing and keeps that honest.
     */
    public function testTheRebuildHookStillMaintainsThePath(): void
    {
        $top    = self::BASE_ID + 801;
        $middle = self::BASE_ID + 802;
        $bottom = self::BASE_ID + 803;

        $this->setParent($middle, $top);
        $this->setParent($bottom, $middle);

        $stored = (string) get_user_meta($bottom, NetworkRepository::PATH_META, true);

        self::assertSame(
            '/' . $top . '/' . $middle . '/' . $bottom . '/',
            $stored,
            'Nobody wrote this path — setting the parent was supposed to be enough.'
        );
    }

    /**
     * The delimiter trap, at the database rather than in a string.
     *
     * NetworkPathTest proves '/1/7/' does not prefix-match '/1/70/'. That is
     * the rule; this is the rule surviving contact with `LIKE`, where
     * `esc_like()` and the stored value both have to hold it up.
     *
     * The ids are chosen so one is a decimal prefix of the other — 90007 and
     * 900071 — because that is the only shape where dropping the trailing
     * slash would leak a stranger's customers into a manager's list.
     */
    public function testASiblingWhoseIdSharesADigitPrefixIsNotInTheSubtree(): void
    {
        $root    = self::BASE_ID + 500;
        $seven   = 90_007;   // '/…/90007/'
        $seventy = 900_071;  // '/…/900071/' — starts with the digits of 90007
        $under   = self::BASE_ID + 501;

        $this->setParent($seven, $root);
        $this->setParent($seventy, $root);
        $this->setParent($under, $seven);

        $visible = $this->network->subtreeIds($seven);

        self::assertContains($under, $visible, 'A real descendant went missing.');
        self::assertNotContains(
            $seventy,
            $visible,
            'A sibling was pulled in by prefix matching — the bug the trailing slash exists to prevent.'
        );
    }

    // --- Fixtures ----------------------------------------------------------

    /**
     * A straight chain, root first, seeded without firing the rebuild hook.
     *
     * The obvious fixture — update_user_meta() per level — costs O(n²): every
     * parent set triggers NetworkSync, which walks the whole chain upward
     * again. Building fifty-five levels that way ran about fourteen hundred
     * meta reads and put thirty-eight seconds on the suite, to test a guard
     * that one walk exercises perfectly well.
     *
     * So the edges go in directly and the walk is asked for once. What this
     * gives up is hook coverage, which is why
     * testTheRebuildHookStillMaintainsThePath() keeps it, at three levels
     * where the cost is nothing.
     *
     * @return list<int>
     */
    private function seedChain(int $levels): array
    {
        global $wpdb;

        $chain = [];

        for ($level = 0; $level < $levels; $level++) {
            $userId = self::BASE_ID + $level + 1;

            if ($level > 0) {
                $wpdb->insert($wpdb->usermeta, [
                    'user_id'    => $userId,
                    'meta_key'   => NetworkRepository::PARENT_META,
                    'meta_value' => (string) $chain[$level - 1],
                ]);
            }

            $chain[] = $userId;
        }

        // Written behind WordPress' back, so its meta cache does not know.
        wp_cache_flush();

        return $chain;
    }

    /**
     * Materialise the paths a subtree query matches against.
     *
     * @param list<int> $chain
     */
    private function materialisePaths(array $chain): void
    {
        foreach ($chain as $userId) {
            $this->network->pathFor($userId);
        }
    }

    private function setParent(int $userId, int $parentId): void
    {
        update_user_meta($userId, NetworkRepository::PARENT_META, $parentId);
    }
}
