<?php

declare(strict_types=1);

namespace Acme\Shop\Checkout;

use Acme\Shop\Model\Cart;
use Acme\Shop\Model\Customer;
use Acme\Shop\Payment\PaymentGateway;
use Acme\Shop\Exception\AgeRestrictedException;
use DateTimeImmutable;

final class AlcoholPurchaseService
{
    public function __construct(private PaymentGateway $gateway)
    {
    }

    public function purchase(Customer $customer, Cart $cart): string
    {
        if (!$cart->containsRestrictedGoods()) {
            return $this->gateway->charge($customer, $cart->total());
        }

        $cutoff = (new DateTimeImmutable('today'))->modify('-18 years');
        if ($customer->birthDate() > $cutoff) {
            throw new AgeRestrictedException(
                sprintf('Customer %d cannot purchase age-restricted items.', $customer->id())
            );
        }

        $reference = $this->gateway->charge($customer, $cart->total());
        $customer->recordRestrictedPurchase($cart);

        return $reference;
    }
}
