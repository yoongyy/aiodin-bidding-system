<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use RuntimeException;

final class FileAuctionRepository implements AuctionRepositoryInterface
{
    public function __construct(
        private string $productsPath,
        private string $biddingsPath
    ) {
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
        $products = $this->readProducts();
        foreach ($products as $product) {
            if (($product['slug'] ?? null) === $slug) {
                return $product;
            }
        }

        return null;
    }

    public function saveProduct(array $product): array
    {
        $products = $this->readProducts();
        $slug = (string) ($product['slug'] ?? 'single-product');
        $saved = false;

        foreach ($products as $index => $existing) {
            if (($existing['slug'] ?? null) === $slug) {
                $products[$index] = $product;
                $saved = true;
                break;
            }
        }

        if (!$saved) {
            $products[] = $product;
        }

        $this->writeJson($this->productsPath, $products);

        return $product;
    }

    public function listProducts(): array
    {
        return $this->readProducts();
    }

    public function listBiddingsByProductSlug(string $productSlug): array
    {
        $all = $this->readBiddings();
        $filtered = array_values(array_filter(
            $all,
            static fn (array $bidding): bool => ($bidding['productSlug'] ?? null) === $productSlug
        ));

        usort($filtered, static function (array $a, array $b): int {
            return strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? ''));
        });

        return $filtered;
    }

    public function addBidding(array $bidding): void
    {
        $all = $this->readBiddings();
        $all[] = $bidding;
        $this->writeJson($this->biddingsPath, $all);
    }

    public function clearBiddingsByProductSlug(string $productSlug): void
    {
        $all = $this->readBiddings();
        $kept = array_values(array_filter(
            $all,
            static fn (array $bidding): bool => ($bidding['productSlug'] ?? null) !== $productSlug
        ));
        $this->writeJson($this->biddingsPath, $kept);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readProducts(): array
    {
        return $this->readJsonList($this->productsPath);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readBiddings(): array
    {
        return $this->readJsonList($this->biddingsPath);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readJsonList(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $json = file_get_contents($path);
        if ($json === false || trim($json) === '') {
            return [];
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($data)) {
            return [];
        }

        $rows = [];
        foreach ($data as $item) {
            if (is_array($item)) {
                $rows[] = $item;
            }
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function writeJson(string $path, array $rows): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create storage directory: %s', $directory));
        }

        $tmp = $path . '.tmp';
        $encoded = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($tmp, $encoded, LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Unable to write temporary file: %s', $tmp));
        }

        if (!rename($tmp, $path)) {
            throw new RuntimeException(sprintf('Unable to persist file: %s', $path));
        }
    }
}
