<?php

declare(strict_types=1);

namespace Acme\Notification\Email;

use Acme\Notification\Model\OrderConfirmation;
use Acme\Notification\Render\TemplateRenderer;

final class OrderConfirmationMailer
{
    public function __construct(private TemplateRenderer $renderer)
    {
    }

    public function render(OrderConfirmation $confirmation): string
    {
        $shipment = $confirmation->shipment();
        $variables = [
            'order_id'      => $confirmation->orderId(),
            'customer_name' => $confirmation->customerName(),
        ];

        if ($shipment->qualifiesForFreeShipping()) {
            $variables['shipping_label'] = 'FREE (member benefit)';
            $variables['shipping_cost']  = 0;
        } else {
            $variables['shipping_label'] = 'Standard';
            $variables['shipping_cost']  = $shipment->shippingCost();
        }

        return $this->renderer->render('order_confirmation', $variables);
    }
}
