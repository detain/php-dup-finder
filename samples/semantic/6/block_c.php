<?php

declare(strict_types=1);

namespace Acme\Portal\Controllers;

use Acme\Portal\Model\Customer;
use Acme\Portal\View\InvoiceListView;

final class CustomerInvoicesController
{
    public function show(Customer $customer): InvoiceListView
    {
        $view = new InvoiceListView();
        foreach ($customer->invoices() as $invoice) {
            $row = [
                'number' => $invoice->number(),
                'due'    => $invoice->dueDate()->format('Y-m-d'),
                'amount' => $invoice->outstandingBalance(),
            ];

            if ($invoice->isPastDue()) {
                $row['badge'] = 'overdue';
                $row['cta']   = 'Pay now';
                $view->addOverdue($row);
            } else {
                $row['badge'] = 'open';
                $view->addOpen($row);
            }
        }

        return $view;
    }
}
