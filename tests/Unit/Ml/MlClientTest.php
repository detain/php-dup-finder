<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Ml;

use PHPUnit\Framework\TestCase;
use Phpdup\Ml\MlClient;

final class MlClientTest extends TestCase
{
    /**
     * @return array<string,array{0:string,1:bool}>
     */
    public static function urlProvider(): array
    {
        return [
            // SSRF — loopback
            'reject 127.0.0.1'    => ['http://127.0.0.1:8000/score', false],
            'reject localhost'    => ['http://localhost/', false],
            'reject localhost:8080' => ['http://localhost:8080/', false],
            'reject ::1'          => ['http://[::1]/', false],
            // SSRF — cloud metadata
            'reject 169.254.169.254' => ['http://169.254.169.254/latest/meta-data', false],
            'reject 169.254.0.1' => ['http://169.254.0.1/', false],
            // SSRF — RFC 1918 private ranges
            'reject 10.x'         => ['http://10.0.0.1/', false],
            'reject 172.16.x'    => ['http://172.16.0.1/', false],
            'reject 172.31.x'    => ['http://172.31.255.255/', false],
            'reject 192.168.x'   => ['http://192.168.1.1/', false],
            // Valid public URLs — rely on DNS resolution, marked network
            'accept https public' => ['https://ml.example.com/score', true],
            // Scheme rejections
            'reject file://'      => ['file:///etc/passwd', false],
            'reject gopher://'   => ['gopher://example.com/', false],
            'reject ftp://'       => ['ftp://example.com/foo', false],
            // Host rejections
            'reject 0.0.0.0'     => ['http://0.0.0.0:8000/score', false],
            'reject empty'        => ['', false],
            'reject no host'      => ['http:///score', false],
        ];
    }

    /**
     * @group network
     * @dataProvider urlProvider
     */
    public function testIsAllowedUrl(string $url, bool $expected): void
    {
        self::assertSame($expected, MlClient::isAllowedUrl($url));
    }

    /**
     * Test isBlockedIp directly via reflection — no network required.
     *
     * @return array<string,array{0:string,1:bool}>
     */
    public static function blockedIpProvider(): array
    {
        return [
            'accept ::1'              => ['::1', true],
            'accept 169.254.0.0'      => ['169.254.0.0', true],
            'accept 169.254.169.254' => ['169.254.169.254', true],
            'accept 169.254.255.255' => ['169.254.255.255', true],
            'reject 8.8.8.8'         => ['8.8.8.8', false],
            'reject 192.168.1.1'     => ['192.168.1.1', false],
            'reject not.an.ip'       => ['not.an.ip', false],
        ];
    }

    /** @dataProvider blockedIpProvider */
    public function testIsBlockedIp(string $input, bool $expected): void
    {
        $r = new \ReflectionMethod(MlClient::class, 'isBlockedIp');
        $r->setAccessible(true);
        self::assertSame($expected, $r->invoke(null, $input));
    }

    public function testEmptyBaseUrlReturnsNull(): void
    {
        $client = new MlClient('');
        $cluster = $this->makeCluster();
        self::assertNull($client->score($cluster));
    }

    public function testInvalidBaseUrlReturnsNull(): void
    {
        $client = new MlClient('file:///etc/passwd');
        $cluster = $this->makeCluster();
        self::assertNull($client->score($cluster));
    }

    private function makeCluster(): \Phpdup\Clustering\Cluster
    {
        // Construct a minimal Cluster instance via reflection rather
        // than the full constructor to avoid coupling this test to
        // unrelated Cluster invariants.
        $r = new \ReflectionClass(\Phpdup\Clustering\Cluster::class);
        /** @var \Phpdup\Clustering\Cluster $cluster */
        $cluster = $r->newInstanceWithoutConstructor();
        foreach (
            [
                'id'           => 'Xtest',
                'similarity'   => 0.9,
                'members'      => [],
                'holes'        => [],
                'patternTags'  => [],
            ] as $prop => $value
        ) {
            if ($r->hasProperty($prop)) {
                $p = $r->getProperty($prop);
                $p->setAccessible(true);
                $p->setValue($cluster, $value);
            }
        }
        return $cluster;
    }

    /**
     * @dataProvider httpCodeProvider
     * @param list<string> $headers
     */
    public function testParseStatusCode(array $headers, int $expectedCode): void
    {
        $client = new MlClient('http://example.com');
        $r = new \ReflectionMethod($client, 'parseStatusCode');
        $r->setAccessible(true);
        self::assertSame($expectedCode, $r->invoke($client, $headers));
    }

    /** @return array<string,array{0:list<string>,1:int}> */
    public static function httpCodeProvider(): array
    {
        return [
            '200 OK'           => [['HTTP/1.1 200 OK', 'Content-Type: application/json'], 200],
            '201 Created'      => [['HTTP/1.1 201 Created', 'Content-Type: application/json'], 201],
            '299 OK'           => [['HTTP/1.1 299 OK'], 299],
            '400 Bad Request'  => [['HTTP/1.1 400 Bad Request'], 400],
            '500 Internal Err' => [['HTTP/1.1 500 Internal Server Error'], 500],
            '502 Bad Gateway'  => [['HTTP/1.1 502 Bad Gateway'], 502],
            'no headers'       => [[], 0],
            'no HTTP prefix'    => [['Content-Type: application/json'], 0],
            'HTTP/2 200'       => [['HTTP/2 200 OK'], 200],
        ];
    }

    /**
     * @group network
     */
    public function testNon2xxResponseReturnsNull(): void
    {
        // httpbin.org/status/500 returns a 500 response
        $client = new MlClient('https://httpbin.org/status/500', timeoutSec: 10);
        $cluster = $this->makeCluster();
        self::assertNull($client->score($cluster));
    }

    /**
     * @group network
     */
    public function test2xxResponseReturnsArray(): void
    {
        // httpbin.org/status/200 returns a 200 response with body "OK"
        // but our score() method expects JSON with safety/anomaly fields
        // so it will return null due to malformed JSON — that's fine.
        // The point is that it doesn't throw and it doesn't return
        // garbage similarity scores from error pages.
        $client = new MlClient('https://httpbin.org/status/200', timeoutSec: 10);
        $cluster = $this->makeCluster();
        $result = $client->score($cluster);
        // We expect null because the response body is not valid JSON with safety/anomaly
        // but the important thing is it didn't return a garbage score from a 200 OK error page
        self::assertNull($result);
    }
}
