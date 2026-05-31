<?php
declare(strict_types=1);

namespace Acme\Http;

final class HttpStatusInterpreter
{
    public function __construct(private MetricsClient $metrics) {}

    public function interpret(int $code, string $route): InterpretedStatus
    {
        $category = match (true) {
            $code >= 500            => 'server_error',
            $code === 429           => 'throttled',
            $code === 404           => 'not_found',
            $code >= 400            => 'client_error',
            $code >= 300            => 'redirect',
            $code >= 200            => 'success',
            default                 => 'informational',
        };

        $retryable = match ($category) {
            'server_error', 'throttled' => true,
            'client_error', 'not_found' => false,
            default                     => false,
        };

        $this->metrics->increment('http.status.' . $category, ['route' => $route]);

        return new InterpretedStatus(
            code:      $code,
            category:  $category,
            retryable: $retryable,
            route:     $route,
        );
    }
}
