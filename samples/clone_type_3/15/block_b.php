<?php

declare(strict_types=1);

namespace App\Router;

use App\Request\Request;
use App\Response\Response;
use App\Exception\NotFoundException;
use App\Exception\MethodNotAllowedException;

final class ApiRouter
{
    private array $routes = [];
    private array $middlewares = [];

    public function addRoute(string $method, string $path, callable $handler, array $middlewares = []): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'handler' => $handler,
            'middlewares' => $middlewares,
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

                $pipeline = array_merge(
                    $this->middlewares,
                    $route['middlewares'],
                    [$route['handler']]
                );

                return $this->executePipeline($pipeline, $request);
            }
        }

        if ($this->hasMatchingPath($path)) {
            throw new MethodNotAllowedException('Method not allowed for this path');
        }

        throw new NotFoundException('API route not found: ' . $path);
    }

    public function addMiddleware(callable $middleware): void
    {
        $this->middlewares[] = $middleware;
    }

    private function matchPath(string $routePath, string $requestPath): array|false
    {
        $routeParts = explode('/', trim($routePath, '/'));
        $pathParts = explode('/', trim($requestPath, '/'));

        if (count($routeParts) !== count($pathParts)) {
            return false;
        }

        $params = [];

        for ($i = 0; $i < count($routeParts); $i++) {
            if (str_starts_with($routeParts[$i], '{') && str_ends_with($routeParts[$i], '}')) {
                $paramName = trim($routeParts[$i], '{}');
                $params[$paramName] = $pathParts[$i];
            } elseif ($routeParts[$i] !== $pathParts[$i]) {
                return false;
            }
        }

        return $params;
    }

    private function hasMatchingPath(string $path): bool
    {
        foreach ($this->routes as $route) {
            if ($this->matchPath($route['path'], $path) !== false) {
                return true;
            }
        }

        return false;
    }

    private function executePipeline(array $pipeline, Request $request): Response
    {
        $handler = array_shift($pipeline);

        if (empty($pipeline)) {
            $result = $handler($request);
            return $result instanceof Response ? $result : new Response(200, [], $result);
        }

        $next = function ($request) use ($pipeline) {
            return $this->executePipeline($pipeline, $request);
        };

        return $handler($request, $next);
    }
}
