<?php
declare(strict_types=1);

namespace App\Records\Legal;

use App\Auth\ViewerContext;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class LegalCaseRepository
{
    private const ROLE_VISIBILITY = [
        'admin'   => ['public', 'restricted', 'sealed'],
        'lawyer'  => ['public', 'restricted'],
        'client'  => ['public'],
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly ViewerContext $viewer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findForClient(int $clientId): array
    {
        $role = $this->viewer->role();
        $visibilities = self::ROLE_VISIBILITY[$role] ?? ['public'];
        $placeholders = implode(',', array_map(static fn (int $i): string => ":vis{$i}", array_keys($visibilities)));

        $sql = "SELECT id, client_id, docket_number, opened_at, visibility
                FROM legal_cases
                WHERE deleted_at IS NULL
                  AND client_id = :client_id
                  AND visibility IN ({$placeholders})
                ORDER BY opened_at DESC";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':client_id', $clientId, PDO::PARAM_INT);
            foreach ($visibilities as $i => $v) {
                $stmt->bindValue(":vis{$i}", $v);
            }
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            $this->logger->error('Legal case query failed', [
                'client_id' => $clientId,
                'role' => $role,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('Unable to load legal cases', 0, $e);
        }
    }
}
