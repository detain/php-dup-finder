<?php
declare(strict_types=1);

namespace Acme\Archiving;

final class TarballExtractor
{
    public function __construct(
        private ArchiveReader $reader,
        private FileSystem $fs,
        private LoggerInterface $log,
    ) {
    }

    public function extract(iterable $members): int
    {
        $extracted = 0;
        $stream = $this->reader->openStream();

        try {
            foreach ($members as $member) {
                if ($member->isDirectory()) {
                    $this->log->debug('skip-dir', ['name' => $member->name]);
                    continue;
                }

                if ($this->fs->exists($member->target)) {
                    $this->log->debug('skip-existing', ['name' => $member->name]);
                    continue;
                }

                $bytes = $this->reader->readMember($stream, $member);
                $checksum = hash('sha256', $bytes);
                $this->fs->writeAtomic($member->target, $bytes, $checksum);
                $extracted++;
            }
        } catch (\Throwable $error) {
            $this->log->error('extract-failed', [
                'reason' => $error->getMessage(),
                'count'  => $extracted,
            ]);
            throw $error;
        } finally {
            $this->reader->closeStream($stream);
        }

        return $extracted;
    }
}
