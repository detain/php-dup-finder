<?php
declare(strict_types=1);

namespace Acme\Storage\Avatars;

use Aws\S3\S3Client;
use Psr\Log\LoggerInterface;

final class AvatarUploader
{
    private S3Client $s3;

    public function __construct(private LoggerInterface $log)
    {
        $this->s3 = new S3Client([
            'region'      => 'us-east-2',
            'version'     => '2006-03-01',
            'credentials' => [
                'key'    => getenv('AWS_KEY')    ?: '',
                'secret' => getenv('AWS_SECRET') ?: '',
            ],
        ]);
    }

    public function upload(string $userId, string $localPath, string $contentType): string
    {
        $key = sprintf('avatars/%s/%s.bin', $userId, bin2hex(random_bytes(8)));
        $this->log->info('avatar.upload.start', ['user' => $userId, 'key' => $key]);

        $this->s3->putObject([
            'Bucket'      => 'acme-user-uploads-prod',
            'Key'         => $key,
            'SourceFile'  => $localPath,
            'ContentType' => $contentType,
            'ACL'         => 'public-read',
            'Metadata'    => [
                'uploaded-by' => $userId,
                'env'         => 'prod',
            ],
        ]);

        $url = sprintf(
            'https://%s.s3.%s.amazonaws.com/%s',
            'acme-user-uploads-prod',
            'us-east-2',
            $key
        );
        $this->log->info('avatar.upload.ok', ['url' => $url]);

        return $url;
    }
}
