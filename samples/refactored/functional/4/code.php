<?php
declare(strict_types=1);

namespace Acme\Http\Auth;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

interface CredentialExtractor
{
    public function extract(ServerRequestInterface $request): ?string;
}

interface UserLookup
{
    public function resolve(string $credential): ?AuthenticatedUser;
}

final class UserResolverMiddleware implements MiddlewareInterface
{
    /** @param iterable<array{0:CredentialExtractor,1:UserLookup}> $strategies */
    public function __construct(private readonly iterable $strategies) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        foreach ($this->strategies as [$extractor, $lookup]) {
            $cred = $extractor->extract($request);
            if ($cred === null) {
                continue;
            }
            $user = $lookup->resolve($cred);
            if ($user !== null) {
                return $handler->handle($request->withAttribute('user', $user));
            }
        }
        return $handler->handle($request);
    }
}
