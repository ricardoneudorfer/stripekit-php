<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use StripeKit\StripeKit;

$kit = StripeKit::init([
    'secretKey' => getenv('STRIPE_SECRET_KEY'),
    'publishableKey' => getenv('STRIPE_PUBLISHABLE_KEY'),
    'mode' => 'elements',
    'timezone' => 'America/New_York',
    'currency' => 'usd',
]);

function jsonBody(): array
{
    return json_decode(file_get_contents('php://input') ?: '[]', true) ?? [];
}

function respond(mixed $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($method === 'GET' && $path === '/billing/client-config') {
    respond($kit->toClientConfig());
    return;
}

if ($method === 'POST' && $path === '/billing/checkout') {
    $body = jsonBody();

    $checkout = $kit->checkout->create([
        'mode' => $body['mode'],
        'priceId' => $body['priceId'] ?? null,
        'amount' => $body['mode'] === 'payment' ? 4900 : null,
        'email' => $body['email'] ?? null,
    ]);

    respond([
        'checkoutId' => $checkout['id'],
        'clientSecret' => $checkout['clientSecret'],
    ]);
    return;
}

if ($method === 'POST' && $path === '/billing/payment-methods') {
    $body = jsonBody();
    $methods = $kit->paymentMethods->list($body['customerId']);
    respond($methods);
    return;
}

if ($method === 'POST' && $path === '/billing/pay-with-saved-method') {
    $body = jsonBody();

    $result = $kit->payments->payWithSavedMethod([
        'customerId' => $body['customerId'],
        'paymentMethodId' => $body['paymentMethodId'],
        'amount' => $body['amount'],
        'currency' => 'usd',
    ]);

    respond($result);
    return;
}

respond(['error' => 'Not found'], 404);
