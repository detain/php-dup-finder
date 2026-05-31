<?php
declare(strict_types=1);

namespace App\Api\PasswordReset;

final class PasswordResetRequest
{
    public function __construct(public string $email) {}

    public static function fromHttp(array $body): self
    {
        return new self((string) ($body['email'] ?? ''));
    }
}

final class PasswordResetValidator
{
    /** @return list<string> */
    public function validate(PasswordResetRequest $req): array
    {
        $errors = [];
        if (!filter_var($req->email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'email_invalid';
        }
        return $errors;
    }
}

final class PasswordResetHandler
{
    public function __construct(private \PDO $pdo) {}

    public function execute(PasswordResetRequest $req): array
    {
        $token = bin2hex(random_bytes(16));
        $stmt = $this->pdo->prepare('INSERT INTO password_resets (email, token, created_at) VALUES (?, ?, NOW())');
        $stmt->execute([$req->email, $token]);
        return ['token' => $token];
    }
}

final class PasswordResetResponder
{
    public function ok(array $data): array
    {
        return ['status' => 'ok', 'data' => $data, 'errors' => []];
    }

    public function fail(array $errors): array
    {
        return ['status' => 'error', 'data' => null, 'errors' => $errors];
    }
}

final class PasswordResetController
{
    public function __construct(
        private PasswordResetValidator $validator,
        private PasswordResetHandler $handler,
        private PasswordResetResponder $responder,
    ) {}

    public function __invoke(array $body): array
    {
        $req = PasswordResetRequest::fromHttp($body);
        $errors = $this->validator->validate($req);
        return $errors === [] ? $this->responder->ok($this->handler->execute($req)) : $this->responder->fail($errors);
    }
}
