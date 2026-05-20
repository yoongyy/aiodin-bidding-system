<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;

final class DefaultAuction
{
    public static function fromEnv(DateTimeImmutable $now): array
    {
        $catalog = self::catalogFromEnv($now);
        return $catalog[0];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function catalogFromEnv(DateTimeImmutable $now): array
    {
        $duration = max(10, EnvValue::int('AUCTION_DURATION_SECONDS', 60));
        $currency = EnvValue::string('AUCTION_CURRENCY', 'MYR');
        $createdAt = $now->format(DATE_ATOM);

        $raw = trim(EnvValue::string('AUCTION_PRODUCTS_JSON', ''));
        if ($raw !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $decoded = null;
            }

            if (is_array($decoded)) {
                $catalog = [];
                foreach ($decoded as $row) {
                    if (is_array($row)) {
                        $normalized = self::normalizeProduct($row, $duration, $currency, $createdAt);
                        if ($normalized !== null) {
                            $catalog[] = $normalized;
                        }
                    }
                }

                if ($catalog !== []) {
                    return $catalog;
                }
            }
        }

        $configured = self::normalizeProduct([
            'slug' => EnvValue::string('AUCTION_SLUG', 'iphone-15-pro'),
            'title' => EnvValue::string('AUCTION_TITLE', 'iPhone 15 Pro'),
            'description' => EnvValue::string(
                'AUCTION_DESCRIPTION',
                'Factory unlocked flagship smartphone in pristine condition.'
            ),
            'imageUrl' => EnvValue::string('AUCTION_IMAGE_URL', 'https://upload.wikimedia.org/wikipedia/commons/8/84/IPhone_15_pro.jpg'),
            'startingPrice' => round(EnvValue::float('AUCTION_STARTING_PRICE', 5000.0), 2),
        ], $duration, $currency, $createdAt);

        $defaults = [
            $configured,
            self::normalizeProduct([
                'slug' => 'cash-voucher',
                'title' => 'Cash Voucher',
                'description' => 'Instant cash voucher redeemable immediately after auction closes.',
                'imageUrl' => 'https://upload.wikimedia.org/wikipedia/commons/7/7b/United_States_one_dollar_bill%2C_obverse.jpg',
                'startingPrice' => 1000.0,
            ], $duration, $currency, $createdAt),
            self::normalizeProduct([
                'slug' => 'macbook-pro-16',
                'title' => 'MacBook Pro 16"',
                'description' => '16-inch workstation laptop for creators and developers.',
                'imageUrl' => 'https://upload.wikimedia.org/wikipedia/commons/8/83/Apple_MacBook_Pro_16%22_M2Max.jpg',
                'startingPrice' => 9500.0,
            ], $duration, $currency, $createdAt),
            self::normalizeProduct([
                'slug' => 'gaming-pc',
                'title' => 'Gaming PC',
                'description' => 'High-end desktop rig with dedicated graphics and fast storage.',
                'imageUrl' => 'https://upload.wikimedia.org/wikipedia/commons/f/f4/Gaming_pc.jpg',
                'startingPrice' => 7000.0,
            ], $duration, $currency, $createdAt),
        ];

        $catalog = [];
        $seen = [];
        foreach ($defaults as $row) {
            if (!is_array($row)) {
                continue;
            }

            $slug = (string) ($row['slug'] ?? '');
            if ($slug === '' || isset($seen[$slug])) {
                continue;
            }

            $seen[$slug] = true;
            $catalog[] = $row;
        }

        return $catalog;
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>|null
     */
    private static function normalizeProduct(array $product, int $duration, string $currency, string $createdAt): ?array
    {
        $title = trim((string) ($product['title'] ?? ''));
        $slug = trim((string) ($product['slug'] ?? ''));
        $slug = self::slugify($slug !== '' ? $slug : $title);

        if ($title === '' || $slug === '') {
            return null;
        }

        return [
            'slug' => $slug,
            'title' => $title,
            'description' => trim((string) ($product['description'] ?? '')),
            'imageUrl' => trim((string) ($product['imageUrl'] ?? '')),
            'currency' => (string) ($product['currency'] ?? $currency),
            'startingPrice' => round((float) ($product['startingPrice'] ?? 0), 2),
            'durationSeconds' => max(10, (int) ($product['durationSeconds'] ?? $duration)),
            'status' => 'scheduled',
            'startedAt' => null,
            'endsAt' => null,
            'endedAt' => null,
            'winner' => null,
            'createdAt' => $createdAt,
            'updatedAt' => $createdAt,
        ];
    }

    private static function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-');
    }
}

final class EnvValue
{
    public static function string(string $key, string $default = ''): string
    {
        return \App\Config\Env::string($key, $default);
    }

    public static function int(string $key, int $default): int
    {
        return \App\Config\Env::int($key, $default);
    }

    public static function float(string $key, float $default): float
    {
        return \App\Config\Env::float($key, $default);
    }
}
