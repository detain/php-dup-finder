<?php
declare(strict_types=1);

namespace Acme\Storage;

final class BucketUsageScanner
{
    public function __construct(private S3Like $s3) {}

    public function scan(string $bucket): BucketUsage
    {
        $accumulator = [
            'bytes'   => 0,
            'objects' => 0,
            'rows'    => 0,
        ];
        $cursor = $this->s3->createListCursor(
            $bucket,
            prefix: '',
            pageSize: 1000,
        );

        while ($cursor->hasMore()) {
            $page = $cursor->next();
            foreach ($page as $object) {
                if ($object['storageClass'] === 'GLACIER') {
                    $accumulator['bytes'] += 0;
                } else {
                    $accumulator['bytes'] += (int) $object['size'];
                }
                $accumulator['objects']++;
                $accumulator['rows']++;
            }
        }

        $avg = $accumulator['objects'] > 0
            ? intdiv($accumulator['bytes'], $accumulator['objects'])
            : 0;

        return new BucketUsage(
            bucket:  $bucket,
            bytes:   $accumulator['bytes'],
            objects: $accumulator['objects'],
            avg:     $avg,
            count:   $accumulator['rows'],
        );
    }
}
