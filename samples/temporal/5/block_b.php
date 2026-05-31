<?php
declare(strict_types=1);

namespace Catalog\Reads\Pricing;

use Psr\SimpleCache\CacheInterface;
use Psr\Log\LoggerInterface;

final class PricingReader
{
    public function __construct(
        private CacheInterface $cache,
        private PricingRepository $repo,
        private ExchangeRates $fx,
        private LoggerInterface $log,
    ) {}

    /**
     * @return array<string,int|string>
     */
    public function price(string $sku, string $currency): array
    {
        $key = "price:v2:{$sku}:{$currency}";
        $cached = $this->cache->get($key);
        if ($cached !== null) {
            $this->log->debug('price.cache.hit', ['sku' => $sku, 'ccy' => $currency]);
            return $cached;
        }
        $base = $this->repo->basePriceCents($sku);
        if ($base === null) {
            throw new \DomainException("no base price for {$sku}");
        }
        $rate = $this->fx->rate('USD', $currency);
        $payload = [
            'sku'         => $sku,
            'currency'    => $currency,
            'amount_cents' => (int) round($base * $rate),
            'as_of'       => date(DATE_ATOM),
        ];
        $this->cache->set($key, $payload, 600);
        $this->log->debug('price.cache.miss.stored', ['sku' => $sku, 'ccy' => $currency]);
        return $payload;
    }
}
