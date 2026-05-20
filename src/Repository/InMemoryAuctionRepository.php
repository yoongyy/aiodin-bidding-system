<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;

final class InMemoryAuctionRepository implements AuctionRepositoryInterface
{
    /**
     * @param array<int, array<string, mixed>>|null $products
     * @param array<int, array<string, mixed>>|null $biddings
     */
    public function __construct(
        private ?array $products = null,
        private ?array $biddings = null
    ) {
        $this->products ??= [];
        $this->biddings ??= [];
    }

    public function ensureProduct(array $defaults, DateTimeImmutable $now): array
    {
        $slug = (string) ($defaults['slug'] ?? 'single-product');
        $product = $this->getProductBySlug($slug);

        if ($product === null) {
            $product = $defaults;
            $product['createdAt'] = $product['createdAt'] ?? $now->format(DATE_ATOM);
            $product['updatedAt'] = $now->format(DATE_ATOM);
            $this->saveProduct($product);
        }

        return $product;
    }

    public function getProductBySlug(string $slug): ?array
    {
        foreach ($this->products as $product) {
            if (($product['slug'] ?? null) === $slug) {
                return $product;
            }
        }

        return null;
    }

    public function saveProduct(array $product): array
    {
        $slug = (string) ($product['slug'] ?? 'single-product');
        foreach ($this->products as $index => $existing) {
            if (($existing['slug'] ?? null) === $slug) {
                $this->products[$index] = $product;
                return $product;
            }
        }

        $this->products[] = $product;
        return $product;
    }

    public function listProducts(): array
    {
        return array_values($this->products);
    }

    public function listBiddingsByProductSlug(string $productSlug): array
    {
        $filtered = array_values(array_filter(
            $this->biddings,
            static fn (array $bidding): bool => ($bidding['productSlug'] ?? null) === $productSlug
        ));

        usort($filtered, static function (array $a, array $b): int {
            return strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? ''));
        });

        return $filtered;
    }

    public function addBidding(array $bidding): void
    {
        $this->biddings[] = $bidding;
    }

    public function clearBiddingsByProductSlug(string $productSlug): void
    {
        $this->biddings = array_values(array_filter(
            $this->biddings,
            static fn (array $bidding): bool => ($bidding['productSlug'] ?? null) !== $productSlug
        ));
    }
}
