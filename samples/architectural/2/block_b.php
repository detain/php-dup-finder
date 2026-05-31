<?php
declare(strict_types=1);

namespace App\Cqrs\Posts;

final class PublishPostCommand
{
    public function __construct(public int $postId, public \DateTimeImmutable $publishAt) {}
}

final class PublishPostResult
{
    public function __construct(public int $postId, public bool $wasAlreadyPublished) {}
}

final class PublishPostHandler
{
    public function __construct(private \PDO $pdo, private \Psr\Log\LoggerInterface $log) {}

    public function handle(PublishPostCommand $cmd): PublishPostResult
    {
        $this->log->info('handling PublishPost', ['postId' => $cmd->postId]);
        $stmt = $this->pdo->prepare('SELECT published_at FROM posts WHERE id = ?');
        $stmt->execute([$cmd->postId]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false && $existing !== null) {
            return new PublishPostResult($cmd->postId, true);
        }
        $upd = $this->pdo->prepare('UPDATE posts SET published_at = ? WHERE id = ?');
        $upd->execute([$cmd->publishAt->format('Y-m-d H:i:s'), $cmd->postId]);
        $this->log->info('post published', ['id' => $cmd->postId]);
        return new PublishPostResult($cmd->postId, false);
    }
}

final class PostBus
{
    public function __construct(private PublishPostHandler $handler) {}

    public function dispatch(PublishPostCommand $cmd): PublishPostResult
    {
        try {
            return $this->handler->handle($cmd);
        } catch (\Throwable $e) {
            throw new \RuntimeException('PublishPost failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
