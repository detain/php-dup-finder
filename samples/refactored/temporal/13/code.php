<?php
declare(strict_types=1);

namespace App\Contract;

interface TemporalOperation
{
    public function begin(): void;
    public function validate(): void;
    public function execute(): mixed;
    public function finalize(): void;
    public function rollback(): void;
}

abstract class BaseTemporalOperation implements TemporalOperation
{
    protected bool $began = false;
    protected bool $executed = false;

    public function executeOperation(): mixed
    {
        $this->begin();
        $this->validate();

        try {
            $result = $this->execute();
            $this->executed = true;
            $this->finalize();
            return $result;
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function begin(): void
    {
        $this->began = true;
    }

    public function validate(): void
    {
        if (!$this->began) {
            throw new \RuntimeException('Operation not begun');
        }
    }

    abstract public function execute(): mixed;
    abstract public function finalize(): void;
    abstract public function rollback(): void;
}

final class VerificationTemporalOperation extends BaseTemporalOperation
{
    private ?Verification $verification = null;
    private ?Challenge $challenge = null;

    public function __construct(
        private readonly VerificationService $service,
        private readonly string $channel,
        private readonly string $recipient
    ) {}

    public function execute(): mixed
    {
        $this->verification = $this->service->createVerification(
            $this->channel,
            $this->recipient
        );
        $this->challenge = $this->service->createChallenge($this->verification->getId());

        return ['verification_id' => $this->verification->getId()];
    }

    public function finalize(): void
    {
        $this->service->markAsPending($this->verification->getId());
    }

    public function rollback(): void
    {
        if ($this->verification !== null) {
            $this->service->updateStatus($this->verification->getId(), 'failed');
        }
    }
}
