<?php
declare(strict_types=1);

namespace App\Api\Signup;

final class SignupRequest
{
    public function __construct(public string $email, public string $password) {}

    public static function fromHttp(array $body): self
    {
        return new self((string) ($body['email'] ?? ''), (string) ($body['password'] ?? ''));
    }
}

final class SignupValidator
{
    /** @return list<string> */
    public function validate(SignupRequest $req): array
    {
        $errors = [];
        if (!filter_var($req->email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'email_invalid';
        }
        if (strlen($req->password) < 8) {
            $errors[] = 'password_too_short';
        }
        return $errors;
    }
}

final class SignupHandler
{
    public function __construct(private \PDO $pdo) {}

    public function execute(SignupRequest $req): array
    {
        $hash = password_hash($req->password, PASSWORD_BCRYPT);
        $stmt = $this->pdo->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)');
        $stmt->execute([$req->email, $hash]);
        return ['userId' => (int) $this->pdo->lastInsertId()];
    }
}

final class SignupResponder
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

final class SignupController
{
    public function __construct(
        private SignupValidator $validator,
        private SignupHandler $handler,
        private SignupResponder $responder,
    ) {}

    public function __invoke(array $body): array
    {
        $req = SignupRequest::fromHttp($body);
        $errors = $this->validator->validate($req);
        return $errors === [] ? $this->responder->ok($this->handler->execute($req)) : $this->responder->fail($errors);
    }
}
