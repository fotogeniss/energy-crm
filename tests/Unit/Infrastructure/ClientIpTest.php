<?php

/**
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Infrastructure;

use EnergyCRM\Infrastructure\ClientIp;
use PHPUnit\Framework\TestCase;

final class ClientIpTest extends TestCase
{
    /**
     * The whole point. Without a declared proxy these headers are just text
     * the caller typed, and believing them hands out a fresh rate-limit
     * budget per request.
     */
    public function testForwardedHeadersAreIgnoredWhenNoProxyIsDeclared(): void
    {
        $resolver = new ClientIp();

        $ip = $resolver->resolve([
            'REMOTE_ADDR'             => '198.51.100.7',
            'HTTP_X_FORWARDED_FOR'    => '1.2.3.4',
            'HTTP_CF_CONNECTING_IP'   => '5.6.7.8',
        ]);

        self::assertSame('198.51.100.7', $ip);
    }

    /** A proxy we did not put there is still just a caller. */
    public function testForwardedHeadersAreIgnoredFromAnUntrustedAddress(): void
    {
        $resolver = new ClientIp(['203.0.113.1']);

        $ip = $resolver->resolve([
            'REMOTE_ADDR'          => '198.51.100.7',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
        ]);

        self::assertSame('198.51.100.7', $ip);
    }

    public function testCloudflareHeaderIsBelievedFromCloudflare(): void
    {
        $resolver = new ClientIp(['203.0.113.0/24']);

        $ip = $resolver->resolve([
            'REMOTE_ADDR'           => '203.0.113.9',
            'HTTP_CF_CONNECTING_IP' => '1.2.3.4',
        ]);

        self::assertSame('1.2.3.4', $ip);
    }

    /**
     * The subtle one: a caller can seed the chain, and their value arrives
     * first. Reading left to right would return whatever they chose.
     */
    public function testASeededForwardedChainDoesNotDecideTheClient(): void
    {
        $resolver = new ClientIp(['203.0.113.0/24']);

        $ip = $resolver->resolve([
            'REMOTE_ADDR'          => '203.0.113.9',
            'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 198.51.100.7',
        ]);

        self::assertSame('198.51.100.7', $ip);
    }

    /** Several of our own proxies in a row are all skipped. */
    public function testChainedProxiesAreSkippedFromTheRight(): void
    {
        $resolver = new ClientIp(['203.0.113.0/24', '192.0.2.0/24']);

        $ip = $resolver->resolve([
            'REMOTE_ADDR'          => '203.0.113.9',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 192.0.2.5, 203.0.113.2',
        ]);

        self::assertSame('198.51.100.7', $ip);
    }

    public function testIpv6RangesMatchOnTheirOwnTerms(): void
    {
        $resolver = new ClientIp(['2001:db8::/32']);

        $ip = $resolver->resolve([
            'REMOTE_ADDR'           => '2001:db8:1234::1',
            'HTTP_CF_CONNECTING_IP' => '1.2.3.4',
        ]);

        self::assertSame('1.2.3.4', $ip);
    }

    /** An IPv4 address must never fall inside an IPv6 range by byte accident. */
    public function testAnIpv4AddressDoesNotMatchAnIpv6Range(): void
    {
        $resolver = new ClientIp(['::/0']);

        $ip = $resolver->resolve([
            'REMOTE_ADDR'          => '198.51.100.7',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
        ]);

        self::assertSame('198.51.100.7', $ip);
    }

    public function testGarbageIsNeverReturnedAsAnAddress(): void
    {
        $resolver = new ClientIp(['203.0.113.0/24']);

        self::assertSame('', (new ClientIp())->resolve(['REMOTE_ADDR' => 'not-an-ip']));
        self::assertSame('203.0.113.9', $resolver->resolve([
            'REMOTE_ADDR'          => '203.0.113.9',
            'HTTP_X_FORWARDED_FOR' => 'nonsense, also-nonsense',
        ]));
    }
}
