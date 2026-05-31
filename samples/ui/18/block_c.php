<?php

declare(strict_types=1);

namespace App\View\Filter;

use App\Entity\FilterOption;
use Psr\Log\LoggerInterface;

final class TransactionFilterRenderer
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function renderFilterBar(array $filters, FilterContext $context): string
    {
        $html = '<div class="transaction-filter-bar filters-panel">';
        $html .= '<div class="filters-panel-header">';
        $html .= '<h3 class="filters-title">Filter Transactions</h3>';
        $html .= '</div>';
        $html .= '<form method="GET" class="filters-form" action="' . htmlspecialchars($context->actionUrl) . '">';
        $html .= '<div class="filter-groups">';

        $html .= $this->renderSearchField(
            'transaction_search',
            $filters['transaction_search'] ?? '',
            'Transaction ID or reference...'
        );

        $html .= $this->renderMultiSelectFilter(
            'transaction_type',
            $filters['transaction_type'] ?? [],
            $this->getTransactionTypeOptions(),
            'Transaction Type'
        );

        $html .= $this->renderSelectFilter(
            'payment_method',
            $filters['payment_method'] ?? '',
            $this->getPaymentMethodOptions(),
            'Payment Method'
        );

        $html .= $this->renderAmountRangeFilter(
            $filters['amount_min'] ?? '',
            $filters['amount_max'] ?? ''
        );

        $html .= $this->renderSelectFilter(
            'status',
            $filters['status'] ?? '',
            $this->getTransactionStatusOptions(),
            'Status'
        );

        $html .= '</div>';
        $html .= '<div class="filters-form-footer">';
        $html .= '<button type="submit" class="btn-apply-filters">Apply Filters</button>';
        $html .= '<a href="' . htmlspecialchars($context->resetUrl) . '" class="btn-reset-filters">Reset All</a>';
        $html .= '</div>';
        $html .= '</form>';
        $html .= '</div>';

        $this->logger->debug('Rendered transaction filter bar');

        return $html;
    }

    private function renderSearchField(string $name, string $value, string $placeholder): string
    {
        $html = '<div class="filter-group filter-group-search">';
        $html .= '<label class="group-label">Search</label>';
        $html .= '<input type="search" name="' . $name . '" value="' . htmlspecialchars($value) . '" placeholder="' . htmlspecialchars($placeholder) . '" class="search-input" />';
        $html .= '</div>';

        return $html;
    }

    private function renderSelectFilter(string $name, string $value, array $options, string $label): string
    {
        $html = '<div class="filter-group">';
        $html .= '<label class="group-label">' . htmlspecialchars($label) . '</label>';
        $html .= '<select name="' . $name . '" class="filter-select">';
        $html .= '<option value="">Select ' . htmlspecialchars($label) . '</option>';

        foreach ($options as $option) {
            $selected = $option->value === $value ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars($option->value) . '"' . $selected . '>';
            $html .= htmlspecialchars($option->label);
            $html .= '</option>';
        }

        $html .= '</select>';
        $html .= '</div>';

        return $html;
    }

    private function renderMultiSelectFilter(string $name, array $values, array $options, string $label): string
    {
        $html = '<div class="filter-group">';
        $html .= '<label class="group-label">' . htmlspecialchars($label) . '</label>';
        $html .= '<select name="' . $name . '[]" class="filter-multiselect" multiple>';

        foreach ($options as $option) {
            $selected = in_array($option->value, $values, true) ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars($option->value) . '"' . $selected . '>';
            $html .= htmlspecialchars($option->label);
            $html .= '</option>';
        }

        $html .= '</select>';
        $html .= '</div>';

        return $html;
    }

    private function renderAmountRangeFilter(string $min, string $max): string
    {
        $html = '<div class="filter-group">';
        $html .= '<label class="group-label">Amount Range</label>';
        $html .= '<div class="amount-range">';
        $html .= '<input type="number" name="amount_min" value="' . htmlspecialchars($min) . '" placeholder="Min $0" class="amount-input" step="0.01" />';
        $html .= '<span class="range-divider">-</span>';
        $html .= '<input type="number" name="amount_max" value="' . htmlspecialchars($max) . '" placeholder="Max" class="amount-input" step="0.01" />';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    private function getTransactionTypeOptions(): array
    {
        return [
            new FilterOption('payment', 'Payment'),
            new FilterOption('refund', 'Refund'),
            new FilterOption('credit', 'Credit'),
            new FilterOption('debit', 'Debit'),
            new FilterOption('transfer', 'Transfer'),
        ];
    }

    private function getPaymentMethodOptions(): array
    {
        return [
            new FilterOption('credit_card', 'Credit Card'),
            new FilterOption('debit_card', 'Debit Card'),
            new FilterOption('paypal', 'PayPal'),
            new FilterOption('bank_transfer', 'Bank Transfer'),
            new FilterOption('crypto', 'Cryptocurrency'),
        ];
    }

    private function getTransactionStatusOptions(): array
    {
        return [
            new FilterOption('completed', 'Completed'),
            new FilterOption('pending', 'Pending'),
            new FilterOption('failed', 'Failed'),
            new FilterOption('cancelled', 'Cancelled'),
            new FilterOption('disputed', 'Disputed'),
        ];
    }
}
