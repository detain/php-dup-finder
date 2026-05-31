<?php
declare(strict_types=1);

namespace Admin\Grid;

final class SubscriptionGridRow
{
    public string $sub_ref;
    public string $cust_ref;
    public string $plan_label;
    public string $status_badge;
    public string $period_human;
    public string $trial_info;
    public bool $auto_cancel;

    public function fill(array $row): void
    {
        if (empty($row['id']) || empty($row['customer_id'])) {
            throw new \InvalidArgumentException('Grid row missing ids');
        }
        if (!in_array($row['status'] ?? '', ['active', 'past_due', 'canceled', 'trialing'], true)) {
            throw new \InvalidArgumentException('Grid row bad status');
        }
        $this->sub_ref = (string)$row['id'];
        $this->cust_ref = (string)$row['customer_id'];
        $this->plan_label = strtoupper((string)$row['plan_code']);
        $this->status_badge = ucfirst((string)$row['status']);
        $start = new \DateTimeImmutable((string)$row['period_start']);
        $end = new \DateTimeImmutable((string)$row['period_end']);
        $this->period_human = $start->format('M j') . ' – ' . $end->format('M j, Y');
        $this->trial_info = !empty($row['trial_end'])
            ? 'Trial ends ' . (new \DateTimeImmutable((string)$row['trial_end']))->format('M j')
            : 'No trial';
        $this->auto_cancel = (bool)($row['cancel_at_period_end'] ?? false);
    }
}

final class AdminController
{
    public function index(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $grid = new SubscriptionGridRow();
            $grid->fill($row);
            $out[] = $grid;
        }
        return $out;
    }
}
