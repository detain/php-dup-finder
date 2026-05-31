<?php
declare(strict_types=1);

namespace Admin\Privileged\Accounts;

use Psr\Log\LoggerInterface;

final class AccountSuspensionService
{
    public function __construct(
        private AuditLog $audit,
        private AccountRepository $accounts,
        private SessionStore $sessions,
        private LoggerInterface $log,
    ) {}

    public function suspend(int $actorId, int $accountId, string $reason): void
    {
        $auditId = $this->audit->begin([
            'actor_id' => $actorId,
            'action'   => 'account.suspend',
            'target'   => "account:{$accountId}",
            'started_at' => date(DATE_ATOM),
        ]);
        try {
            $account = $this->accounts->find($accountId);
            if ($account === null) {
                throw new \DomainException('account_not_found');
            }
            if ($account['status'] === 'suspended') {
                throw new \DomainException('already_suspended');
            }
            $this->accounts->updateStatus($accountId, 'suspended', $reason);
            $this->sessions->revokeAllFor($accountId);
            $stateHash = sha1(json_encode($this->accounts->snapshot($accountId), JSON_THROW_ON_ERROR));
            $this->audit->finish($auditId, [
                'outcome'    => 'success',
                'state_hash' => $stateHash,
                'finished_at'=> date(DATE_ATOM),
            ]);
            $this->log->info('account.suspend.ok', ['account' => $accountId]);
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
