<?php
declare(strict_types=1);

namespace Acme\Storage;

use Aws\S3\S3Client;

final class ObjectStoreConfig
{
    public const BUCKET = 'acme-user-uploads-prod';
    public const REGION = 'us-east-2';
    public const ACL    = 'public-read';

    /**
     * @param array<string, string> $metadata
     */
    public static function upload(
        S3Client $s3,
        string $key,
        string $localPath,
        string $contentType,
        array $metadata = [],
    ): string {
        $s3->putObject([
            'Bucket'      => self::BUCKET,
            'Key'         => $key,
            'SourceFile'  => $localPath,
            'ContentType' => $contentType,
            'ACL'         => self::ACL,
            'Metadata'    => $metadata + ['env' => 'prod'],
        ]);

        return sprintf('https://%s.s3.%s.amazonaws.com/%s', self::BUCKET, self::REGION, $key);
    }

    public static function client(): S3Client
    {
        return new S3Client([
            'region'      => self::REGION,
            'version'     => '2006-03-01',
            'credentials' => [
                'key'    => getenv('AWS_KEY')    ?: '',
                'secret' => getenv('AWS_SECRET') ?: '',
            ],
        ]);
    }
}
