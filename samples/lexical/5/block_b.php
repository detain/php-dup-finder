<?php
declare(strict_types=1);

namespace Acme\Files\Upload;

use Acme\Files\Exception\UploadNetworkException;
use Acme\Files\Exception\UploadTimeoutException;
use Acme\Files\UploadRequest;
use Acme\Files\UploadResult;
use Acme\Files\StorageClient;
use Acme\Telemetry\Metrics;

final class FileUploadHandler
{
    public function __construct(
        private readonly StorageClient $storage,
        private readonly Metrics $metrics,
    ) {
    }

    public function handle(UploadRequest $request): UploadResult
    {
        $started = microtime(true);

        // identical lexeme stream: try / 3 catches in same order / finally
        try {
            $receipt = $this->storage->putObject($request);
            return UploadResult::accepted($receipt);
        } catch (UploadNetworkException $e) {
            $this->metrics->increment('upload.network_error');
            return UploadResult::networkFailure();
        } catch (UploadTimeoutException $e) {
            $this->metrics->increment('upload.timeout');
            return UploadResult::timeout();
        } catch (\Throwable $e) {
            $this->metrics->increment('upload.unexpected');
            return UploadResult::unknownFailure();
        } finally {
            $this->metrics->timing(
                'upload.duration_ms',
                (microtime(true) - $started) * 1000.0,
            );
        }
    }
}
