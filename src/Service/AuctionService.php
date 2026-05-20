<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\AuctionClosedException;
use App\Exception\ValidationException;
use App\Repository\AuctionRepositoryInterface;
use App\Support\Clock;
use App\Support\DefaultAuction;
use DateInterval;
use DateTimeImmutable;

final class AuctionService
{
    public function __construct(
        private AuctionRepositoryInterface $repository,
        private Clock $clock,
        private ?array $defaults = null,
        private ?array $productCatalog = null
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getState(?string $productSlug = null): array
    {
        $now = $this->clock->now();
        $slug = $this->resolveProductSlug($productSlug, $now);
        $product = $this->requireProductBySlug($slug, $now);
        $biddings = $this->repository->listBiddingsByProductSlug($slug);
        $product = $this->expireIfNeeded($product, $biddings, $now);

        return $this->present($product, $biddings, $now);
    }

    /**
     * @return array<string, mixed>
     */
    public function placeBid(string $productSlug, string $bidderName, float $amount, ?string $sessionId = null): array
    {
        $now = $this->clock->now();
        $slug = $this->resolveProductSlug($productSlug, $now);
        $product = $this->requireProductBySlug($slug, $now);
        $biddings = $this->repository->listBiddingsByProductSlug($slug);
        $product = $this->expireIfNeeded($product, $biddings, $now);

        if (($product['status'] ?? 'scheduled') === 'ended') {
            throw new AuctionClosedException('The auction has already ended.');
        }

        $bidderName = $this->normalizeBidderName($bidderName);
        $amount = round($amount, 2);
        $errors = [];

        if ($bidderName === '') {
            $errors['bidderName'] = 'Bidder name is required.';
        }
        if ($amount <= 0) {
            $errors['amount'] = 'Bid amount must be greater than zero.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $latestBid = $biddings[0] ?? null;
        $currentBid = isset($latestBid['amount']) ? (float) $latestBid['amount'] : null;
        $startingPrice = (float) ($product['startingPrice'] ?? 0);

        if ($currentBid === null && $amount < $startingPrice) {
            throw new ValidationException([
                'amount' => sprintf('The first bid must be at least %s.', $this->formatMoney($startingPrice, (string) $product['currency'])),
            ]);
        }

        if ($currentBid !== null && $amount <= $currentBid) {
            throw new ValidationException([
                'amount' => sprintf('The next bid must be higher than %s.', $this->formatMoney($currentBid, (string) $product['currency'])),
            ]);
        }

        if (($product['status'] ?? 'scheduled') === 'scheduled') {
            $product['status'] = 'live';
            $product['startedAt'] = $now->format(DATE_ATOM);
        }

        $product['endsAt'] = $now->add(new DateInterval('PT' . (int) ($product['durationSeconds'] ?? 60) . 'S'))->format(DATE_ATOM);
        $product['endedAt'] = null;
        $product['winner'] = null;
        $product['updatedAt'] = $now->format(DATE_ATOM);

        $this->repository->addBidding([
            'productSlug' => $slug,
            'bidderName' => $bidderName,
            'amount' => $amount,
            'sessionId' => $sessionId,
            'createdAt' => $now->format(DATE_ATOM),
        ]);

        $this->repository->saveProduct($product);
        $biddings = $this->repository->listBiddingsByProductSlug($slug);

        return $this->present($product, $biddings, $now);
    }

    /**
     * @return array<string, mixed>
     */
    public function reset(?string $productSlug = null): array
    {
        $now = $this->clock->now();
        $slug = $this->resolveProductSlug($productSlug, $now);
        $product = $this->requireDefaultBySlug($slug, $now);

        $product['createdAt'] = $now->format(DATE_ATOM);
        $product['updatedAt'] = $now->format(DATE_ATOM);
        $product['status'] = 'scheduled';
        $product['startedAt'] = null;
        $product['endsAt'] = null;
        $product['endedAt'] = null;
        $product['winner'] = null;

        $this->repository->saveProduct($product);
        $this->repository->clearBiddingsByProductSlug($slug);

        return $this->present($product, [], $now);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listProducts(): array
    {
        $now = $this->clock->now();
        $this->seedProducts($now);

        $rows = [];
        foreach ($this->productCatalog($now) as $defaults) {
            $slug = (string) ($defaults['slug'] ?? '');
            if ($slug === '') {
                continue;
            }

            $product = $this->repository->ensureProduct($defaults, $now);
            $biddings = $this->repository->listBiddingsByProductSlug($slug);
            $product = $this->expireIfNeeded($product, $biddings, $now);
            $state = $this->present($product, $biddings, $now);

            $rows[] = [
                'slug' => $state['slug'],
                'title' => $state['title'],
                'description' => $state['description'],
                'imageUrl' => $state['imageUrl'],
                'currency' => $state['currency'],
                'startingPrice' => $state['startingPrice'],
                'currentBid' => $state['currentBid'],
                'displayPrice' => $state['displayPrice'],
                'status' => $state['status'],
                'statusLabel' => $state['statusLabel'],
                'bidCount' => $state['bidCount'],
                'lastBidderName' => $state['bids'][0]['bidderName'] ?? null,
                'countdownSecondsRemaining' => $state['countdown']['secondsRemaining'],
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $product
     * @param array<int, array<string, mixed>> $biddings
     * @return array<string, mixed>
     */
    private function expireIfNeeded(array $product, array $biddings, DateTimeImmutable $now): array
    {
        if (($product['status'] ?? 'scheduled') !== 'live') {
            return $product;
        }

        $endsAt = $this->parseDate($product['endsAt'] ?? null);
        if ($endsAt === null || $now < $endsAt) {
            return $product;
        }

        $product['status'] = 'ended';
        $product['endedAt'] = $now->format(DATE_ATOM);
        $product['updatedAt'] = $now->format(DATE_ATOM);

        $latestBid = $biddings[0] ?? null;
        if ($latestBid !== null) {
            $product['winner'] = [
                'bidderName' => $latestBid['bidderName'] ?? null,
                'amount' => (float) ($latestBid['amount'] ?? 0),
                'endedAt' => $now->format(DATE_ATOM),
            ];
        }

        $this->repository->saveProduct($product);
        return $product;
    }

    /**
     * @param array<string, mixed> $product
     * @param array<int, array<string, mixed>> $biddings
     * @return array<string, mixed>
     */
    private function present(array $product, array $biddings, DateTimeImmutable $now): array
    {
        $status = (string) ($product['status'] ?? 'scheduled');
        $latestBid = $biddings[0] ?? null;
        $currentBid = isset($latestBid['amount']) ? (float) $latestBid['amount'] : null;
        $startingPrice = (float) ($product['startingPrice'] ?? 0);
        $displayPrice = $currentBid ?? $startingPrice;
        $endsAt = $this->parseDate($product['endsAt'] ?? null);
        $secondsRemaining = 0;

        if ($status === 'live' && $endsAt !== null) {
            $secondsRemaining = max(0, $endsAt->getTimestamp() - $now->getTimestamp());
        }

        return [
            'slug' => (string) $product['slug'],
            'title' => (string) $product['title'],
            'description' => (string) $product['description'],
            'imageUrl' => (string) ($product['imageUrl'] ?? ''),
            'currency' => (string) $product['currency'],
            'startingPrice' => $startingPrice,
            'currentBid' => $currentBid,
            'displayPrice' => $displayPrice,
            'status' => $status,
            'statusLabel' => $this->statusLabel($status),
            'statusTone' => $this->statusTone($status),
            'countdown' => [
                'secondsRemaining' => $secondsRemaining,
                'endsAt' => $product['endsAt'] ?? null,
                'startedAt' => $product['startedAt'] ?? null,
            ],
            'winner' => $product['winner'] ?? null,
            'bidCount' => count($biddings),
            'bids' => $biddings,
            'startedAt' => $product['startedAt'] ?? null,
            'endedAt' => $product['endedAt'] ?? null,
            'lastUpdatedAt' => $product['updatedAt'] ?? null,
        ];
    }

    private function normalizeBidderName(string $bidderName): string
    {
        $bidderName = trim(preg_replace('/\s+/u', ' ', $bidderName) ?? '');
        return substr($bidderName, 0, 80);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(DateTimeImmutable $now): array
    {
        return $this->productCatalog($now)[0];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function productCatalog(DateTimeImmutable $now): array
    {
        if ($this->productCatalog !== null && $this->productCatalog !== []) {
            return $this->productCatalog;
        }

        if ($this->defaults !== null) {
            $this->productCatalog = [$this->defaults];
            return $this->productCatalog;
        }

        $this->productCatalog = DefaultAuction::catalogFromEnv($now);
        return $this->productCatalog;
    }

    private function seedProducts(DateTimeImmutable $now): void
    {
        foreach ($this->productCatalog($now) as $defaults) {
            $product = $this->repository->ensureProduct($defaults, $now);

            $product['title'] = $defaults['title'] ?? ($product['title'] ?? '');
            $product['description'] = $defaults['description'] ?? ($product['description'] ?? '');
            $product['imageUrl'] = $defaults['imageUrl'] ?? ($product['imageUrl'] ?? '');
            $product['currency'] = $defaults['currency'] ?? ($product['currency'] ?? 'MYR');

            if (($product['status'] ?? 'scheduled') === 'scheduled') {
                $product['startingPrice'] = $defaults['startingPrice'] ?? ($product['startingPrice'] ?? 0);
                $product['durationSeconds'] = $defaults['durationSeconds'] ?? ($product['durationSeconds'] ?? 60);
            }

            $this->repository->saveProduct($product);
        }
    }

    private function resolveProductSlug(?string $productSlug, DateTimeImmutable $now): string
    {
        $this->seedProducts($now);
        $catalog = $this->productCatalog($now);
        $catalogSlugs = array_column($catalog, 'slug');

        $slug = trim((string) ($productSlug ?? ''));
        if ($slug !== '') {
            if (!in_array($slug, $catalogSlugs, true) || $this->repository->getProductBySlug($slug) === null) {
                throw new ValidationException([
                    'productSlug' => 'Unknown product.',
                ]);
            }
            return $slug;
        }

        $defaults = $this->defaults($now);
        return (string) ($defaults['slug'] ?? 'single-product');
    }

    /**
     * @return array<string, mixed>
     */
    private function requireProductBySlug(string $slug, DateTimeImmutable $now): array
    {
        $product = $this->repository->getProductBySlug($slug);
        if ($product !== null) {
            return $product;
        }

        $fallback = $this->requireDefaultBySlug($slug, $now);
        return $this->repository->ensureProduct($fallback, $now);
    }

    /**
     * @return array<string, mixed>
     */
    private function requireDefaultBySlug(string $slug, DateTimeImmutable $now): array
    {
        foreach ($this->productCatalog($now) as $defaults) {
            if ((string) ($defaults['slug'] ?? '') === $slug) {
                return $defaults;
            }
        }

        throw new ValidationException([
            'productSlug' => 'Unknown product.',
        ]);
    }

    private function formatMoney(float $amount, string $currency): string
    {
        return $currency . ' ' . number_format($amount, 2, '.', ',');
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'live' => 'In progress',
            'ended' => 'Ended',
            default => 'Waiting for first bid',
        };
    }

    private function statusTone(string $status): string
    {
        return match ($status) {
            'live' => 'live',
            'ended' => 'ended',
            default => 'idle',
        };
    }

    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
