# Aiodin Bidding System

Live multi-product bidding system built with PHP and MongoDB.

## What is included

- Multiple product auctions with independent live states
- Product selector with per-product auction state
- First valid bid starts a 60-second countdown
- Every valid higher bid resets the countdown back to 60 seconds
- Live state updates through client-side polling
- Auction phases:
  - Before start: `Bid To Start`
  - In progress: countdown timer + live current bid updates
  - Ended: `Auction Ended` + winner display
- Bid action bar with `Amount`, `Name`, `+100`, `Bid`
- Bid history and winner display
- Backend unit tests in PHPUnit
- Frontend unit tests in Vitest

## Requirements

- PHP 8.2+
- Composer
- Node 18+
- MongoDB server for the primary storage path

## Configuration

Copy `.env.example` to `.env` and adjust the values for your environment.

If `MONGO_URI` is present and the MongoDB PHP extension is loaded, the app uses MongoDB.
If MongoDB is not configured yet, it falls back to a local JSON file store so the UI can still boot.

Data model:
- `products` collection/table stores product auction metadata
- `biddings` collection/table stores each bid row
- Auction state is computed by combining product + biddings

## API endpoints

- `GET /api/products`
- `GET /api/auction/state?productSlug=<slug>`
- `POST /api/auction/bid` with JSON body:
  - `productSlug` (string)
  - `bidderName` (string)
  - `amount` (number)
- `GET|POST /api/auction/reset?productSlug=<slug>`

## Install

```bash
composer install
npm install
```

## Run

```bash
composer serve
```

Open `http://localhost:8000`.

## Run with Docker

Build image:

```bash
docker build -t aiodin-bidding-system:local .
```

Run container:

```bash
docker run --rm -p 8000:8000 \
  -e MONGO_URI="your_mongodb_uri" \
  -e MONGO_DATABASE="aiodin_bidding" \
  aiodin-bidding-system:local
```

Open `http://localhost:8000`.

## Tests

Run all tests:

```bash
composer test
npm test
```

Run backend unit tests only:

```bash
composer test
```

Run frontend unit tests only:

```bash
npm test -- --run
```

Run frontend tests in watch mode:

```bash
npm run test:watch
```

## Deployment (free-tier options)

Recommended first option:

- Render (free web service):
  - Supports Dockerfile deployments for PHP apps.
  - Gives a public `onrender.com` URL with TLS.
  - Free instances can spin down on idle and have monthly limits.

Other options:

- Koyeb:
  - Supports Dockerfile builder and git-based deploys.
  - Has a small free web service tier (check current limits in their pricing FAQ).
- Railway:
  - Uses free trial + recurring free credit model.
  - Good for testing, but free allowance is usually tighter for always-on apps.

Official docs:

- Render free instances: `https://render.com/docs/free`
- Render Docker deploys: `https://render.com/docs/docker`
- Koyeb pricing FAQ: `https://www.koyeb.com/docs/faqs/pricing`
- Koyeb git/docker deploy: `https://www.koyeb.com/docs/build-and-deploy/deploy-with-git`
- Railway free trial: `https://docs.railway.com/pricing/free-trial`

## Notes

- By default, four dummy products are seeded (`iPhone 15 Pro`, `Cash Voucher`, `MacBook Pro 16"`, `Gaming PC`).
- You can override the default product catalog with `AUCTION_PRODUCTS_JSON`.
