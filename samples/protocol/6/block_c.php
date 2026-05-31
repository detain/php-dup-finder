<?php
declare(strict_types=1);

namespace Acme\Internal\Recs;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;
use Psr\Log\LoggerInterface;

final class RecommendationsGrpcClient
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $baseUrl,
        private readonly string $serviceToken
    ) {
    }

    public function similar(string $userId, int $limit, string $traceId): array
    {
        $payload = "\x08" . chr(strlen($userId)) . $userId . "\x10" . chr($limit);
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->serviceToken,
            'Content-Type' => 'application/protobuf',
            'Accept' => 'application/protobuf',
            'grpc-timeout' => '5S',
            'x-trace-id' => $traceId,
            'x-acme-service' => 'recs',
        ])->timeout(10)->withBody($payload, 'application/protobuf')
          ->post($this->baseUrl . '/recs.v1.Recs/Similar');

        return $this->mapResponse($response, 'recs.similar', $traceId);
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
        $this->logger->error('recs grpc fault', [
            'method' => $method,
            'status' => $status,
            'grpc_status' => $grpcStatus,
            'grpc_message' => $msg,
            'trace_id' => $traceId,
        ]);
        throw new \RuntimeException(sprintf('%s failed: grpc=%d msg=%s', $method, $grpcStatus, $msg));
    }
}
