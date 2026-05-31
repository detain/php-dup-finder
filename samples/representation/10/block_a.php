<?php
declare(strict_types=1);

namespace Helpdesk;

final class TicketRecord
{
    public int $id;
    public string $subject;
    public string $body;
    public string $requesterEmail;
    public string $requesterName;
    public string $priority;
    public string $status;
    public ?int $assigneeId;
    public ?string $internalNotes;
    public \DateTimeImmutable $openedAt;
    public ?\DateTimeImmutable $closedAt;
    public \DateTimeImmutable $lastUpdatedAt;

    public function __construct(array $row)
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
        $this->id = (int)$row['id'];
        $this->subject = (string)$row['subject'];
        $this->body = (string)$row['body'];
        $this->requesterEmail = strtolower((string)$row['requester_email']);
        $this->requesterName = (string)$row['requester_name'];
        $this->priority = (string)$row['priority'];
        $this->status = (string)$row['status'];
        $this->assigneeId = isset($row['assignee_id']) ? (int)$row['assignee_id'] : null;
        $this->internalNotes = isset($row['internal_notes']) ? (string)$row['internal_notes'] : null;
        $this->openedAt = new \DateTimeImmutable((string)$row['opened_at']);
        $this->closedAt = !empty($row['closed_at']) ? new \DateTimeImmutable((string)$row['closed_at']) : null;
        $this->lastUpdatedAt = new \DateTimeImmutable((string)$row['updated_at']);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['open', 'pending'], true);
    }
}

final class AgentDashboard
{
    public function load(array $row): TicketRecord
    {
        return new TicketRecord($row);
    }
}
