<?php
declare(strict_types=1);

namespace Admin\Privileged\Credentials;

use Psr\Log\LoggerInterface;

final class ApiKeyRotationService
{
    public function __construct(
        private AuditLog $audit,
        private ApiKeyRepository $keys,
        private KeyGenerator $generator,
        private LoggerInterface $log,
    ) {}

    public function rotate(int $actorId, string $keyId): string
    {
        $auditId = $this->audit->begin([
            'actor_id' => $actorId,
            'action'   => 'apikey.rotate',
            'target'   => "key:{$keyId}",
            'started_at' => date(DATE_ATOM),
        ]);
        try {
            $existing = $this->keys->find($keyId);
            if ($existing === null) {
                throw new \DomainException('key_not_found');
            }
            if ($existing['revoked']) {
                throw new \DomainException('already_revoked');
            }
            $newSecret = $this->generator->generate(48);
            $this->keys->markRotated($keyId, hash('sha256', $newSecret));
            $stateHash = sha1(json_encode($this->keys->snapshot($keyId), JSON_THROW_ON_ERROR));
            $this->audit->finish($auditId, [
                'outcome'    => 'success',
                'state_hash' => $stateHash,
                'finished_at'=> date(DATE_ATOM),
            ]);
            $this->log->info('apikey.rotate.ok', ['key' => $keyId]);
            return $newSecret;
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
