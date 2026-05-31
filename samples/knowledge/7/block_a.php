<?php

declare(strict_types=1);

namespace App\Auth;

use App\Cache\Cache;
use App\Repositories\UserRepository;

final class LoginThrottle
{
    public function __construct(
        private Cache $cache,
        private UserRepository $users,
    ) {}

    public function recordFailedAttempt(string $email, string $ip): ThrottleResult
    {
        $key = $this->key($email, $ip);
        $attempts = (int) ($this->cache->get($key) ?? 0);
        $attempts++;
        $this->cache->set($key, $attempts, 900); // 15-minute window

        if ($attempts >= 5) {
            $user = $this->users->findByEmail($email);
            if ($user !== null) {
                $user->lockedUntil = time() + 1800;
                $this->users->save($user);
            }
            return new ThrottleResult(blocked: true, attempts: $attempts, retryAfterSeconds: 1800);
        }

        return new ThrottleResult(
            blocked: false,
            attempts: $attempts,
            attemptsRemaining: 5 - $attempts,
            retryAfterSeconds: 0,
        );
    }

    public function clear(string $email, string $ip): void
    {
        $this->cache->delete($this->key($email, $ip));
    }

    private function key(string $email, string $ip): string
    {
        return 'login_attempts:' . sha1($email . '|' . $ip);
    }
}
