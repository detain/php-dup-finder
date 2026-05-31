<?php
declare(strict_types=1);

namespace Admin\Privileged\Roles;

use Psr\Log\LoggerInterface;

final class RoleAssignmentService
{
    public function __construct(
        private AuditLog $audit,
        private UserRepository $users,
        private RoleRepository $roles,
        private LoggerInterface $log,
    ) {}

    public function assign(int $actorId, int $targetUserId, string $role): void
    {
        $auditId = $this->audit->begin([
            'actor_id' => $actorId,
            'action'   => 'role.assign',
            'target'   => "user:{$targetUserId}",
            'started_at' => date(DATE_ATOM),
        ]);
        try {
            $target = $this->users->find($targetUserId);
            if ($target === null) {
                throw new \DomainException('user_not_found');
            }
            if (!$this->roles->isValid($role)) {
                throw new \DomainException('invalid_role');
            }
            $this->users->addRole($targetUserId, $role);
            $stateHash = sha1(json_encode($this->users->effectiveRoles($targetUserId), JSON_THROW_ON_ERROR));
            $this->audit->finish($auditId, [
                'outcome'    => 'success',
                'state_hash' => $stateHash,
                'finished_at'=> date(DATE_ATOM),
            ]);
            $this->log->info('role.assign.ok', ['user' => $targetUserId, 'role' => $role]);
        } catch (\Throwable $e) {
            $this->audit->finish($auditId, [
                'outcome'    => 'failure',
                'error'      => $e->getMessage(),
                'finished_at'=> date(DATE_ATOM),
            ]);
            throw $e;
        }
    }
}
