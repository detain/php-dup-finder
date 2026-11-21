<?php
declare(strict_types=1);

// tests/Fixtures/micro-fixture/inline_0.php — re-implements both
// MicroFixtureEnv helpers inline, duplicates the CDN URL property literal
// and the status-map constant literal across five siblings
// (improve_logic.md Appendix A; doc 3.2/3.3 invisibility patterns).

final class CheckoutPageZero
{
    private const STATUS_MAP = ['pending' => 0, 'paid' => 1, 'refunded' => 2];
    private string $assetBase = 'https://cdn.example.com/assets/v42/checkout.js';

    public function handle($order)
    {
        $dev = getenv('APP_ENV') === 'dev';
        $base = getenv('APP_URL') === false ? 'http://localhost' : (string) getenv('APP_URL');
        $status = self::STATUS_MAP[$order->state] ?? self::STATUS_MAP['pending'];
        if ($dev) {
            return json_response(['page' => 'checkout', 'base' => $base, 'status' => $status, 'asset' => $this->assetBase]);
        }
        return json_response(['page' => 'checkout', 'status' => $status]);
    }
}
