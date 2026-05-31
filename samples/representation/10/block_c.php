<?php
declare(strict_types=1);

namespace Portal\Customer;

final class CustomerPortalTicket
{
    public string $reference;
    public string $title;
    public string $bodySnippet;
    public string $priorityLabel;
    public string $statusLabel;
    public string $opened;
    public ?string $closed;
    public string $lastActivity;

    public function fromRecord(array $row): void
    {
        if (empty($row['id']) || empty($row['subject'])) {
            throw new \InvalidArgumentException('Need id/subject');
        }
        if (!in_array($row['priority'] ?? '', ['low', 'normal', 'high', 'urgent'], true)) {
            throw new \InvalidArgumentException('Bad priority');
        }
        if (!in_array($row['status'] ?? '', ['open', 'pending', 'solved', 'closed'], true)) {
            throw new \InvalidArgumentException('Bad status');
        }
        $this->reference = 'TKT-' . str_pad((string)$row['id'], 6, '0', STR_PAD_LEFT);
        $this->title = (string)$row['subject'];
        $body = (string)$row['body'];
        $this->bodySnippet = strlen($body) > 140 ? substr($body, 0, 137) . '...' : $body;
        $priorityLabels = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'];
        $this->priorityLabel = $priorityLabels[(string)$row['priority']];
        $statusLabels = ['open' => 'Open', 'pending' => 'Awaiting reply', 'solved' => 'Solved', 'closed' => 'Closed'];
        $this->statusLabel = $statusLabels[(string)$row['status']];
        $this->opened = (new \DateTimeImmutable((string)$row['opened_at']))->format('M j, Y');
        $this->closed = !empty($row['closed_at'])
            ? (new \DateTimeImmutable((string)$row['closed_at']))->format('M j, Y')
            : null;
        $this->lastActivity = (new \DateTimeImmutable((string)$row['updated_at']))->format('M j, Y \a\t H:i');
    }
}

final class PortalController
{
    public function show(array $row): CustomerPortalTicket
    {
        $t = new CustomerPortalTicket();
        $t->fromRecord($row);
        return $t;
    }
}
