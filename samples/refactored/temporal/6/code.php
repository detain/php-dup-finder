<?php
declare(strict_types=1);

namespace Admin\Privileged;

use Psr\Log\LoggerInterface;

final class AuditedAction
{
    public function __construct(private AuditLog $audit, private LoggerInterface $log) {}

    /**
     * @template T
     * @param callable():array{0:T,1:string} $work returns [result, stateHash]
     * @return T
     */
    public function audited(int $actorId, string $action, string $target, callable $work)
    {
        $id = $this->audit->begin([
            'actor_id' => $actorId,
            'action'   => $action,
            'target'   => $target,
            'started_at' => date(DATE_ATOM),
        ]);
        try {
            [$result, $stateHash] = $work();
            $this->audit->finish($id, [
                'outcome'    => 'success',
                'state_hash' => $stateHash,
                'finished_at'=> date(DATE_ATOM),
            ]);
            $this->log->info("{$action}.ok", ['target' => $target]);
            return $result;
        } catch (\Throwable $e) {
            $this->audit->finish($id, [
                'outcome'    => 'failure',
                'error'      => $e->getMessage(),
                'finished_at'=> date(DATE_ATOM),
            ]);
            throw $e;
        }
    }
}

final class RoleAssignmentService
{
    public function __construct(private AuditedAction $audited, private UserRepository $users, private RoleRepository $roles) {}

    public function assign(int $actorId, int $targetUserId, string $role): void
    {
        $this->audited->audited($actorId, 'role.assign', "user:{$targetUserId}", function () use ($targetUserId, $role) {
            if ($this->users->find($targetUserId) === null) throw new \DomainException('user_not_found');
            if (!$this->roles->isValid($role)) throw new \DomainException('invalid_role');
            $this->users->addRole($targetUserId, $role);
            return [null, sha1(json_encode($this->users->effectiveRoles($targetUserId), JSON_THROW_ON_ERROR))];
        });
    }
}
