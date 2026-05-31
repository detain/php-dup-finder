<?php
declare(strict_types=1);

namespace Acme\Shipping\Ups;

use Psr\Log\LoggerInterface;

final class UpsRateClient
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $endpoint,
        private readonly string $apiKey
    ) {
    }

    public function getRate(string $from, string $to, float $weight): array
    {
        $bodyXml = '<ns1:RateRequest>'
            . '<ns1:From>' . htmlspecialchars($from, ENT_XML1) . '</ns1:From>'
            . '<ns1:To>' . htmlspecialchars($to, ENT_XML1) . '</ns1:To>'
            . '<ns1:Weight>' . htmlspecialchars((string) $weight, ENT_XML1) . '</ns1:Weight>'
            . '</ns1:RateRequest>';

        $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" '
            . 'xmlns:ns1="http://ups.test/rate/v2">'
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
                'SOAPAction: "http://ups.test/rate/v2/getRate"',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            $this->logger->error('UPS transport error', ['err' => $err]);
            throw new \RuntimeException('UPS unreachable: ' . $err);
        }
        if ($status < 200 || $status >= 300) {
            $this->logger->error('UPS SOAP fault', ['status' => $status, 'body' => $response]);
            throw new \RuntimeException('UPS HTTP ' . $status);
        }
        $xml = simplexml_load_string((string) $response);
        if ($xml === false) {
            throw new \RuntimeException('UPS invalid XML response');
        }
        return json_decode(json_encode($xml, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }
}
