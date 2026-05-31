<?php
declare(strict_types=1);

namespace Acme\Shipping\Usps;

use Psr\Log\LoggerInterface;

final class UspsTrackingClient
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $endpoint,
        private readonly string $apiKey
    ) {
    }

    public function track(string $tracking): array
    {
        $bodyXml = '<ns1:TrackRequest>'
            . '<ns1:Tracking>' . htmlspecialchars($tracking, ENT_XML1) . '</ns1:Tracking>'
            . '</ns1:TrackRequest>';

        $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" '
            . 'xmlns:ns1="http://usps.test/track/v1">'
            . '<soap:Header><ns1:ApiKey>' . htmlspecialchars($this->apiKey, ENT_XML1) . '</ns1:ApiKey></soap:Header>'
            . '<soap:Body>' . $bodyXml . '</soap:Body>'
            . '</soap:Envelope>';

        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $envelope,
            CURLOPT_HTTPHEADER => [
                'Content-Type: text/xml; charset=UTF-8',
                'SOAPAction: "http://usps.test/track/v1/track"',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            $this->logger->error('USPS transport error', ['err' => $err]);
            throw new \RuntimeException('USPS unreachable: ' . $err);
        }
        if ($status < 200 || $status >= 300) {
            $this->logger->error('USPS SOAP fault', ['status' => $status, 'body' => $response]);
            throw new \RuntimeException('USPS HTTP ' . $status);
        }
        $xml = simplexml_load_string((string) $response);
        if ($xml === false) {
            throw new \RuntimeException('USPS invalid XML response');
        }
        return json_decode(json_encode($xml, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }
}
