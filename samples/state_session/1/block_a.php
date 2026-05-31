<?php
declare(strict_types=1);

namespace Authentication\Sessions;

use Doctrine\ORM\EntityManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Cookie;

final class SessionController
{
    private const SESSION_COOKIE_NAME = 'customer_session';
    private const SESSION_LIFETIME = 86400;
    private const CACHE_TTL = 3600;

    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly LoggerInterface $logger,
        private readonly CacheService $cache,
        private readonly CryptoService $crypto
    ) {}

    public function login(Request $request): JsonResponse
    {
        $email = $request->request->get('email');
        $password = $request->request->get('password');

        $customer = $this->entityManager
            ->getRepository(Customer::class)
            ->findOneBy(['email' => strtolower($email)]);

        if ($customer === null || !password_verify($password, $customer->getPasswordHash())) {
            return $this->json(['error' => 'Invalid credentials'], 401);
        }

        // Create session
        $sessionId = $this->crypto->generateSessionId();
        $sessionToken = $this->crypto->hash($sessionId);

        $expiresAt = new \DateTimeImmutable('+' . self::SESSION_LIFETIME . ' seconds');

        // Store in database
        $session = new CustomerSession();
        $session->setCustomer($customer);
        $session->setSessionToken($sessionToken);
        $session->setIpAddress($request->getClientIp());
        $session->setUserAgent($request->headers->get('User-Agent'));
        $session->setCreatedAt(new \DateTimeImmutable());
        $session->setExpiresAt($expiresAt);
        $session->setLastActivityAt(new \DateTimeImmutable());

        $this->entityManager->persist($session);
        $this->entityManager->flush();

        // Store in cache for fast access
        $cacheData = [
            'customer_id' => $customer->getId(),
            'email' => $customer->getEmail(),
            'name' => $customer->getFullName(),
            'tier' => $customer->getMembershipTier(),
            'session_token' => $sessionToken
        ];

        $this->cache->set(
            'session:' . $sessionId,
            json_encode($cacheData),
            self::CACHE_TTL
        );

        // Set cookie
        $cookie = Cookie::create(self::SESSION_COOKIE_NAME)
            ->withValue($sessionId)
            ->withExpires($expiresAt)
            ->withPath('/')
            ->withHttpOnly(true)
            ->withSecure(true);

        $this->logger->info('Customer logged in', [
            'customer_id' => $customer->getId(),
            'session_id' => substr($sessionId, 0, 8) . '...'
        ]);

        return $this->json([
            'message' => 'Login successful',
            'customer' => $customer->toArray()
        ])->headers->setCookie($cookie);
    }

    public function getCurrentCustomer(Request $request): JsonResponse
    {
        $sessionId = $request->cookies->get(self::SESSION_COOKIE_NAME);

        if ($sessionId === null) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        // Try cache first
        $cached = $this->cache->get('session:' . $sessionId);
        if ($cached !== null) {
            $data = json_decode($cached, true);

            // Update last activity in database (async via queue in production)
            $this->updateLastActivity($sessionId);

            return $this->json([
                'customer' => [
                    'id' => $data['customer_id'],
                    'email' => $data['email'],
                    'name' => $data['name'],
                    'tier' => $data['tier']
                ]
            ]);
        }

        // Cache miss - verify in database
        $session = $this->entityManager
            ->getRepository(CustomerSession::class)
            ->findOneBySessionToken($this->crypto->hash($sessionId));

        if ($session === null) {
            return $this->json(['error' => 'Session expired'], 401);
        }

        if ($session->getExpiresAt() < new \DateTimeImmutable()) {
            $this->entityManager->remove($session);
            $this->entityManager->flush();
            $this->cache->delete('session:' . $sessionId);

            return $this->json(['error' => 'Session expired'], 401);
        }

        $customer = $session->getCustomer();

        // Repopulate cache
        $cacheData = [
            'customer_id' => $customer->getId(),
            'email' => $customer->getEmail(),
            'name' => $customer->getFullName(),
            'tier' => $customer->getMembershipTier(),
            'session_token' => $session->getSessionToken()
        ];

        $this->cache->set(
            'session:' . $sessionId,
            json_encode($cacheData),
            self::CACHE_TTL
        );

        return $this->json([
            'customer' => $customer->toArray()
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $sessionId = $request->cookies->get(self::SESSION_COOKIE_NAME);

        if ($sessionId !== null) {
            // Remove from database
            $session = $this->entityManager
                ->getRepository(CustomerSession::class)
                ->findOneBySessionToken($this->crypto->hash($sessionId));

            if ($session !== null) {
                $this->logger->info('Customer logged out', [
                    'customer_id' => $session->getCustomer()->getId()
                ]);

                $this->entityManager->remove($session);
                $this->entityManager->flush();
            }

            // Remove from cache
            $this->cache->delete('session:' . $sessionId);
        }

        // Clear cookie
        $cookie = Cookie::create(self::SESSION_COOKIE_NAME)
            ->withValue('')
            ->withExpires(new \DateTimeImmutable('-1 year'))
            ->withPath('/');

        return $this->json(['message' => 'Logged out'])->headers->setCookie($cookie);
    }

    private function updateLastActivity(string $sessionId): void
    {
        try {
            $hashedToken = $this->crypto->hash($sessionId);
            $this->entityManager->getRepository(CustomerSession::class)
                ->createQueryBuilder('s')
                ->update()
                ->set('s.lastActivityAt', ':now')
                ->where('s.sessionToken = :token')
                ->setParameter('token', $hashedToken)
                ->setParameter('now', new \DateTimeImmutable())
                ->getQuery()
                ->execute();
        } catch (\Exception $e) {
            $this->logger->warning('Failed to update session last activity', [
                'error' => $e->getMessage()
            ]);
        }
    }
}
