<?php

/**
 * (409) Το blog κλειστό, η 0064 και οι δύο νέοι έλεγχοι της Υγείας.
 *
 * Μετά το spam της 17/11 (4.768 άρθρα με κλεμμένους κωδικούς εφαρμογής του
 * «test»): άρθρα δεν φαίνονται πουθενά δημόσια, κωδικοί εφαρμογής δεν
 * υπάρχουν, και αν ξαναεμφανιστεί κάτι από τα δύο η Υγεία γίνεται κόκκινη.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\BlogClosed;
use EnergyCRM\Infrastructure\HealthChecks;
use EnergyCRM\Persistence\Schema\Migrations\RevokeApplicationPasswords;
use EnergyCRM\Persistence\Schema\SchemaInspector;

final class BlogClosedTest extends IntegrationTestCase
{
    public function testPostsAreNotPublicAnywhere(): void
    {
        $post = get_post_type_object('post');

        self::assertNotNull($post);
        self::assertFalse($post->public);
        self::assertFalse($post->publicly_queryable);
        self::assertFalse($post->show_in_rest);
        self::assertTrue($post->exclude_from_search);
        // Στο wp-admin μένουν ορατά: όποιος ψάχνει, πρέπει να τα βρει.
        self::assertTrue($post->show_ui);
    }

    public function testPagesAreUntouched(): void
    {
        $page = get_post_type_object('page');

        self::assertNotNull($page);
        self::assertTrue($page->public);
    }

    public function testCommentsAndFeedsAreClosed(): void
    {
        self::assertFalse(apply_filters('comments_open', true, 0));
        self::assertFalse(apply_filters('pings_open', true, 0));
        self::assertNotFalse(has_action('do_feed_rss2'));
        self::assertFalse(has_action('wp_head', 'feed_links'));
    }

    public function testTheSitemapKeepsOnlyPages(): void
    {
        self::assertTrue(BlogClosed::sitemapKeeps('posts'));
        self::assertFalse(BlogClosed::sitemapKeeps('users'));
        self::assertFalse(BlogClosed::sitemapKeeps('taxonomies'));
        self::assertSame(['page' => 1], (new BlogClosed())->sitemapPostTypes(['post' => 1, 'page' => 1]));
    }

    public function testNotFoundAndBlogArchivesAnswerGone(): void
    {
        self::assertTrue(BlogClosed::isGone(true, false));
        self::assertTrue(BlogClosed::isGone(false, true));
        self::assertFalse(BlogClosed::isGone(false, false));
    }

    public function testTheMigrationRevokesEveryApplicationPassword(): void
    {
        $a = $this->makePartner();
        $b = $this->makePartner();
        update_user_meta($a, RevokeApplicationPasswords::META_KEY, [['uuid' => 'x', 'name' => 'PBN Automation']]);
        update_user_meta($b, RevokeApplicationPasswords::META_KEY, [['uuid' => 'y', 'name' => 'Blacklink WP Poster']]);

        self::assertFalse($this->health('Κωδικοί εφαρμογής')['ok']);

        (new RevokeApplicationPasswords())->apply(new SchemaInspector());

        self::assertSame('', get_user_meta($a, RevokeApplicationPasswords::META_KEY, true));
        self::assertSame('', get_user_meta($b, RevokeApplicationPasswords::META_KEY, true));
        self::assertTrue($this->health('Κωδικοί εφαρμογής')['ok']);
    }

    public function testAPublishedPostTurnsTheHealthCheckRed(): void
    {
        // Η εγκατάσταση δοκιμών μπορεί να έχει το «Hello world!».
        $existing = get_posts(['post_type' => 'post', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids']);
        foreach ($existing as $id) {
            wp_delete_post((int) $id, true);
        }

        self::assertTrue($this->health('Άρθρα WordPress')['ok']);

        wp_insert_post(['post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Mostbet RTP']);

        $check = $this->health('Άρθρα WordPress');
        self::assertFalse($check['ok']);
        self::assertStringContainsString('1 άρθρα', $check['detail']);
    }

    /** @return array{group: string, label: string, ok: bool|null, detail: string} */
    private function health(string $label): array
    {
        foreach ((new HealthChecks())->all() as $row) {
            if ($row['label'] === $label) {
                return $row;
            }
        }

        self::fail('Ο έλεγχος «' . $label . '» δεν υπάρχει στην οθόνη Υγεία.');
    }
}
