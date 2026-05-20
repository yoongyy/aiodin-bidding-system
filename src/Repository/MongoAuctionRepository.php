<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Query;
use MongoDB\Driver\WriteConcern;
use RuntimeException;

final class MongoAuctionRepository implements AuctionRepositoryInterface
{
    private Manager $manager;

    public function __construct(
        string $uri,
        private string $database,
        private string $productsCollection,
        private string $biddingsCollection,
        ?string $username = null,
        ?string $password = null
    ) {
        if (!extension_loaded('mongodb')) {
            throw new RuntimeException('The mongodb PHP extension is required for MongoAuctionRepository.');
        }

        $this->manager = new Manager($this->buildUri($uri, $username, $password));
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
        $query = new Query(['slug' => $slug], ['limit' => 1]);
        $cursor = $this->manager->executeQuery($this->productsNamespace(), $query);

        foreach ($cursor as $document) {
            $normalized = $this->normalize($document);
            if (is_array($normalized)) {
                unset($normalized['_id']);
            }

            return $normalized;
        }

        return null;
    }

    public function saveProduct(array $product): array
    {
        $bulk = new BulkWrite();
        $bulk->update(
            ['slug' => $product['slug']],
            ['$set' => $this->denormalize($product)],
            ['upsert' => true]
        );

        $this->manager->executeBulkWrite(
            $this->productsNamespace(),
            $bulk,
            ['writeConcern' => new WriteConcern(WriteConcern::MAJORITY, 1000)]
        );

        return $product;
    }

    public function listProducts(): array
    {
        $query = new Query([], ['sort' => ['createdAt' => 1]]);
        $cursor = $this->manager->executeQuery($this->productsNamespace(), $query);

        $rows = [];
        foreach ($cursor as $document) {
            $normalized = $this->normalize($document);
            if (is_array($normalized)) {
                unset($normalized['_id']);
                $rows[] = $normalized;
            }
        }

        return $rows;
    }

    public function listBiddingsByProductSlug(string $productSlug): array
    {
        $query = new Query(['productSlug' => $productSlug], ['sort' => ['createdAt' => -1]]);
        $cursor = $this->manager->executeQuery($this->biddingsNamespace(), $query);

        $rows = [];
        foreach ($cursor as $document) {
            $normalized = $this->normalize($document);
            if (is_array($normalized)) {
                unset($normalized['_id']);
                $rows[] = $normalized;
            }
        }

        return $rows;
    }

    public function addBidding(array $bidding): void
    {
        $bulk = new BulkWrite();
        $bulk->insert($this->denormalize($bidding));
        $this->manager->executeBulkWrite(
            $this->biddingsNamespace(),
            $bulk,
            ['writeConcern' => new WriteConcern(WriteConcern::MAJORITY, 1000)]
        );
    }

    public function clearBiddingsByProductSlug(string $productSlug): void
    {
        $bulk = new BulkWrite();
        $bulk->delete(['productSlug' => $productSlug], ['limit' => 0]);
        $this->manager->executeBulkWrite(
            $this->biddingsNamespace(),
            $bulk,
            ['writeConcern' => new WriteConcern(WriteConcern::MAJORITY, 1000)]
        );
    }

    private function productsNamespace(): string
    {
        return $this->database . '.' . $this->productsCollection;
    }

    private function biddingsNamespace(): string
    {
        return $this->database . '.' . $this->biddingsCollection;
    }

    private function buildUri(string $uri, ?string $username, ?string $password): string
    {
        if ($username === null || $username === '' || $password === null || $password === '') {
            return $this->withDefaultTimeouts($uri);
        }

        $parts = parse_url($uri);
        if ($parts === false) {
            throw new RuntimeException('Invalid MongoDB URI.');
        }

        $scheme = $parts['scheme'] ?? 'mongodb';
        $host = $parts['host'] ?? '127.0.0.1';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        $built = sprintf(
            '%s://%s:%s@%s%s%s%s',
            $scheme,
            rawurlencode($username),
            rawurlencode($password),
            $host,
            $port,
            $path,
            $query
        );

        return $this->withDefaultTimeouts($built);
    }

    private function withDefaultTimeouts(string $uri): string
    {
        $parts = explode('?', $uri, 2);
        $base = $parts[0];
        $queryString = $parts[1] ?? '';

        $params = [];
        if ($queryString !== '') {
            parse_str($queryString, $params);
        }

        if (!isset($params['connectTimeoutMS'])) {
            $params['connectTimeoutMS'] = '3000';
        }
        if (!isset($params['serverSelectionTimeoutMS'])) {
            $params['serverSelectionTimeoutMS'] = '3000';
        }

        return $base . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param mixed $value
     */
    private function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalize($item);
            }
            return $normalized;
        }

        if (is_object($value)) {
            $normalized = [];
            foreach (get_object_vars($value) as $key => $item) {
                $normalized[$key] = $this->normalize($item);
            }
            return $normalized;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function denormalize(array $document): array
    {
        unset($document['_id']);
        return $document;
    }
}
