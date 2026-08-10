<?php

/**
 * Shared ground for tests that talk to a real database.
 *
 * ## Why this is not WP_UnitTestCase
 *
 * The obvious base class calls `PHPUnit\Util\Test::parseTestMethodAnnotations()`,
 * removed in PHPUnit 10. WordPress' test library still targets PHPUnit 9, and
 * this project runs 11 — the unit suite uses attributes, which 9 cannot read.
 * One of the two had to give, and downgrading PHPUnit to satisfy a base class
 * would have cost 131 working tests.
 *
 * What WP_UnitTestCase actually provides that matters here is small: a
 * transaction per test, and a way to make a user. Both are a few lines. The
 * rest of it — annotation parsing, expectedDeprecated, its own factories — is
 * machinery this suite does not use.
 *
 * WordPress itself is still fully loaded: the bootstrap installs and boots it
 * exactly as the library intends. Only the test case is ours.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Persistence\Tables;
use PHPUnit\Framework\TestCase;
use WP_User;

abstract class IntegrationTestCase extends TestCase
{
    /** Distinguishes the users each test makes, without a database round trip. */
    private static int $userCounter = 0;

    /**
     * Every test runs inside a transaction that is never committed.
     *
     * So a test can write whatever it needs and the next one starts from the
     * same clean install, with no teardown to forget. This is exactly what
     * WP_UnitTestCase does, and it depends on the tables being InnoDB — which
     * the EnsureInnoDb migration guarantees for ours.
     */
    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;

        // Errors belong in the assertion, not in the middle of the output.
        $wpdb->hide_errors();

        $wpdb->query('SET autocommit = 0');
        $wpdb->query('START TRANSACTION');

        // The cache outlives the transaction and would answer with rows that
        // no longer exist.
        wp_cache_flush();
    }

    protected function tearDown(): void
    {
        global $wpdb;

        $wpdb->query('ROLLBACK');
        $wpdb->query('SET autocommit = 1');

        // Filters are global. Left in place, an encryption switch turned on in
        // one test would silently change the next.
        remove_all_filters('ecrm_encrypt_pii');

        wp_cache_flush();

        parent::tearDown();
    }

    /**
     * Turn on ciphertext writing for this test only.
     *
     * The reason the switch is a filter and not only a constant: a constant is
     * set once per process and can never be tested off.
     */
    protected function encryptionOn(): void
    {
        add_filter('ecrm_encrypt_pii', '__return_true');
    }

    /**
     * A row exactly as it sits on disk, with no repository in between.
     *
     * The point of most of these tests is the difference between what is
     * stored and what is returned, so one side has to bypass the translation.
     *
     * @return array<string, mixed>
     */
    protected function storedRow(string $unprefixedTable, int $id): array
    {
        global $wpdb;

        /** @var array<string, mixed>|null $row */
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM %i WHERE id = %d', Tables::name($unprefixedTable), $id),
            ARRAY_A
        );

        self::assertNotNull($row, "No row {$id} in {$unprefixedTable}.");

        return $row;
    }

    /** A partner who can own contracts. */
    protected function makePartner(): int
    {
        $n = ++self::$userCounter;

        $userId = wp_insert_user([
            'user_login' => 'partner' . $n . '_' . wp_generate_password(6, false),
            'user_email' => 'partner' . $n . '.' . wp_generate_password(6, false) . '@example.test',
            'user_pass'  => wp_generate_password(12, false),
            'role'       => 'subscriber',
        ]);

        self::assertIsInt($userId, 'Could not create a test user.');

        return $userId;
    }

    /**
     * A partner who also holds a CRM role, so the REST guards let them in.
     *
     * Separate from makePartner() on purpose: most of this suite calls
     * repositories directly and has no use for capabilities, while anything
     * going through a route needs them or never reaches the handler.
     */
    protected function makeCrmUser(string $role = Roles::SELLER): int
    {
        self::assertNotNull(
            get_role($role),
            "The role {$role} is not registered — did activation run Roles::sync()?"
        );

        $userId = $this->makePartner();

        $user = get_user_by('id', $userId);
        self::assertInstanceOf(WP_User::class, $user);

        // Replaces 'subscriber' rather than adding to it: a CRM user is one
        // thing, and two roles would make current_user_can() answer for both.
        $user->set_role($role);

        return $userId;
    }

    /**
     * A customer with every field this plugin treats as sensitive.
     *
     * @return array<string, string>
     */
    protected function customerData(string $afm = '123456789'): array
    {
        return [
            'customer_type' => 'individual',
            'first_name'    => 'Γιώργος',
            'last_name'     => 'Παπαδόπουλος',
            'afm'           => $afm,
            'adt'           => 'ΑΒ123456',
            'street'        => 'Αγίου Δημητρίου',
            'street_no'     => '14',
            'postal_code'   => '54633',
            'city'          => 'Θεσσαλονίκη',
            'region'        => 'Θεσσαλονίκης',
            'phone'         => '2310123456',
            'email'         => 'giorgos@example.test',
        ];
    }
}
