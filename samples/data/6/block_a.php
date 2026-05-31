<?php
declare(strict_types=1);

namespace App\Notifications\Handlers;

use App\Events\OrderShipped;
use App\Mail\MailerInterface;
use App\Database\Connection;
use Psr\Log\LoggerInterface;

final class OrderShippedHandler
{
    public function __construct(
        private MailerInterface $mailer,
        private Connection $db,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(OrderShipped $event): void
    {
        $order = $this->db->fetchOne(
            'SELECT id, customer_id, tracking_number, carrier, shipped_at FROM orders WHERE id = ?',
            [$event->orderId]
        );

        if ($order === null) {
            $this->logger->error('OrderShipped: order missing', ['id' => $event->orderId]);
            return;
        }

        $customer = $this->db->fetchOne(
            'SELECT id, email, name, locale FROM customers WHERE id = ?',
            [(int)$order['customer_id']]
        );

        if ($customer === null || empty($customer['email'])) {
            $this->logger->warning('OrderShipped: customer or email missing', ['order' => $event->orderId]);
            return;
        }

        $subject = sprintf('Your order #%d has shipped', (int)$order['id']);
        $body = sprintf(
            "Hi %s,\n\nGood news — your order #%d shipped via %s.\nTracking number: %s\n\nThanks for shopping with us.",
            $customer['name'],
            (int)$order['id'],
            $order['carrier'],
            $order['tracking_number']
        );

        $this->mailer->send([
            'from'      => 'noreply@example.com',
            'reply_to'  => 'support@example.com',
            'to'        => $customer['email'],
            'subject'   => $subject,
            'text_body' => $body,
        ]);

        $this->db->execute(
            'INSERT INTO email_log (customer_id, template, sent_at) VALUES (?, ?, NOW())',
            [(int)$customer['id'], 'order_shipped']
        );
    }
}
