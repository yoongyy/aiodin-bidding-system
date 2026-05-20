<?php

declare(strict_types=1);

namespace Tests\Backend;

use App\Exception\AuctionClosedException;
use App\Exception\ValidationException;
use App\Repository\InMemoryAuctionRepository;
use App\Service\AuctionService;
use App\Support\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AuctionServiceTest extends TestCase
{
    private DateTimeImmutable $now;
    private const PRODUCT_SLUG = 'single-product';

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-05-20T10:00:00+00:00');
    }

    public function testAuctionStartsInScheduledState(): void
    {
        $service = $this->makeService();
        $state = $service->getState(self::PRODUCT_SLUG);

        self::assertSame('scheduled', $state['status']);
        self::assertSame('Waiting for first bid', $state['statusLabel']);
        self::assertSame(0, $state['bidCount']);
        self::assertSame(null, $state['currentBid']);
        self::assertSame(0, $state['countdown']['secondsRemaining']);
    }

    public function testFirstValidBidStartsTheCountdown(): void
    {
        $service = $this->makeService();
        $state = $service->placeBid(self::PRODUCT_SLUG, 'Aina', 500.00, 'session-1');

        self::assertSame('live', $state['status']);
        self::assertSame(500.00, $state['currentBid']);
        self::assertSame(null, $state['winner']);
        self::assertSame(1, $state['bidCount']);
        self::assertSame(60, $state['countdown']['secondsRemaining']);
        self::assertSame('Aina', $state['bids'][0]['bidderName']);
    }

    public function testLowerBidIsRejectedAfterAnOpeningBid(): void
    {
        $service = $this->makeService();
        $service->placeBid(self::PRODUCT_SLUG, 'Aina', 500.00);

        $this->expectException(ValidationException::class);
        $service->placeBid(self::PRODUCT_SLUG, 'Ben', 499.99);
    }

    public function testNewHigherBidResetsCountdownBackToFullDuration(): void
    {
        $clock = new FrozenClock($this->now);
        $repository = new InMemoryAuctionRepository();
        $service = new AuctionService($repository, $clock, $this->defaults());

        $firstState = $service->placeBid(self::PRODUCT_SLUG, 'Aina', 500.00);
        $firstEndsAt = new DateTimeImmutable((string) $firstState['countdown']['endsAt']);

        $clock->travelTo($this->now->add(new \DateInterval('PT20S')));
        $secondState = $service->placeBid(self::PRODUCT_SLUG, 'Ben', 510.00);
        $secondEndsAt = new DateTimeImmutable((string) $secondState['countdown']['endsAt']);

        self::assertSame(60, $secondState['countdown']['secondsRemaining']);
        self::assertTrue($secondEndsAt > $firstEndsAt);
    }

    public function testAuctionEndsAfterCountdownAndRejectsLateBids(): void
    {
        $clock = new FrozenClock($this->now);
        $repository = new InMemoryAuctionRepository();
        $service = new AuctionService($repository, $clock, $this->defaults());

        $service->placeBid(self::PRODUCT_SLUG, 'Aina', 500.00);

        $clock->travelTo($this->now->add(new \DateInterval('PT61S')));
        $state = $service->getState(self::PRODUCT_SLUG);

        self::assertSame('ended', $state['status']);
        self::assertSame('Aina', $state['winner']['bidderName']);
        self::assertSame(0, $state['countdown']['secondsRemaining']);

        $this->expectException(AuctionClosedException::class);
        $service->placeBid(self::PRODUCT_SLUG, 'Chris', 550.00);
    }

    public function testEachProductHasIndependentAuctionState(): void
    {
        $clock = new FrozenClock($this->now);
        $repository = new InMemoryAuctionRepository();
        $service = new AuctionService($repository, $clock, null, [
            $this->defaultsFor('iphone-15-pro', 5000.00),
            $this->defaultsFor('macbook-pro-16', 9500.00),
        ]);

        $service->placeBid('iphone-15-pro', 'Aina', 5000.00);
        $service->placeBid('macbook-pro-16', 'Ben', 9500.00);
        $clock->travelTo($this->now->add(new \DateInterval('PT1S')));
        $service->placeBid('iphone-15-pro', 'Chris', 5200.00);

        $iphoneState = $service->getState('iphone-15-pro');
        $macbookState = $service->getState('macbook-pro-16');

        self::assertSame(2, $iphoneState['bidCount']);
        self::assertSame('Chris', $iphoneState['bids'][0]['bidderName']);
        self::assertSame(1, $macbookState['bidCount']);
        self::assertSame('Ben', $macbookState['bids'][0]['bidderName']);
    }

    public function testUnknownProductSlugIsRejected(): void
    {
        $service = $this->makeService();

        try {
            $service->getState('unknown-product');
            self::fail('Expected ValidationException for unknown product state lookup.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('productSlug', $exception->errors());
        }

        try {
            $service->placeBid('unknown-product', 'Aina', 999.00);
            self::fail('Expected ValidationException for unknown product bid.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('productSlug', $exception->errors());
        }
    }

    public function testListProductsUsesCatalogOrder(): void
    {
        $service = new AuctionService(
            new InMemoryAuctionRepository(),
            new FrozenClock($this->now),
            null,
            [
                $this->defaultsFor('iphone-15-pro', 5000.00),
                $this->defaultsFor('cash-voucher', 1000.00),
                $this->defaultsFor('macbook-pro-16', 9500.00),
            ]
        );

        $products = $service->listProducts();
        self::assertSame(['iphone-15-pro', 'cash-voucher', 'macbook-pro-16'], array_column($products, 'slug'));
    }

    public function testScheduledProductMetadataIsSyncedFromCatalog(): void
    {
        $repository = new InMemoryAuctionRepository(
            [
                [
                    'slug' => 'iphone-15-pro',
                    'title' => 'Old title',
                    'description' => 'Old description',
                    'imageUrl' => 'https://old.example/old.jpg',
                    'currency' => 'USD',
                    'startingPrice' => 1.0,
                    'durationSeconds' => 10,
                    'status' => 'scheduled',
                    'startedAt' => null,
                    'endsAt' => null,
                    'endedAt' => null,
                    'winner' => null,
                    'createdAt' => $this->now->format(DATE_ATOM),
                    'updatedAt' => $this->now->format(DATE_ATOM),
                ],
            ],
            []
        );

        $service = new AuctionService(
            $repository,
            new FrozenClock($this->now),
            null,
            [
                [
                    'slug' => 'iphone-15-pro',
                    'title' => 'New title',
                    'description' => 'New description',
                    'imageUrl' => 'https://new.example/new.jpg',
                    'currency' => 'MYR',
                    'startingPrice' => 5000.0,
                    'durationSeconds' => 60,
                    'status' => 'scheduled',
                    'startedAt' => null,
                    'endsAt' => null,
                    'endedAt' => null,
                    'winner' => null,
                    'createdAt' => $this->now->format(DATE_ATOM),
                    'updatedAt' => $this->now->format(DATE_ATOM),
                ],
            ]
        );

        $state = $service->getState('iphone-15-pro');
        self::assertSame('New title', $state['title']);
        self::assertSame('New description', $state['description']);
        self::assertSame('https://new.example/new.jpg', $state['imageUrl']);
        self::assertSame('MYR', $state['currency']);
        self::assertSame(5000.0, $state['startingPrice']);
    }

    private function makeService(): AuctionService
    {
        return new AuctionService(new InMemoryAuctionRepository(), new FrozenClock($this->now), $this->defaults());
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return $this->defaultsFor(self::PRODUCT_SLUG, 500.00);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultsFor(string $slug, float $startingPrice): array
    {
        return [
            'slug' => $slug,
            'title' => 'Vintage Leica Camera',
            'description' => 'A single item auction for testing.',
            'imageUrl' => '',
            'currency' => 'MYR',
            'startingPrice' => $startingPrice,
            'durationSeconds' => 60,
            'status' => 'scheduled',
            'startedAt' => null,
            'endsAt' => null,
            'endedAt' => null,
            'winner' => null,
            'createdAt' => $this->now->format(DATE_ATOM),
            'updatedAt' => $this->now->format(DATE_ATOM),
        ];
    }
}
