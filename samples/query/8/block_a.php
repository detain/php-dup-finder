<?php
declare(strict_types=1);

namespace App\Records\Medical;

use App\Auth\ViewerContext;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class MedicalRecordRepository
{
    private const ROLE_VISIBILITY = [
        'admin'   => ['public', 'restricted', 'internal'],
        'doctor'  => ['public', 'restricted'],
        'patient' => ['public'],
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
    public function findForPatient(int $patientId): array
    {
        $role = $this->viewer->role();
        $visibilities = self::ROLE_VISIBILITY[$role] ?? ['public'];
        $placeholders = implode(',', array_map(static fn (int $i): string => ":vis{$i}", array_keys($visibilities)));

        $sql = "SELECT id, patient_id, summary, recorded_at, visibility
                FROM medical_records
                WHERE deleted_at IS NULL
                  AND patient_id = :patient_id
                  AND visibility IN ({$placeholders})
                ORDER BY recorded_at DESC";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':patient_id', $patientId, PDO::PARAM_INT);
            foreach ($visibilities as $i => $v) {
                $stmt->bindValue(":vis{$i}", $v);
            }
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            $this->logger->error('Medical record query failed', [
                'patient_id' => $patientId,
                'role' => $role,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('Unable to load medical records', 0, $e);
        }
    }
}
