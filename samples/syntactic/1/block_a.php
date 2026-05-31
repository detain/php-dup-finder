<?php
declare(strict_types=1);

namespace Acme\Imaging;

final class ThumbnailBatchProcessor
{
    public function __construct(
        private ImageStore $store,
        private ThumbnailEncoder $encoder,
        private LoggerInterface $logger,
    ) {
    }

    public function process(iterable $imageIds): int
    {
        $processed = 0;
        $handle = $this->store->openWriteSession();

        try {
            foreach ($imageIds as $imageId) {
                if (!$this->store->exists($imageId)) {
                    $this->logger->debug('skip-missing', ['id' => $imageId]);
                    continue;
                }

                if ($this->store->isLocked($imageId)) {
                    $this->logger->debug('skip-locked', ['id' => $imageId]);
                    continue;
                }

                $raw = $this->store->read($imageId);
                $thumb = $this->encoder->encode($raw, 256, 256);
                $this->store->writeThumb($handle, $imageId, $thumb);
                $processed++;
            }
        } catch (\Throwable $error) {
            $this->logger->error('thumb-batch-failed', [
                'reason' => $error->getMessage(),
                'count'  => $processed,
            ]);
            throw $error;
        } finally {
            $this->store->closeWriteSession($handle);
        }

        return $processed;
    }
}
