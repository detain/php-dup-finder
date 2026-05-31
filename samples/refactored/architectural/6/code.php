<?php
declare(strict_types=1);

namespace App\Api;

/** @template T of object */
interface RequestValidator
{
    /** @param T $request @return list<string> */
    public function validate(object $request): array;
}

/** @template T of object */
interface RequestHandler
{
    /** @param T $request */
    public function execute(object $request): array;
}

final class Envelope
{
    public static function ok(array $data): array
    {
        return ['status' => 'ok', 'data' => $data, 'errors' => []];
    }

    public static function fail(array $errors): array
    {
        return ['status' => 'error', 'data' => null, 'errors' => $errors];
    }
}

/** @template T of object */
final class ApiController
{
    /**
     * @param RequestValidator<T> $validator
     * @param RequestHandler<T> $handler
     * @param callable(array): T $factory
     */
    public function __construct(
        private RequestValidator $validator,
        private RequestHandler $handler,
        private \Closure $factory,
    ) {}

    public function __invoke(array $body): array
    {
        $req = ($this->factory)($body);
        $errors = $this->validator->validate($req);
        return $errors === [] ? Envelope::ok($this->handler->execute($req)) : Envelope::fail($errors);
    }
}
