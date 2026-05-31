<?php
declare(strict_types=1);

namespace Reports\Sla;

final class TicketSlaDto
{
    public function __construct(
        public readonly int $ticket_id,
        public readonly string $headline,
        public readonly string $priority_level,
        public readonly string $current_state,
        public readonly ?int $assignee,
        public readonly \DateTimeImmutable $opened,
        public readonly ?\DateTimeImmutable $closed,
        public readonly \DateTimeImmutable $touched,
        public readonly int $ageHours,
        public readonly ?int $resolutionHours,
    ) {
        if ($ticket_id <= 0 || $headline === '') {
            throw new \InvalidArgumentException('Need id/subject');
        }
        if (!in_array($priority_level, ['low', 'normal', 'high', 'urgent'], true)) {
            throw new \InvalidArgumentException('Bad priority');
        }
        if (!in_array($current_state, ['open', 'pending', 'solved', 'closed'], true)) {
            throw new \InvalidArgumentException('Bad status');
        }
    }

    public static function fromRow(array $row): self
    {
        $opened = new \DateTimeImmutable((string)$row['opened_at']);
        $closed = !empty($row['closed_at']) ? new \DateTimeImmutable((string)$row['closed_at']) : null;
        $age = (int) round((time() - $opened->getTimestamp()) / 3600);
        $resolution = $closed !== null ? (int) round(($closed->getTimestamp() - $opened->getTimestamp()) / 3600) : null;
        return new self(
            (int)$row['id'],
            (string)$row['subject'],
            (string)$row['priority'],
            (string)$row['status'],
            isset($row['assignee_id']) ? (int)$row['assignee_id'] : null,
            $opened,
            $closed,
            new \DateTimeImmutable((string)$row['updated_at']),
            $age,
            $resolution,
        );
    }

    public function breachedSla(int $slaHours): bool
    {
        return $this->ageHours > $slaHours && $this->closed === null;
    }
}

final class SlaReport
{
    /** @return TicketSlaDto[] */
    public function build(array $rows): array
    {
        return array_map(fn($r) => TicketSlaDto::fromRow($r), $rows);
    }
}
