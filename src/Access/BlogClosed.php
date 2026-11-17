<?php

/**
 * (409) Το blog του WordPress κλειστό: το CRM δεν γράφει άρθρα.
 *
 * Στις 17/11 βρέθηκαν στο live 4.768 άρθρα spam (καζίνο, στοίχημα), γραμμένα
 * με κλεμμένους κωδικούς εφαρμογής του «test». Έβγαιναν στο sitemap, στο RSS
 * και στη σελίδα 404, και η Google τα ευρετηρίαζε κάτω από το domain μας
 * (project: claude/energy-crm-incident-1711.md). Το CRM ζει σε μία σελίδα·
 * άρθρα, σχόλια, feeds και αρχεία κατηγοριών δεν τα χρειάζεται κανείς. Αν
 * κάποιος ξαναγράψει άρθρο, δεν φαίνεται πουθενά, και η Υγεία το δείχνει
 * κόκκινο (`HealthChecks::security()`).
 *
 * - Τα άρθρα δεν είναι δημόσια: ούτε σελίδα, ούτε REST, ούτε αναζήτηση. Στο
 *   wp-admin φαίνονται ακόμη, για να μπορεί να τα δει όποιος ψάχνει.
 * - Feeds, σχόλια και pingbacks κλειστά.
 * - Sitemap: μόνο σελίδες. Ούτε χρήστες (τα ονόματα σύνδεσης), ούτε κατηγορίες.
 * - Κάθε «δεν βρέθηκε» του δημόσιου site απαντά 410 (Gone) αντί για 404, και
 *   τα αρχεία κατηγορίας/ετικέτας/ημερομηνίας επίσης: η Google βγάζει τις
 *   διευθύνσεις του spam από το ευρετήριο πιο γρήγορα.
 *
 * Ξανανοίγει, αν ποτέ χρειαστεί blog:
 *   add_filter( 'ecrm_close_blog', '__return_false' );
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Access;

final class BlogClosed
{
    /** Τα feeds του WordPress, όλα. */
    private const FEEDS = ['do_feed', 'do_feed_rdf', 'do_feed_rss', 'do_feed_rss2', 'do_feed_atom'];

    public function register(): void
    {
        if (! apply_filters('ecrm_close_blog', true)) {
            return;
        }

        add_filter('register_post_type_args', [$this, 'postTypeArgs'], 10, 2);

        add_filter('comments_open', '__return_false', 20);
        add_filter('pings_open', '__return_false', 20);

        remove_action('wp_head', 'feed_links', 2);
        remove_action('wp_head', 'feed_links_extra', 3);
        foreach (self::FEEDS as $feed) {
            add_action($feed, [$this, 'gone'], 1);
        }

        add_filter('wp_sitemaps_add_provider', [$this, 'sitemapProvider'], 10, 2);
        add_filter('wp_sitemaps_post_types', [$this, 'sitemapPostTypes']);

        add_action('template_redirect', [$this, 'goneInsteadOfNotFound'], 99);
    }

    /**
     * Το «post» μένει στο wp-admin αλλά όχι στο δημόσιο site ή στο REST.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function postTypeArgs(array $args, string $postType): array
    {
        if ($postType !== 'post') {
            return $args;
        }

        return array_merge($args, self::closedPostArgs());
    }

    /** @return array<string, bool> */
    public static function closedPostArgs(): array
    {
        return [
            'public'              => false,
            'publicly_queryable'  => false,
            'exclude_from_search' => true,
            'show_in_rest'        => false,
            'show_in_nav_menus'   => false,
            'has_archive'         => false,
            'show_ui'             => true,
        ];
    }

    /**
     * Χωρίς χρήστες και κατηγορίες στο sitemap.
     *
     * @param mixed $provider
     * @return mixed
     */
    public function sitemapProvider($provider, string $name)
    {
        return self::sitemapKeeps($name) ? $provider : false;
    }

    public static function sitemapKeeps(string $provider): bool
    {
        return $provider === 'posts';
    }

    /**
     * Μέσα στο «posts» του sitemap: μόνο σελίδες.
     *
     * @param array<string, mixed> $types
     * @return array<string, mixed>
     */
    public function sitemapPostTypes(array $types): array
    {
        unset($types['post']);

        return $types;
    }

    /** Feed: 410, χωρίς σώμα. */
    public function gone(): void
    {
        status_header(410);
        nocache_headers();
        exit;
    }

    /**
     * Δημόσιο «δεν βρέθηκε» και αρχεία κατηγορίας/ετικέτας/ημερομηνίας: 410.
     * Το θέμα δείχνει κανονικά τη σελίδα του· αλλάζει μόνο ο κωδικός.
     */
    public function goneInsteadOfNotFound(): void
    {
        if (is_admin() || ! self::isGone(is_404(), is_category() || is_tag() || is_date())) {
            return;
        }

        status_header(410);
        nocache_headers();
    }

    public static function isGone(bool $notFound, bool $blogArchive): bool
    {
        return $notFound || $blogArchive;
    }
}
