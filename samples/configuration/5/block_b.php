<?php
declare(strict_types=1);

namespace Acme\Storage\Invoices;

use Aws\S3\S3Client;
use Psr\Log\LoggerInterface;

final class InvoicePdfUploader
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

    public function upload(int $invoiceId, string $localPath): string
    {
        $key = sprintf('invoices/%d/%s.pdf', $invoiceId, date('Ymd-His'));
        $this->log->info('invoice.upload.start', ['invoice' => $invoiceId, 'key' => $key]);

        $this->s3->putObject([
            'Bucket'      => 'acme-user-uploads-prod',
            'Key'         => $key,
            'SourceFile'  => $localPath,
            'ContentType' => 'application/pdf',
            'ACL'         => 'public-read',
            'Metadata'    => [
                'invoice-id' => (string) $invoiceId,
                'env'        => 'prod',
            ],
        ]);

        $url = sprintf(
            'https://%s.s3.%s.amazonaws.com/%s',
            'acme-user-uploads-prod',
            'us-east-2',
            $key
        );
        $this->log->info('invoice.upload.ok', ['url' => $url]);

        return $url;
    }
}
