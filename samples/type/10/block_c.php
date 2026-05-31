<?php
declare(strict_types=1);

namespace Acme\Search\Indexers;

use Acme\Search\SearchClient;
use Acme\Search\Exceptions\IndexException;
use Acme\Search\Metrics;
use Acme\Identity\UserRepository;
use Psr\Log\LoggerInterface;

final class UserSearchIndexer
{
    public function __construct(
        private readonly SearchClient $client,
        private readonly UserRepository $repo,
        private readonly Metrics $metrics,
        private readonly LoggerInterface $log,
        private readonly string $indexName = 'users_v3'
    ) {
    }

    public function reindexBatch(array $userIds): int
    {
        $bulk = $this->client->openBulk($this->indexName);
        $count = 0;
        $start = microtime(true);
        try {
            foreach ($userIds as $id) {
                $user = $this->repo->find((int)$id);
                if ($user === null) {
                    $bulk->delete((string)$id);
                    continue;
                }
                $doc = [
                    'id'           => $user->id,
                    'type'         => 'user',
                    'updated_at'   => $user->updatedAt->format(DATE_ATOM),
                    'boost'        => $user->isVerified ? 1.5 : 1.0,
                    'display_name' => $user->displayName,
                    'username'     => $user->username,
                    'email_domain' => substr(strrchr($user->email, '@') ?: '@', 1),
                    'location'     => $user->location,
                    'role'         => $user->role,
                    'karma'        => $user->karma,
                    'active'       => $user->status === 'active',
                ];
                $bulk->upsert((string)$user->id, $doc);
                $count++;
            }
            $bulk->commit();
            $this->metrics->increment('search.user.indexed', $count);
        } catch (\Throwable $e) {
            $bulk->discard();
            $this->log->error('user reindex failed', ['err' => $e->getMessage()]);
            $this->metrics->increment('search.user.failed');
            throw new IndexException('user index failed', 0, $e);
        } finally {
            $this->metrics->timing('search.user.duration', microtime(true) - $start);
        }
        return $count;
    }
}
