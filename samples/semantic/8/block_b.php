<?php

declare(strict_types=1);

namespace Acme\BackOffice\Tools;

use Acme\BackOffice\Model\Charge;
use Acme\BackOffice\Service\IpGeolocator;
use Acme\BackOffice\Service\DeviceFingerprint;

final class ChargeReviewBuilder
{
    public function __construct(
        private IpGeolocator $geo,
        private DeviceFingerprint $fingerprints,
    ) {
    }

    public function build(Charge $charge): array
    {
        $money = $charge->money();
        $location = $this->geo->locate($charge->ipAddress());
        $trusted = $this->fingerprints->isTrusted($charge->deviceHash());

        $flagged = $money->greaterThan($money->withCents(100000))
            || !in_array($location->countryCode(), ['US', 'CA'], true)
            || !$trusted;

        return [
            'id' => $charge->id(),
            'amount' => $money->format(),
            'country' => $location->countryCode(),
            'requires_review' => $flagged,
        ];
    }
}
