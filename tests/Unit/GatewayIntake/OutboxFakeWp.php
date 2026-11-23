<?php

/**
 * Ψεύτικο WordPress για το `GatewayEventOutboxTest`: μνήμη αντί για βάση.
 *
 * Οι συναρτήσεις ορίζονται στο namespace της ουράς, οπότε η ίδια η κλάση τις
 * βρίσκει πριν από τις παγκόσμιες. Φορτώνεται μόνο από εκείνο το τεστ.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\GatewayIntake\Infrastructure;

/** Μνήμη αντί για WordPress, μόνο για το τεστ της ουράς. */
final class OutboxFakeWp
{
    /** @var array<string, mixed> */
    public static array $options = [];
    /** @var list<int> */
    public static array $responses = [];
    /** @var list<string> */
    public static array $posts = [];
    public static int $now = 1700000000;
    public static int $locks = 0;
    /** @var (callable(): mixed)|null */
    public static $duringPost = null;

    public static function reset(): void
    {
        self::$options    = [];
        self::$responses  = [];
        self::$posts      = [];
        self::$locks      = 0;
        self::$duringPost = null;
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- ψεύτικο $wpdb, μόνο για το τεστ.
        $GLOBALS['wpdb']  = new class () {
            public function prepare(string $q, mixed ...$a): string
            {
                return $q . '|' . implode('|', $a);
            }
            // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- όνομα του $wpdb.
            public function get_var(string $q): string
            {
                OutboxFakeWp::$locks++;

                return '1';
            }
            public function query(string $q): int
            {
                return 1;
            }
        };
    }
}

function time(): int
{
    return OutboxFakeWp::$now;
}
function get_option(string $k, mixed $d = false): mixed
{
    return OutboxFakeWp::$options[$k] ?? $d;
}
function update_option(string $k, mixed $v, mixed $a = null): bool
{
    OutboxFakeWp::$options[$k] = $v;

    return true;
}
function wp_cache_delete(string $k, string $g = ''): bool
{
    return true;
}
function wp_json_encode(mixed $v, int $f = 0): string|false
{
    return json_encode($v, $f);
}
function wp_remote_post(string $url, array $args): array
{
    OutboxFakeWp::$posts[] = (string) $args['body'];
    if (OutboxFakeWp::$duringPost !== null) {
        $fn = OutboxFakeWp::$duringPost;
        OutboxFakeWp::$duringPost = null;
        $fn();
    }

    return ['code' => array_shift(OutboxFakeWp::$responses) ?? 0];
}
function is_wp_error(mixed $r): bool
{
    return false;
}
function wp_remote_retrieve_response_code(array $r): int
{
    return (int) $r['code'];
}
function wp_next_scheduled(string $h): int|false
{
    return false;
}
function wp_unschedule_event(int $t, string $h): bool
{
    return true;
}
function wp_schedule_single_event(int $t, string $h): bool
{
    return true;
}
function error_log(string $m): bool
{
    return true;
}
