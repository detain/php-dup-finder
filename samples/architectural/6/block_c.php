<?php
declare(strict_types=1);

namespace App\Api\ProfileUpdate;

final class ProfileUpdateRequest
{
    public function __construct(public int $userId, public string $displayName, public string $bio) {}

    public static function fromHttp(array $body): self
    {
        return new self((int) ($body['userId'] ?? 0), (string) ($body['displayName'] ?? ''), (string) ($body['bio'] ?? ''));
    }
}

final class ProfileUpdateValidator
{
    /** @return list<string> */
    public function validate(ProfileUpdateRequest $req): array
    {
        $errors = [];
        if ($req->userId <= 0) {
            $errors[] = 'user_invalid';
        }
        if ($req->displayName === '') {
            $errors[] = 'display_name_required';
        }
        if (strlen($req->bio) > 500) {
            $errors[] = 'bio_too_long';
        }
        return $errors;
    }
}

final class ProfileUpdateHandler
{
    public function __construct(private \PDO $pdo) {}

    public function execute(ProfileUpdateRequest $req): array
    {
        $stmt = $this->pdo->prepare('UPDATE profiles SET display_name = ?, bio = ? WHERE user_id = ?');
        $stmt->execute([$req->displayName, $req->bio, $req->userId]);
        return ['updated' => $stmt->rowCount()];
    }
}

final class ProfileUpdateResponder
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

final class ProfileUpdateController
{
    public function __construct(
        private ProfileUpdateValidator $validator,
        private ProfileUpdateHandler $handler,
        private ProfileUpdateResponder $responder,
    ) {}

    public function __invoke(array $body): array
    {
        $req = ProfileUpdateRequest::fromHttp($body);
        $errors = $this->validator->validate($req);
        return $errors === [] ? $this->responder->ok($this->handler->execute($req)) : $this->responder->fail($errors);
    }
}
