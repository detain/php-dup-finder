<?php

declare(strict_types=1);

namespace App\Application\DTOs\Reporting;

use App\Domain\Orders\Entity\Order;
use App\Domain\Customer\Entity\Customer;

/**
 * Report generation request and response DTOs.
 *
 * REPORT TYPES AND THEIR PARAMETERS:
 *
 * SALES_REPORT:
 * Parameters:
 * - start_date (DateTimeImmutable, required): Report period start
 * - end_date (DateTimeImmutable, required): Report period end
 * - group_by (string, optional): day|week|month (default: day)
 * - include_refunds (bool, optional): Include refunds in totals (default: false)
 * - payment_methods (string[], optional): Filter by payment methods
 * - categories (string[], optional): Filter by product categories
 * - regions (string[], optional): Filter by shipping regions
 *
 * Returns:
 * - summary (object): Total sales, orders, refunds, average order value
 * - breakdown (array): Sales grouped by specified dimension
 * - top_products (array): Best selling products
 * - top_customers (array): Highest value customers
 *
 * INVENTORY_REPORT:
 * Parameters:
 * - as_of_date (DateTimeImmutable, required): Report snapshot date
 * - warehouse_id (string, optional): Filter by warehouse
 * - category (string[], optional): Filter by product category
 * - low_stock_threshold (int, optional): Highlight items below threshold
 * - include_discontinued (bool, optional): Include discontinued items
 *
 * Returns:
 * - current_stock (array): Stock levels by product
 * - low_stock_alerts (array): Products below threshold
 * - out_of_stock (array): Products with zero stock
 * - stock_turnover (array): Inventory turnover metrics
 *
 * CUSTOMER_ANALYTICS_REPORT:
 * Parameters:
 * - start_date (DateTimeImmutable, required): Report period start
 * - end_date (DateTimeImmutable, required): Report period end
 * - cohort_type (string, optional): registration_date|first_purchase (default: registration_date)
 * - segment (string, optional): Filter by customer segment
 *
 * Returns:
 * - new_customers (int): Customers acquired in period
 * - returning_customers (int): Customers with prior purchases
 * - customer_lifetime_value (object): LTV distribution
 * - retention_rates (array): Month-over-month retention
 *
 * REFUND_REPORT:
 * Parameters:
 * - start_date (DateTimeImmutable, required): Report period start
 * - end_date (DateTimeImmutable, required): Report period end
 * - reason (string[], optional): Filter by refund reason
 * - payment_method (string[], optional): Filter by original payment method
 * - include_investigation (bool, optional): Include pending investigations
 *
 * Returns:
 * - total_refunds (float): Total refund amount
 * - refund_count (int): Number of refunds processed
 * - refund_rate (float): Refunds as percentage of sales
 * - by_reason (array): Breakdown by refund reason
 * - investigation_queue (array): Pending refund investigations
 *
 * DOCUMENTED IN:
 * - API spec: paths./reports.generate
 * - Report descriptions: docs/reports/descriptions.md
 * - Parameters schema: docs/reports/parameters.md
 */
class ReportRequest
{
    public const TYPE_SALES_REPORT = 'sales';
    public const TYPE_INVENTORY_REPORT = 'inventory';
    public const TYPE_CUSTOMER_ANALYTICS_REPORT = 'customer_analytics';
    public const TYPE_REFUND_REPORT = 'refund';

    private const VALID_GROUP_BY = ['day', 'week', 'month'];

    private string $reportType;
    private array $parameters;
    private DateTimeImmutable $requestedAt;
    private string $requestedBy;

    public function __construct(
        string $reportType,
        array $parameters,
        string $requestedBy
    ) {
        $this->reportType = $reportType;
        $this->parameters = $this->validateParameters($reportType, $parameters);
        $this->requestedAt = new DateTimeImmutable();
        $this->requestedBy = $requestedBy;
    }

    /**
     * Validate parameters based on report type.
     * Required parameters are defined in the API documentation.
     */
    private function validateParameters(string $reportType, array $parameters): array
    {
        $requiredParams = match ($reportType) {
            self::TYPE_SALES_REPORT => ['start_date', 'end_date'],
            self::TYPE_INVENTORY_REPORT => ['as_of_date'],
            self::TYPE_CUSTOMER_ANALYTICS_REPORT => ['start_date', 'end_date'],
            self::TYPE_REFUND_REPORT => ['start_date', 'end_date'],
            default => throw new \InvalidArgumentException("Unknown report type: {$reportType}"),
        };

        $missing = array_diff($requiredParams, array_keys($parameters));
        if (!empty($missing)) {
            throw new \InvalidArgumentException(
                "Missing required parameters for {$reportType}: " . implode(', ', $missing)
            );
        }

        if (isset($parameters['group_by']) && !in_array($parameters['group_by'], self::VALID_GROUP_BY)) {
            throw new \InvalidArgumentException(
                "Invalid group_by value. Must be one of: " . implode(', ', self::VALID_GROUP_BY)
            );
        }

        return $parameters;
    }

    public function getReportType(): string
    {
        return $this->reportType;
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function getStartDate(): ?DateTimeImmutable
    {
        return $this->parameters['start_date'] ?? null;
    }

    public function getEndDate(): ?DateTimeImmutable
    {
        return $this->parameters['end_date'] ?? null;
    }

    public function getGroupBy(): string
    {
        return $this->parameters['group_by'] ?? 'day';
    }

    public function toArray(): array
    {
        return [
            'report_type' => $this->reportType,
            'parameters' => $this->parameters,
            'requested_at' => $this->requestedAt->format(\DateTimeImmutable::ATOM),
            'requested_by' => $this->requestedBy,
        ];
    }
}
