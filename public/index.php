<?php

declare(strict_types=1);

use App\Bootstrap;
use App\Exception\AuctionClosedException;
use App\Exception\ValidationException;

require dirname(__DIR__) . '/vendor/autoload.php';

$service = Bootstrap::service();
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

ensureSessionCookie();

if ($path === '/health') {
    jsonResponse(['ok' => true]);
}

if ($path === '/api/auction/state' && $method === 'GET') {
    $productSlug = (string) ($_GET['productSlug'] ?? '');

    try {
        jsonResponse($service->getState($productSlug !== '' ? $productSlug : null));
    } catch (ValidationException $exception) {
        jsonResponse(['message' => $exception->getMessage(), 'errors' => $exception->errors()], 422);
    }
}

if ($path === '/api/products' && $method === 'GET') {
    jsonResponse(['products' => $service->listProducts()]);
}

if ($path === '/api/auction/bid' && $method === 'POST') {
    try {
        $payload = readPayload();
    } catch (Throwable) {
        jsonResponse(['message' => 'Invalid request body.'], 400);
    }

    $productSlug = (string) ($payload['productSlug'] ?? '');
    $bidderName = (string) ($payload['bidderName'] ?? '');
    $amount = (float) ($payload['amount'] ?? 0);
    $sessionId = getSessionCookie();

    try {
        $state = $service->placeBid($productSlug, $bidderName, $amount, $sessionId);
        jsonResponse($state, 201);
    } catch (ValidationException $exception) {
        jsonResponse(['message' => $exception->getMessage(), 'errors' => $exception->errors()], 422);
    } catch (AuctionClosedException $exception) {
        jsonResponse(['message' => $exception->getMessage()], 409);
    }
}

if ($path === '/api/auction/reset' && in_array($method, ['POST', 'GET'], true)) {
    $productSlug = (string) ($_GET['productSlug'] ?? '');

    try {
        jsonResponse($service->reset($productSlug !== '' ? $productSlug : null));
    } catch (ValidationException $exception) {
        jsonResponse(['message' => $exception->getMessage(), 'errors' => $exception->errors()], 422);
    }
}

if ($path !== '/' && $path !== '/index.php') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Not Found';
    exit;
}

if ($method === 'GET' && (isset($_GET['bidderName']) || isset($_GET['amount']) || isset($_GET['reset']))) {
    $productSlug = (string) ($_GET['product'] ?? '');

    if ((string) ($_GET['reset'] ?? '') === '1') {
        try {
            $service->reset($productSlug !== '' ? $productSlug : null);
            redirectToHome(['notice' => 'Auction reset.', 'product' => $productSlug]);
        } catch (ValidationException $exception) {
            redirectToHome(['error' => implode(' ', $exception->errors()), 'product' => $productSlug]);
        }
    }

    if (isset($_GET['bidderName'], $_GET['amount'])) {
        $bidderName = (string) $_GET['bidderName'];
        $amount = (float) $_GET['amount'];

        try {
            $service->placeBid($productSlug, $bidderName, $amount, getSessionCookie());
            redirectToHome(['notice' => 'Bid accepted.', 'product' => $productSlug]);
        } catch (ValidationException $exception) {
            redirectToHome(['error' => implode(' ', $exception->errors()), 'product' => $productSlug]);
        } catch (AuctionClosedException $exception) {
            redirectToHome(['error' => $exception->getMessage(), 'product' => $productSlug]);
        }
    }
}

$productSlug = (string) ($_GET['product'] ?? '');
try {
    $state = $service->getState($productSlug !== '' ? $productSlug : null);
} catch (ValidationException) {
    $state = $service->getState();
}
$products = $service->listProducts();
renderPage($state, $products);

function ensureSessionCookie(): void
{
    if (isset($_COOKIE['auction_session']) && $_COOKIE['auction_session'] !== '') {
        return;
    }

    $sessionId = bin2hex(random_bytes(16));
    setcookie('auction_session', $sessionId, [
        'expires' => time() + 86400 * 30,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE['auction_session'] = $sessionId;
}

function getSessionCookie(): string
{
    return (string) ($_COOKIE['auction_session'] ?? '');
}

/**
 * @return array<string, mixed>
 */
function readPayload(): array
{
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));

    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input') ?: '';
        /** @var array<string, mixed> $data */
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    return $_POST;
}

/**
 * @param array<string, mixed> $payload
 */
function jsonResponse(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

/**
 * @param array<string, string> $params
 */
function redirectToHome(array $params = []): never
{
    $location = '/';
    $cleanParams = array_filter($params, static fn (string $value): bool => $value !== '');
    if ($cleanParams !== []) {
        $location .= '?' . http_build_query($cleanParams);
    }

    header('Location: ' . $location, true, 302);
    exit;
}

/**
 * @param array<string, mixed> $state
 * @param array<int, array<string, mixed>> $products
 */
function renderPage(array $state, array $products): never
{
    $title = htmlspecialchars((string) ($state['title'] ?? 'Bidding System'), ENT_QUOTES, 'UTF-8');
    $description = htmlspecialchars((string) ($state['description'] ?? ''), ENT_QUOTES, 'UTF-8');
    $bootstrap = json_encode(
        [
            'state' => $state,
            'products' => $products,
        ],
        JSON_UNESCAPED_SLASHES
        | JSON_THROW_ON_ERROR
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
    );
    $appName = htmlspecialchars((string) ($_ENV['APP_NAME'] ?? 'Aiodin Bidding System'), ENT_QUOTES, 'UTF-8');
    $resetHref = '/?reset=1&product=' . urlencode((string) ($state['slug'] ?? ''));
    $status = (string) ($state['status'] ?? 'scheduled');
    $secondsRemaining = (int) (($state['countdown']['secondsRemaining'] ?? 0));
    $countdownText = 'Bid To Start';
    if ($status === 'live') {
        $minutes = (int) floor(max(0, $secondsRemaining) / 60);
        $seconds = max(0, $secondsRemaining) % 60;
        $countdownText = sprintf('%02d:%02d', $minutes, $seconds);
    } elseif ($status === 'ended') {
        $countdownText = 'Auction Ended';
    }

    header('Content-Type: text/html; charset=UTF-8');
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="color-scheme" content="dark">
  <title><?= $appName ?> - <?= $title ?></title>
  <meta name="description" content="<?= $description ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,800&family=Space+Grotesk:wght@400;500;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/styles.css">
</head>
<body>
  <div class="backdrop"></div>
  <main class="shell">
    <header class="masthead">
      <h1><?= $appName ?></h1>
    </header>

    <section class="panel auction-stage">
      <div class="stage-top">
        <span class="countdown-label">Countdown</span>
        <span class="countdown-value" id="countdown"><?= htmlspecialchars($countdownText, ENT_QUOTES, 'UTF-8') ?></span>
      </div>

      <div class="product-rail" id="product-list" aria-label="Products"></div>

      <article class="product-panel">
        <figure class="product-media">
          <img id="product-image" src="" alt="">
        </figure>
        <div class="product-copy">
          <p class="section-label">Bid product</p>
          <h2 id="product-name"><?= $title ?></h2>
          <p id="product-description"><?= $description ?></p>
        </div>
      </article>

      <div class="stage-bottom">
        <div class="bottom-card">
          <p class="section-label">Last bid</p>
          <strong id="last-bid-amount"><?= htmlspecialchars((string) ($state['currency'] ?? 'MYR'), ENT_QUOTES, 'UTF-8') ?> <?= number_format((float) ($state['displayPrice'] ?? 0), 2, '.', ',') ?></strong>
        </div>
        <div class="bottom-card">
          <p class="section-label">Last bidder</p>
          <strong id="last-bidder-name"><?= htmlspecialchars((string) (($state['bids'][0]['bidderName'] ?? 'No bidder yet')), ENT_QUOTES, 'UTF-8') ?></strong>
        </div>
        <div class="bottom-card bottom-action">
          <p class="section-label">Bid action</p>
          <input
            type="number"
            id="bid-amount"
            class="bidder-input"
            step="0.01"
            min="0"
            placeholder="Amount"
            inputmode="decimal"
          >
          <input
            type="text"
            id="bidder-name"
            class="bidder-input"
            maxlength="80"
            placeholder="Name"
            autocomplete="name"
          >
          <div class="action-buttons">
            <button type="button" class="secondary-inline-button" id="bid-plus-button">+100</button>
            <button type="button" class="primary-button" id="bid-submit">Bid</button>
          </div>
          <p class="form-status" id="form-status" role="status" aria-live="polite"></p>
        </div>
      </div>

      <div class="winner-card" id="winner-card" hidden>
        <p class="section-label">Winner</p>
        <strong id="winner-name"></strong>
        <span id="winner-amount"></span>
      </div>
    </section>

    <section class="stage-note">
      <a class="secondary-button" id="reset-link" href="<?= htmlspecialchars($resetHref, ENT_QUOTES, 'UTF-8') ?>">Reset auction</a>
    </section>
  </main>

  <script id="auction-bootstrap" type="application/json"><?= $bootstrap ?></script>
  <script type="module" src="/assets/app.js"></script>
</body>
</html>
    <?php
    exit;
}
