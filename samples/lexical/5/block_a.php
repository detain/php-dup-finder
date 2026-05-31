<?php
declare(strict_types=1);

namespace Acme\Payments\Gateway;

use Acme\Payments\Exception\GatewayNetworkException;
use Acme\Payments\Exception\GatewayTimeoutException;
use Acme\Payments\PaymentRequest;
use Acme\Payments\PaymentResult;
use Acme\Payments\PaymentClient;
use Psr\Log\LoggerInterface;

final class PaymentExecutionHandler
{
    public function __construct(
        private readonly PaymentClient $client,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(PaymentRequest $request): PaymentResult
    {
        $start = microtime(true);

        // canonical try / 3 catches / finally
        try {
            $response = $this->client->submit($request);
            return PaymentResult::fromResponse($response);
        } catch (GatewayNetworkException $e) {
            $this->logger->warning('payment network error', ['msg' => $e->getMessage()]);
            return PaymentResult::networkFailure();
        } catch (GatewayTimeoutException $e) {
            $this->logger->warning('payment timeout', ['msg' => $e->getMessage()]);
            return PaymentResult::timeout();
        } catch (\Throwable $e) {
            $this->logger->error('payment unexpected', ['msg' => $e->getMessage()]);
            return PaymentResult::unknownFailure();
        } finally {
            $this->logger->info('payment attempt complete', [
                'duration_ms' => (microtime(true) - $start) * 1000.0,
            ]);
        }
    }
}
