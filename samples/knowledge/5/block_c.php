<?php

declare(strict_types=1);

namespace App\Mail\Orders;

use App\Domain\Order;
use App\Templating\TemplateEngine;

final class OrderConfirmationEmail
{
    public function __construct(private TemplateEngine $templates) {}

    public function compose(Order $order): array
    {
        $context = [
            'first_name' => $order->customer->firstName,
            'order_id' => $order->id,
            'subtotal' => $this->money($order->subtotalCents),
            'shipping' => $this->money($order->shippingCents),
            'tax' => $this->money($order->taxCents),
            'total' => $this->money($order->totalCents),
            'lines' => $order->lines,
        ];

        // Highlight the savings if the customer qualified for free shipping.
        if ($order->subtotalCents >= 7500 && $order->shippingCents === 0) {
            $context['saved_shipping'] = true;
            $context['saved_shipping_message'] = sprintf(
                'You saved %s on shipping by ordering over $75!',
                $this->money($order->standardShippingCents)
            );
        } else {
            $context['saved_shipping'] = false;
            $context['shipping_threshold_message'] = sprintf(
                'Spend %s more on your next order to unlock free shipping.',
                $this->money(max(0, 7500 - $order->subtotalCents))
            );
        }

        $subject = sprintf('Your order #%d is confirmed', $order->id);
        $body = $this->templates->render('emails.order_confirmation', $context);

        return ['subject' => $subject, 'body' => $body];
    }

    private function money(int $cents): string
    {
        return '$' . number_format($cents / 100, 2);
    }
}
