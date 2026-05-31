<?php

declare(strict_types=1);

namespace App\Router;

use App\Request\Request;
use App\Response\Response;
use App\Exception\NotFoundException;
use App\Exception\MethodNotAllowedException;

final class WebRouter
{
    private array $routes = [];
    private array $groupMiddlewares = [];
    private string $groupPrefix = '';

    public function group(string $prefix, array $middlewares, callable $callback): void
    {
        $previousPrefix = $this->groupPrefix;
        $previousMiddlewares = $this->groupMiddlewares;

        $this->groupPrefix = $prefix;
        $this->groupMiddlewares = array_merge($this->groupMiddlewares, $middlewares);

        $callback($this);

        $this->groupPrefix = $previousPrefix;
        $this->groupMiddlewares = $previousMiddlewares;
    }

    public function addRoute(string $method, string $path, callable $handler, array $middlewares = []): void
    {
        $fullPath = $this->groupPrefix . '/' . ltrim($path, '/');

        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $fullPath,
            'handler' => $handler,
            'middlewares' => array_merge($this->groupMiddlewares, $middlewares),
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

                return $this->executeMiddlewareStack($route['middlewares'], $route['handler'], $request);
            }
        }

        if ($this->hasMatchingPath($path)) {
            throw new MethodNotAllowedException('HTTP method not allowed');
        }

        throw new NotFoundException('Web route not found: ' . $path);
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

    private function executeMiddlewareStack(array $middlewares, callable $handler, Request $request): Response
    {
        if (empty($middlewares)) {
            $result = $handler($request);
            return $result instanceof Response ? $result : new Response(200, [], $result);
        }

        $middleware = array_shift($middlewares);

        $next = function ($request) use ($middlewares, $handler) {
            return $this->executeMiddlewareStack($middlewares, $handler, $request);
        };

        $result = $middleware($request, $next);

        return $result instanceof Response ? $result : new Response(200, [], $result);
    }
}
