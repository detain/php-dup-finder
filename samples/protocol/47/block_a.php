<?php

declare(strict_types=1);

namespace App\Services\Soap;

use App\Services\UserService;
use App\Services\OrderService;
use App\Services\ProductService;
use App\Exceptions\ValidationException;
use App\Exceptions\NotFoundException;
use App\Exceptions\AuthenticationException;
use SoapFault;

class SoapServiceHandler
{
    private UserService $userService;
    private OrderService $orderService;
    private ProductService $productService;
    private array $authCredentials;

    public function __construct(
        UserService $userService,
        OrderService $orderService,
        ProductService $productService,
        array $authCredentials = []
    ) {
        $this->userService = $userService;
        $this->orderService = $orderService;
        $this->productService = $productService;
        $this->authCredentials = $authCredentials;
    }

    public function handleUserRequest(string $action, array $params): array
    {
        $this->authenticate($params);

        try {
            return match ($action) {
                'getUser' => $this->getUser($params),
                'createUser' => $this->createUser($params),
                'updateUser' => $this->updateUser($params),
                'deleteUser' => $this->deleteUser($params),
                'listUsers' => $this->listUsers($params),
                default => throw new \InvalidArgumentException("Unknown action: {$action}"),
            };
        } catch (NotFoundException $e) {
            throw new SoapFault('Receiver', 'User not found');
        } catch (ValidationException $e) {
            throw new SoapFault('Sender', $e->getMessage());
        } catch (AuthenticationException $e) {
            throw new SoapFault('Sender', 'Authentication failed');
        }
    }

    public function handleOrderRequest(string $action, array $params): array
    {
        $this->authenticate($params);

        try {
            return match ($action) {
                'getOrder' => $this->getOrder($params),
                'createOrder' => $this->createOrder($params),
                'updateOrderStatus' => $this->updateOrderStatus($params),
                'cancelOrder' => $this->cancelOrder($params),
                'listOrders' => $this->listOrders($params),
                default => throw new \InvalidArgumentException("Unknown action: {$action}"),
            };
        } catch (NotFoundException $e) {
            throw new SoapFault('Receiver', 'Order not found');
        } catch (ValidationException $e) {
            throw new SoapFault('Sender', $e->getMessage());
        } catch (AuthenticationException $e) {
            throw new SoapFault('Sender', 'Authentication failed');
        }
    }

    public function handleProductRequest(string $action, array $params): array
    {
        $this->authenticate($params);

        try {
            return match ($action) {
                'getProduct' => $this->getProduct($params),
                'createProduct' => $this->createProduct($params),
                'updateProduct' => $this->updateProduct($params),
                'deleteProduct' => $this->deleteProduct($params),
                'listProducts' => $this->listProducts($params),
                'searchProducts' => $this->searchProducts($params),
                default => throw new \InvalidArgumentException("Unknown action: {$action}"),
            };
        } catch (NotFoundException $e) {
            throw new SoapFault('Receiver', 'Product not found');
        } catch (ValidationException $e) {
            throw new SoapFault('Sender', $e->getMessage());
        } catch (AuthenticationException $e) {
            throw new SoapFault('Sender', 'Authentication failed');
        }
    }

    private function authenticate(array $params): void
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

    private function getUser(array $params): array
    {
        $user = $this->userService->findById($params['id']);

        if (!$user) {
            throw new NotFoundException('User not found');
        }

        return $this->formatUser($user);
    }

    private function createUser(array $params): array
    {
        $user = $this->userService->createUser([
            'email' => $params['email'],
            'name' => $params['name'],
            'password' => $params['password'],
            'phone' => $params['phone'] ?? null,
        ]);

        return $this->formatUser($user);
    }

    private function updateUser(array $params): array
    {
        $user = $this->userService->updateUser($params['id'], [
            'email' => $params['email'] ?? null,
            'name' => $params['name'] ?? null,
            'phone' => $params['phone'] ?? null,
        ]);

        return $this->formatUser($user);
    }

    private function deleteUser(array $params): array
    {
        $this->userService->deleteUser($params['id']);

        return ['success' => true];
    }

    private function listUsers(array $params): array
    {
        $limit = $params['limit'] ?? 50;
        $offset = $params['offset'] ?? 0;

        $users = $this->userService->listUsers($limit, $offset);

        return [
            'users' => array_map([$this, 'formatUser'], $users),
            'total' => $this->userService->countUsers(),
        ];
    }

    private function getOrder(array $params): array
    {
        $order = $this->orderService->findById($params['id']);

        if (!$order) {
            throw new NotFoundException('Order not found');
        }

        return $this->formatOrder($order);
    }

    private function createOrder(array $params): array
    {
        $order = $this->orderService->createOrder([
            'customer_email' => $params['customer_email'],
            'items' => $params['items'],
            'shipping_address' => $params['shipping_address'] ?? null,
        ]);

        return $this->formatOrder($order);
    }

    private function updateOrderStatus(array $params): array
    {
        $order = $this->orderService->updateStatus($params['id'], $params['status']);

        return $this->formatOrder($order);
    }

    private function cancelOrder(array $params): array
    {
        $this->orderService->cancel($params['id']);

        return ['success' => true];
    }

    private function listOrders(array $params): array
    {
        $limit = $params['limit'] ?? 50;
        $offset = $params['offset'] ?? 0;

        $orders = $this->orderService->listOrders($limit, $offset);

        return [
            'orders' => array_map([$this, 'formatOrder'], $orders),
            'total' => $this->orderService->countOrders(),
        ];
    }

    private function getProduct(array $params): array
    {
        $product = $this->productService->findById($params['id']);

        if (!$product) {
            throw new NotFoundException('Product not found');
        }

        return $this->formatProduct($product);
    }

    private function createProduct(array $params): array
    {
        $product = $this->productService->createProduct([
            'sku' => $params['sku'],
            'name' => $params['name'],
            'price' => $params['price'],
            'description' => $params['description'] ?? null,
        ]);

        return $this->formatProduct($product);
    }

    private function updateProduct(array $params): array
    {
        $product = $this->productService->updateProduct($params['id'], $params);

        return $this->formatProduct($product);
    }

    private function deleteProduct(array $params): array
    {
        $this->productService->deleteProduct($params['id']);

        return ['success' => true];
    }

    private function listProducts(array $params): array
    {
        $limit = $params['limit'] ?? 50;
        $offset = $params['offset'] ?? 0;

        $products = $this->productService->listProducts($limit, $offset);

        return [
            'products' => array_map([$this, 'formatProduct'], $products),
            'total' => $this->productService->countProducts(),
        ];
    }

    private function searchProducts(array $params): array
    {
        $products = $this->productService->search($params['query'] ?? '');

        return [
            'products' => array_map([$this, 'formatProduct'], $products),
            'total' => count($products),
        ];
    }

    private function formatUser(array $user): array
    {
        return [
            'id' => $user['id'],
            'email' => $user['email'],
            'name' => $user['name'],
            'phone' => $user['phone'] ?? null,
            'status' => $user['status'],
        ];
    }

    private function formatOrder(array $order): array
    {
        return [
            'id' => $order['id'],
            'order_number' => $order['order_number'],
            'customer_email' => $order['customer_email'],
            'status' => $order['status'],
            'total' => $order['total'],
            'created_at' => $order['created_at'],
        ];
    }

    private function formatProduct(array $product): array
    {
        return [
            'id' => $product['id'],
            'sku' => $product['sku'],
            'name' => $product['name'],
            'price' => $product['price'],
            'inventory_count' => $product['inventory_count'] ?? 0,
        ];
    }
}
