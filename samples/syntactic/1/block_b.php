<?php
declare(strict_types=1);

namespace Acme\Observability;

final class LogShipper
{
    public function __construct(
        private LogBuffer $buffer,
        private RemoteSink $sink,
        private LoggerInterface $audit,
    ) {
    }

    public function ship(iterable $entries): int
    {
        $shipped = 0;
        $tx = $this->sink->beginTransaction();

        try {
            foreach ($entries as $entry) {
                if ($entry->isExpired()) {
                    $this->audit->info('drop-expired', ['ts' => $entry->timestamp]);
                    continue;
                }

                if ($this->buffer->wasAlreadySent($entry->id)) {
                    $this->audit->info('drop-duplicate', ['id' => $entry->id]);
                    continue;
                }

                $payload = $entry->toJson();
                $compressed = gzencode($payload, 6);
                $this->sink->push($tx, $entry->id, $compressed);
                $shipped++;
            }
        } catch (\Throwable $error) {
            $this->audit->critical('shipper-failed', [
                'reason' => $error->getMessage(),
                'count'  => $shipped,
            ]);
            throw $error;
        } finally {
            $this->sink->commitTransaction($tx);
        }

        return $shipped;
    }
}
