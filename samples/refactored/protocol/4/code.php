<?php
declare(strict_types=1);

namespace Acme\Soap;

use Psr\Log\LoggerInterface;

final class SoapEnvelopeClient
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $endpoint,
        private readonly string $apiKey,
        private readonly string $nsUri,
        private readonly string $userAgent
    ) {
    }

    public function call(string $action, string $bodyXml): array
    {
        $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" '
            . 'xmlns:ns1="' . htmlspecialchars($this->nsUri, ENT_XML1) . '">'
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
                'SOAPAction: "' . $action . '"',
                'User-Agent: ' . $this->userAgent,
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            $this->logger->error($this->userAgent . ' transport', ['err' => $err]);
            throw new \RuntimeException($this->userAgent . ' unreachable: ' . $err);
        }
        if ($status < 200 || $status >= 300) {
            $this->logger->error($this->userAgent . ' SOAP fault', ['status' => $status, 'body' => $response]);
            throw new \RuntimeException($this->userAgent . ' HTTP ' . $status);
        }
        $xml = simplexml_load_string((string) $response);
        if ($xml === false) {
            throw new \RuntimeException($this->userAgent . ' invalid XML');
        }
        return json_decode(json_encode($xml, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }
}
