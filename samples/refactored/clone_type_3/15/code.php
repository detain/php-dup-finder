<?php

declare(strict_types=1);

namespace App\Router;

use App\Request\Request;
use App\Response\Response;
use App\Exception\NotFoundException;
use App\Exception\MethodNotAllowedException;

interface RouterInterface
{
    public function addRoute(string $method, string $path, callable $handler, array $middlewares = []): void;
    public function get(string $path, callable $handler, array $middlewares = []): void;
    public function post(string $path, callable $handler, array $middlewares = []): void;
    public function put(string $path, callable $handler, array $middlewares = []): void;
    public function delete(string $path, callable $handler, array $middlewares = []): void;
    public function dispatch(Request $request): Response;
}

abstract class AbstractRouter implements RouterInterface
{
    protected array $routes = [];
    protected array $middlewares = [];
    protected string $groupPrefix = '';

    public function addRoute(string $method, string $path, callable $handler, array $middlewares = []): void
    {
        $fullPath = $this->resolvePath($path);

        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $fullPath,
            'handler' => $handler,
            'middlewares' => array_merge($this->middlewares, $middlewares),
        ];
    }

    public function get(string $path, callable $handler, array $middlewares = []): void
    {
        $this->addRoute('GET', $path, $handler, $middlewares);
    }

    public function post(string $path, callable $handler, array $middlewares = []): void
    {
        $this->addRoute('POST', $path, $handler, $middlewares);
    }

    public function put(string $path, callable $handler, array $middlewares = []): void
    {
        $this->addRoute('PUT', $path, $handler, $middlewares);
    }

    public function delete(string $path, callable $handler, array $middlewares = []): void
    {
        $this->addRoute('DELETE', $path, $handler, $middlewares);
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->getMethod();
        $path = $request->getPath();

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            $params = $this->matchPath($route['path'], $path);

            if ($params !== false) {
                $request->setParams($params);
                return $this->executeRoute($route['middlewares'], $route['handler'], $request);
            }
        }

        if ($this->hasMatchingPath($path)) {
            throw new MethodNotAllowedException($this->getMethodNotAllowedMessage());
        }

        throw new NotFoundException($this->getNotFoundMessage($path));
    }

    protected function resolvePath(string $path): string
    {
        return $this->groupPrefix . '/' . ltrim($path, '/');
    }

    protected function matchPath(string $routePath, string $requestPath): array|false
    {
        $routeParts = explode('/', trim($routePath, '/'));
        $pathParts = explode('/', trim($requestPath, '/'));

        if (count($routeParts) !== count($pathParts)) {
            return false;
        }

        $params = [];

        for ($i = 0; $i < count($routeParts); $i++) {
            if ($this->isParameter($routeParts[$i])) {
                $params[$this->getParameterName($routeParts[$i])] = $pathParts[$i];
            } elseif ($routeParts[$i] !== $pathParts[$i]) {
                return false;
            }
        }

        return $params;
    }

    protected function isParameter(string $part): bool
    {
        return str_starts_with($part, '{') && str_ends_with($part, '}');
    }

    protected function getParameterName(string $part): string
    {
        return trim($part, '{}');
    }

    protected function hasMatchingPath(string $path): bool
    {
        foreach ($this->routes as $route) {
            if ($this->matchPath($route['path'], $path) !== false) {
                return true;
            }
        }

        return false;
    }

    protected function executeRoute(array $middlewares, callable $handler, Request $request): Response
    {
        $pipeline = array_merge($middlewares, [$handler]);

        return $this->executePipeline($pipeline, $request);
    }

    protected function executePipeline(array $pipeline, Request $request): Response
    {
        $handler = array_shift($pipeline);

        if (empty($pipeline)) {
            $result = $handler($request);
            return $result instanceof Response ? $result : new Response(200, [], $result);
        }

        $next = function ($request) use ($pipeline) {
            return $this->executePipeline($pipeline, $request);
        };

        $result = $handler($request, $next);

        return $result instanceof Response ? $result : new Response(200, [], $result);
    }

    abstract protected function getNotFoundMessage(string $path): string;
    abstract protected function getMethodNotAllowedMessage(): string;
}
