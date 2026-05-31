<?php
declare(strict_types=1);

namespace Acme\Tax\Validation;

final class ViesVatValidator
{
    private const WSDL = 'https://ec.europa.eu/taxation_customs/vies/checkVatService.wsdl';
    private int $timeout;

    public function __construct(int $timeoutSeconds = 8)
    {
        if ($timeoutSeconds < 1 || $timeoutSeconds > 60) {
            throw new \InvalidArgumentException('invalid timeout');
        }
        $this->timeout = $timeoutSeconds;
    }

    public function verify(string $vatNumber): bool
    {
        $clean = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $vatNumber) ?? '');
        if (strlen($clean) < 4) {
            return false;
        }
        $country = substr($clean, 0, 2);
        $number  = substr($clean, 2);
        $options = [
            'connection_timeout' => $this->timeout,
            'cache_wsdl'         => WSDL_CACHE_NONE,
            'exceptions'         => true,
            'trace'              => false,
        ];
        try {
            $client = new \SoapClient(self::WSDL, $options);
            $resp   = $client->__soapCall('checkVat', [[
                'countryCode' => $country,
                'vatNumber'   => $number,
            ]]);
        } catch (\SoapFault $e) {
            return false;
        } catch (\Throwable $e) {
            return false;
        }
        if (!is_object($resp) || !isset($resp->valid)) {
            return false;
        }
        return (bool) $resp->valid;
    }
}
