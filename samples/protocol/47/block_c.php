<?php

declare(strict_types=1);

namespace App\Services\Soap;

use SoapFault;

trait SoapHandlerTrait
{
    protected array $authCredentials = [];

    protected function authenticateRequest(array $params): void
    {
        $username = $params['auth']['username'] ?? null;
        $password = $params['auth']['password'] ?? null;

        if (!$username || !$password) {
            throw new SoapFault('Sender', 'Authentication credentials required');
        }

        if (!isset($this->authCredentials[$username])) {
            throw new SoapFault('Sender', 'Invalid credentials');
        }

        if ($this->authCredentials[$username] !== $password) {
            throw new SoapFault('Sender', 'Invalid credentials');
        }
    }

    protected function handleSoapAction(string $action, array $handlers, array $params): array
    {
        $this->authenticateRequest($params);

        if (!isset($handlers[$action])) {
            throw new \InvalidArgumentException("Unknown action: {$action}");
        }

        return $handlers[$action]($params);
    }

    protected function handleSoapException(\Throwable $e): SoapFault
    {
        return match (true) {
            $e instanceof NotFoundException => new SoapFault('Receiver', $e->getMessage()),
            $e instanceof ValidationException => new SoapFault('Sender', $e->getMessage()),
            $e instanceof AuthenticationException => new SoapFault('Sender', 'Authentication failed'),
            default => new SoapFault('Receiver', 'Internal server error'),
        };
    }

    protected function formatEntity(array $entity, array $fields): array
    {
        $formatted = [];

        foreach ($fields as $field) {
            if (isset($entity[$field])) {
                $formatted[$field] = $entity[$field];
            }
        }

        return $formatted;
    }
}

class SoapServiceHandler
{
    use SoapHandlerTrait;

    public function handleUserRequest(string $action, array $params): array
    {
        $handlers = [
            'getUser' => fn($p) => $this->userService->findById($p['id']),
            'createUser' => fn($p) => $this->userService->createUser($p),
            'updateUser' => fn($p) => $this->userService->updateUser($p['id'], $p),
            'deleteUser' => fn($p) => $this->userService->deleteUser($p['id']),
            'listUsers' => fn($p) => $this->userService->listUsers($p['limit'] ?? 50, $p['offset'] ?? 0),
        ];

        try {
            return $this->handleSoapAction($action, $handlers, $params);
        } catch (\Throwable $e) {
            throw $this->handleSoapException($e);
        }
    }
}
