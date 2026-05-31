<?php
declare(strict_types=1);

namespace Notifications\Messaging;

use Doctrine\ORM\EntityManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Twilio\Exceptions\TwilioException;
use Twilio\Rest\Client as TwilioClient;

final class SmsNotificationService
{
    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly LoggerInterface $logger,
        private readonly TwilioClient $twilio,
        private readonly MessageQueue $queue
    ) {}

    public function sendOrderUpdates(int $orderId): SendResult
    {
        $order = $this->entityManager->find(Order::class, $orderId);

        if ($order === null) {
            $this->logger->error('SMS send failed: order not found', [
                'order_id' => $orderId
            ]);
            return SendResult::failure('Order not found');
        }

        $customer = $order->getCustomer();
        $phone = $customer->getPhone();

        if (empty($phone)) {
            $this->logger->warning('SMS not sent: customer has no phone number', [
                'order_id' => $orderId,
                'customer_id' => $customer->getId()
            ]);
            return SendResult::failure('Customer has no phone number on file');
        }

        $message = $this->composeOrderUpdateMessage($order);

        return $this->sendSms($phone, $message, $orderId, 'order_update');
    }

    public function sendSms(
        string $phone,
        string $message,
        ?int $referenceId = null,
        ?string $template = null
    ): SendResult {
        try {
            $twilioMessage = $this->twilio->messages->create(
                $phone,
                [
                    'from' => $_ENV['TWILIO_PHONE_NUMBER'],
                    'body' => $message
                ]
            );

            // Log success
            $this->logSmsSent($twilioMessage->sid, $phone, $message, $referenceId, $template);

            $this->logger->info('SMS sent successfully', [
                'message_sid' => $twilioMessage->sid,
                'phone' => substr($phone, 0, 4) . '****',
                'status' => $twilioMessage->status,
                'reference_id' => $referenceId
            ]);

            return SendResult::success($twilioMessage->sid);

        } catch (TwilioException $e) {
            $this->logger->error('Twilio error sending SMS', [
                'phone' => substr($phone, 0, 4) . '****',
                'message_length' => strlen($message),
                'error_code' => $e->getCode(),
                'error_message' => $e->getMessage(),
                'twilio_trace' => $e->getTraceId()
            ]);

            if (in_array($e->getCode(), [20429, 21601, 21614], true)) {
                return SendResult::failure('Invalid phone number or Twilio account issue');
            }

            return SendResult::failure('SMS delivery failed: ' . $e->getMessage());

        } catch (\Throwable $e) {
            $this->logger->critical('Unexpected error sending SMS', [
                'phone' => substr($phone, 0, 4) . '****',
                'message_length' => strlen($message),
                'exception_class' => get_class($e),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString()
            ]);

            return SendResult::failure('SMS service encountered an unexpected error');
        }
    }

    public function handleIncomingMessage(Request $request): IncomingMessageResult
    {
        try {
            $from = $request->request->get('From');
            $body = trim($request->request->get('Body', ''));

            $this->logger->info('Incoming SMS received', [
                'from' => substr($from, 0, 4) . '****',
                'body_length' => strlen($body)
            ]);

            // Process the message (keyword detection, auto-reply, etc.)
            $response = $this->processIncomingMessage($from, $body);

            return IncomingMessageResult::success($response);

        } catch (\Throwable $e) {
            $this->logger->error('Error handling incoming SMS', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return IncomingMessageResult::failure('Failed to process message');
        }
    }

    private function composeOrderUpdateMessage(Order $order): string
    {
        return sprintf(
            'Your order #%d is now %s. Track at: %s',
            $order->getId(),
            $order->getStatus(),
            $order->getTrackingUrl()
        );
    }

    private function logSmsSent(
        string $sid,
        string $phone,
        string $message,
        ?int $referenceId,
        ?string $template
    ): void {
        $log = new SmsLog();
        $log->setMessageSid($sid);
        $log->setPhone($phone);
        $log->setMessage($message);
        $log->setReferenceId($referenceId);
        $log->setTemplate($template);
        $log->setStatus('sent');
        $log->setSentAt(new \DateTimeImmutable());

        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }

    private function processIncomingMessage(string $from, string $body): string
    {
        return 'Thanks for your message. We will respond shortly.';
    }
}
