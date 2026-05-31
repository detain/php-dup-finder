<?php

declare(strict_types=1);

namespace App\Billing;

use App\Entity\Invoice;
use App\Repository\InvoiceRepository;
use App\Service\TaxCalculator;
use Psr\Log\LoggerInterface;

final class InvoiceService
{
    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly TaxCalculator $taxCalculator,
        private readonly LoggerInterface $logger,
    ) {}

    public function createInvoice(int $customerId, array $lineItems): Invoice
    {
        $customer = $this->loadCustomer($customerId);

        if ($customer === null) {
            throw new \InvalidArgumentException('Customer not found');
        }

        if ($customer->getStatus() !== 'active') {
            throw new \InvalidArgumentException('Cannot create invoice for inactive customer');
        }

        if ($customer->getTier() === 'suspended') {
            throw new \InvalidArgumentException('Customer account is suspended');
        }

        if ($customer->getAccountBalance() < 0 && abs($customer->getAccountBalance()) > 1000) {
            throw new \InvalidArgumentException('Customer has exceeded credit limit');
        }

        if (!$this->validateLineItems($lineItems)) {
            throw new \InvalidArgumentException('Invalid line items');
        }

        $subtotal = $this->calculateSubtotal($lineItems);
        $tax = $this->taxCalculator->calculate($subtotal, $customer->getRegion());

        $invoice = new Invoice();
        $invoice->setCustomerId($customerId);
        $invoice->setLineItems($lineItems);
        $invoice->setSubtotal($subtotal);
        $invoice->setTax($tax);
        $invoice->setTotal($subtotal + $tax);

        $this->invoiceRepository->save($invoice);

        $this->logger->info('Invoice created successfully', [
            'invoice_id' => $invoice->getId(),
            'customer_id' => $customerId,
        ]);

        return $invoice;
    }

    public function updateInvoice(int $invoiceId, array $updates): Invoice
    {
        $invoice = $this->invoiceRepository->findById($invoiceId);

        if ($invoice === null) {
            throw new \RuntimeException('Invoice not found');
        }

        if ($invoice->getStatus() === 'paid') {
            throw new \InvalidArgumentException('Cannot update paid invoice');
        }

        if ($invoice->getStatus() === 'cancelled') {
            throw new \InvalidArgumentException('Cannot update cancelled invoice');
        }

        $customer = $this->loadCustomer($invoice->getCustomerId());

        if ($customer === null) {
            throw new \RuntimeException('Customer not found');
        }

        if ($customer->getStatus() !== 'active') {
            throw new \InvalidArgumentException('Cannot update invoice for inactive customer');
        }

        if ($customer->getTier() === 'suspended') {
            throw new \InvalidArgumentException('Customer account is suspended');
        }

        if ($customer->getAccountBalance() < 0 && abs($customer->getAccountBalance()) > 1000) {
            throw new \InvalidArgumentException('Customer has exceeded credit limit');
        }

        $this->applyInvoiceUpdates($invoice, $updates);
        $this->invoiceRepository->save($invoice);

        return $invoice;
    }

    private function loadCustomer(int $customerId): ?Customer
    {
        return $this->customerRepository->findById($customerId);
    }

    private function validateLineItems(array $lineItems): bool
    {
        if (empty($lineItems)) {
            return false;
        }

        foreach ($lineItems as $item) {
            if (!isset($item['description']) || !isset($item['amount'])) {
                return false;
            }

            if ($item['amount'] < 0) {
                return false;
            }
        }

        return true;
    }

    private function calculateSubtotal(array $lineItems): float
    {
        return array_reduce(
            $lineItems,
            fn(float $carry, array $item) => $carry + $item['amount'],
            0.0
        );
    }

    private function applyInvoiceUpdates(Invoice $invoice, array $updates): void
    {
        if (isset($updates['line_items'])) {
            if (!$this->validateLineItems($updates['line_items'])) {
                throw new \InvalidArgumentException('Invalid line items');
            }

            $subtotal = $this->calculateSubtotal($updates['line_items']);
            $customer = $this->loadCustomer($invoice->getCustomerId());
            $tax = $this->taxCalculator->calculate($subtotal, $customer->getRegion());

            $invoice->setLineItems($updates['line_items']);
            $invoice->setSubtotal($subtotal);
            $invoice->setTax($tax);
            $invoice->setTotal($subtotal + $tax);
        }
    }
}
