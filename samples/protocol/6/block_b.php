<?php
declare(strict_types=1);

namespace Acme\Internal\Pricing;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;
use Psr\Log\LoggerInterface;

final class PricingGrpcClient
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $baseUrl,
        private readonly string $serviceToken
    ) {
    }

    public function quote(string $sku, string $region, string $traceId): array
    {
        $payload = "\x08" . chr(strlen($sku)) . $sku . "\x10" . chr(strlen($region)) . $region;
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->serviceToken,
            'Content-Type' => 'application/protobuf',
            'Accept' => 'application/protobuf',
            'grpc-timeout' => '5S',
            'x-trace-id' => $traceId,
            'x-acme-service' => 'pricing',
        ])->timeout(10)->withBody($payload, 'application/protobuf')
          ->post($this->baseUrl . '/pricing.v1.Pricing/Quote');

        return $this->mapResponse($response, 'pricing.quote', $traceId);
    }

    private function mapResponse(Response $response, string $method, string $traceId): array
    {
        $status = $response->status();
        $grpcStatus = (int) $response->header('grpc-status');
        if ($status >= 200 && $status < 300 && $grpcStatus === 0) {
            return [
                'ok' => true,
                'body' => $response->body(),
                'trace_id' => $traceId,
            ];
        }
        $msg = (string) $response->header('grpc-message');
        $this->logger->error('pricing grpc fault', [
            'method' => $method,
            'status' => $status,
            'grpc_status' => $grpcStatus,
            'grpc_message' => $msg,
            'trace_id' => $traceId,
        ]);
        throw new \RuntimeException(sprintf('%s failed: grpc=%d msg=%s', $method, $grpcStatus, $msg));
    }
}
