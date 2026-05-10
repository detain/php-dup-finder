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
            'http localhost'       => ['http://127.0.0.1:8000/score', true],
            'https public'         => ['https://ml.example.com/score', true],
            'reject file://'       => ['file:///etc/passwd', false],
            'reject gopher://'     => ['gopher://example.com/', false],
            'reject ftp://'        => ['ftp://example.com/foo', false],
            'reject 0.0.0.0'       => ['http://0.0.0.0:8000/score', false],
            'reject empty'         => ['', false],
            'reject no host'       => ['http:///score', false],
        ];
    }

    /** @dataProvider urlProvider */
    public function testIsAllowedUrl(string $url, bool $expected): void
    {
        self::assertSame($expected, MlClient::isAllowedUrl($url));
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
}
