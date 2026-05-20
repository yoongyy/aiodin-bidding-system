<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;

interface AuctionRepositoryInterface
{
    /**
     * Ensure a product document exists and return it.
     *
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    public function ensureProduct(array $defaults, DateTimeImmutable $now): array;

    /**
     * @return array<string, mixed>|null
     */
    public function getProductBySlug(string $slug): ?array;

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    public function saveProduct(array $product): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listProducts(): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listBiddingsByProductSlug(string $productSlug): array;

    /**
     * @param array<string, mixed> $bidding
     */
    public function addBidding(array $bidding): void;

    public function clearBiddingsByProductSlug(string $productSlug): void;
}
