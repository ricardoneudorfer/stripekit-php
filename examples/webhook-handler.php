<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use StripeKit\StripeKit;

$kit = StripeKit::init([
    'secretKey' => getenv('STRIPE_SECRET_KEY'),
    'webhookSecret' => getenv('STRIPE_WEBHOOK_SECRET'),
    'mode' => 'both',
    'timezone' => 'UTC',
]);

$payload = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

try {
    $result = $kit->webhooks->process([
        'payload' => $payload,
        'signature' => $signature,
        'handlers' => [
            'onPaymentSucceeded' => function ($paymentIntent) {
                error_log('Payment succeeded: ' . $paymentIntent->id);
            },
            'onInvoicePaid' => function ($invoice) {
                error_log('Invoice paid: ' . $invoice->id);
            },
            'onSubscriptionDeleted' => function ($subscription) {
                error_log('Subscription cancelled: ' . $subscription->id);
            },
            'onSubscriptionUpdated' => function ($subscription) {
                error_log('Subscription status is now: ' . $subscription->status);
            },
        ],
    ]);

    header('Content-Type: application/json');
    echo json_encode(['received' => $result['received']]);
} catch (\Throwable $error) {
    error_log((string) $error);
    http_response_code(400);
    echo 'Webhook Error';
}
