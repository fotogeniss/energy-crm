<?php

/**
 * Which address a request actually came from.
 *
 * Rate limiting is only as honest as this answer. The previous one read
 * `CF-Connecting-IP`, then `X-Forwarded-For`, then fell back to `REMOTE_ADDR`
 * — and both of those headers are typed by whoever is calling. Anyone could
 * send a different `X-Forwarded-For` on every request and get a fresh budget
 * each time, which means the limiter on the public signing and tracking routes
 * counted nothing at all.
 *
 * `REMOTE_ADDR` is the only value the web server observes rather than reads.
 * A forwarded header is worth something only when the connection itself came
 * from a proxy we put there, so the list of those is configuration, not a
 * guess — and it is empty by default.
 *
 * The second trap is subtler: even behind a real proxy, `X-Forwarded-For` is a
 * list the client can seed. A caller sending `X-Forwarded-For: 1.2.3.4` has
 * that value arrive as the *first* entry, with the proxy appending the true
 * address after it. So the chain is read from the right, skipping our own
 * proxies, and the first address that is not one of them is the client.
 *
 * Constructed with its trusted list rather than reading one, so the whole
 * decision runs in a unit test without WordPress.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

final class ClientIp
{
    /** Set by Cloudflare, which overwrites anything the caller sent. */
    private const CLOUDFLARE_HEADER = 'HTTP_CF_CONNECTING_IP';

    private const FORWARDED_HEADER = 'HTTP_X_FORWARDED_FOR';

    /**
     * @param list<string> $trustedProxies IPs or CIDR ranges we put in front
     *                                     of the site. Empty means no proxy,
     *                                     so no forwarded header is believed.
     */
    public function __construct(private readonly array $trustedProxies = [])
    {
    }

    /**
     * @param array<string, mixed> $server Normally $_SERVER.
     *
     * @return string An IP, or '' when the request has no usable address.
     */
    public function resolve(array $server): string
    {
        $remote = $this->ip($server['REMOTE_ADDR'] ?? null);

        if ($remote === '' || ! $this->isTrustedProxy($remote)) {
            return $remote;
        }

        $cloudflare = $this->ip($server[self::CLOUDFLARE_HEADER] ?? null);

        if ($cloudflare !== '') {
            return $cloudflare;
        }

        return $this->fromForwardedChain($server[self::FORWARDED_HEADER] ?? null, $remote);
    }

    /** The rightmost address in the chain that is not one of our own proxies. */
    private function fromForwardedChain(mixed $header, string $fallback): string
    {
        if (! is_string($header) || $header === '') {
            return $fallback;
        }

        $chain = array_reverse(explode(',', $header));

        foreach ($chain as $entry) {
            $candidate = $this->ip($entry);

            if ($candidate !== '' && ! $this->isTrustedProxy($candidate)) {
                return $candidate;
            }
        }

        return $fallback;
    }

    private function isTrustedProxy(string $ip): bool
    {
        foreach ($this->trustedProxies as $trusted) {
            if ($this->matches($ip, trim($trusted))) {
                return true;
            }
        }

        return false;
    }

    /** Exact address or CIDR range, IPv4 and IPv6 alike. */
    private function matches(string $ip, string $rule): bool
    {
        if ($rule === '') {
            return false;
        }

        if (! str_contains($rule, '/')) {
            return $ip === $rule;
        }

        [$subnet, $bits] = explode('/', $rule, 2);

        $address = inet_pton($ip);
        $network = inet_pton($subnet);

        // Never compare an IPv4 address against an IPv6 range: inet_pton
        // returns different lengths, and a prefix comparison would pass on
        // bytes that mean different things.
        if ($address === false || $network === false || strlen($address) !== strlen($network)) {
            return false;
        }

        $bits = (int) $bits;

        if ($bits < 0 || $bits > strlen($address) * 8) {
            return false;
        }

        $wholeBytes = intdiv($bits, 8);
        $spareBits  = $bits % 8;

        if ($wholeBytes > 0 && strncmp($address, $network, $wholeBytes) !== 0) {
            return false;
        }

        if ($spareBits === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $spareBits)) - 1) & 0xFF;

        return (ord($address[$wholeBytes]) & $mask) === (ord($network[$wholeBytes]) & $mask);
    }

    /** A valid address, or '' — never a half-parsed string. */
    private function ip(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        $value = trim($value);

        return filter_var($value, FILTER_VALIDATE_IP) === false ? '' : $value;
    }
}
