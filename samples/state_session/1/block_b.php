<?php
declare(strict_types=1);

namespace Cart\Session;

use Doctrine\ORM\EntityManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

final class CartStateService
{
    private const CART_COOKIE = 'shopping_cart_id';
    private const CART_TTL_DAYS = 30;
    private const CART_CACHE_PREFIX = 'cart:';
    private const CART_CACHE_TTL = 7200;

    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly LoggerInterface $logger,
        private readonly CacheService $cache,
        private readonly PriceCalculator $priceCalculator
    ) {}

    public function getCart(Request $request): Cart
    {
        $cartId = $request->cookies->get(self::CART_COOKIE);

        if ($cartId !== null) {
            return $this->loadCart($cartId);
        }

        // Create new cart
        $cart = new ShoppingCart();
        $cart->setCreatedAt(new \DateTimeImmutable());
        $cart->setUpdatedAt(new \DateTimeImmutable());
        $cart->setStatus('active');

        $this->entityManager->persist($cart);
        $this->entityManager->flush();

        $this->logger->info('Created new shopping cart', [
            'cart_id' => $cart->getId()
        ]);

        return $cart;
    }

    private function loadCart(string $cartId): Cart
    {
        // Try cache first
        $cacheKey = self::CART_CACHE_PREFIX . $cartId;
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $data = json_decode($cached, true);

            // Validate expiry (cookie handles this, but double-check)
            $expiresAt = new \DateTimeImmutable($data['expires_at']);
            if ($expiresAt > new \DateTimeImmutable()) {
                return $this->hydrateCartFromCache($data);
            }

            // Expired - will need to remove from all stores
            $this->invalidateCart($cartId);
        }

        // Load from database
        $cart = $this->entityManager->find(ShoppingCart::class, $cartId);

        if ($cart === null) {
            // Cart doesn't exist - create new one
            $cart = $this->createNewCart();
            $this->setCartCookie($cart->getId());
            return $cart;
        }

        if ($cart->getStatus() === 'converted' || $cart->getStatus() === 'abandoned') {
            // Old cart - start fresh
            $cart = $this->createNewCart();
            $this->setCartCookie($cart->getId());
            return $cart;
        }

        // Update cache
        $this->cacheCart($cart);

        return $cart;
    }

    public function addItem(Request $request, int $productId, int $quantity): JsonResponse
    {
        $cart = $this->getCart($request);
        $product = $this->entityManager->find(Product::class, $productId);

        if ($product === null) {
            return new JsonResponse(['error' => 'Product not found'], 404);
        }

        // Check existing item
        $existingItem = null;
        foreach ($cart->getItems() as $item) {
            if ($item->getProduct()->getId() === $productId) {
                $existingItem = $item;
                break;
            }
        }

        if ($existingItem !== null) {
            $existingItem->setQuantity($existingItem->getQuantity() + $quantity);
        } else {
            $item = new CartItem();
            $item->setCart($cart);
            $item->setProduct($product);
            $item->setQuantity($quantity);
            $item->setPriceSnapshot($product->getPrice());
            $cart->addItem($item);
        }

        $cart->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->flush();

        // Update cache
        $this->cacheCart($cart);

        $this->logger->info('Added item to cart', [
            'cart_id' => $cart->getId(),
            'product_id' => $productId,
            'quantity' => $quantity
        ]);

        return new JsonResponse([
            'cart_id' => $cart->getId(),
            'item_count' => $cart->getItemCount(),
            'subtotal' => $this->priceCalculator->calculateSubtotal($cart)
        ]);
    }

    private function cacheCart(ShoppingCart $cart): void
    {
        $cacheKey = self::CART_CACHE_PREFIX . $cart->getId();

        $data = [
            'id' => $cart->getId(),
            'status' => $cart->getStatus(),
            'items' => array_map(fn($item) => [
                'product_id' => $item->getProduct()->getId(),
                'quantity' => $item->getQuantity(),
                'price' => $item->getPriceSnapshot()
            ], $cart->getItems()->toArray()),
            'subtotal' => $this->priceCalculator->calculateSubtotal($cart),
            'expires_at' => $cart->getUpdatedAt()
                ->modify('+' . self::CART_TTL_DAYS . ' days')
                ->format('c'),
            'updated_at' => $cart->getUpdatedAt()->format('c')
        ];

        try {
            $this->cache->set($cacheKey, json_encode($data), self::CART_CACHE_TTL);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to cache cart', [
                'cart_id' => $cart->getId(),
                'error' => $e->getMessage()
            ]);
        }
    }

    private function hydrateCartFromCache(array $data): ShoppingCart
    {
        $cart = $this->entityManager->find(ShoppingCart::class, $data['id']);

        if ($cart === null) {
            $cart = new ShoppingCart();
            $cart->setId($data['id']);
            $cart->setCreatedAt(new \DateTimeImmutable());
        }

        return $cart;
    }

    private function invalidateCart(string $cartId): void
    {
        $this->cache->delete(self::CART_CACHE_PREFIX . $cartId);

        $cart = $this->entityManager->find(ShoppingCart::class, $cartId);
        if ($cart !== null) {
            $cart->setStatus('abandoned');
            $this->entityManager->flush();
        }
    }

    private function createNewCart(): ShoppingCart
    {
        $cart = new ShoppingCart();
        $cart->setCreatedAt(new \DateTimeImmutable());
        $cart->setUpdatedAt(new \DateTimeImmutable());
        $cart->setStatus('active');

        $this->entityManager->persist($cart);
        $this->entityManager->flush();

        return $cart;
    }

    private function setCartCookie(int $cartId): void
    {
        // Cookie is set via response headers in controller
    }
}
