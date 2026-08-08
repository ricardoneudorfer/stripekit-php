<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use StripeKit\StripeKit;

$kit = StripeKit::init([
    'secretKey' => getenv('STRIPE_SECRET_KEY'),
    'mode' => 'api',
    'timezone' => 'Europe/Amsterdam',
    'currency' => 'eur',
    'successUrl' => 'https://yourapp.com/billing/success',
    'cancelUrl' => 'https://yourapp.com/billing/cancel',
]);

function createOneTimePayment(StripeKit $kit): array
{
    $payment = $kit->payments->create([
        'amount' => 2500,
        'currency' => 'eur',
        'email' => 'customer@example.com',
        'description' => 'Pro plan - one time setup fee',
    ]);

    echo 'Redirect the customer to: ' . $payment['hostedUrl'] . PHP_EOL;
    return $payment;
}

function createSubscriptionCheckout(StripeKit $kit): array
{
    $checkout = $kit->checkout->create([
        'mode' => 'subscription',
        'priceId' => 'price_123',
        'email' => 'customer@example.com',
        'couponCode' => 'WELCOME10',
        'customFields' => [
            ['key' => 'company_name', 'label' => 'Company name', 'required' => true],
            ['key' => 'vat_number', 'label' => 'VAT number', 'required' => false],
        ],
        'fieldValues' => [
            'company_name' => 'Acme BV',
        ],
        'successUrl' => 'https://yourapp.com/billing/success',
        'cancelUrl' => 'https://yourapp.com/billing/cancel',
    ]);

    echo 'Redirect the customer to: ' . $checkout['hostedUrl'] . PHP_EOL;
    return $checkout;
}

function manageCustomer(StripeKit $kit): void
{
    $customer = $kit->customers->create([
        'email' => 'jane@example.com',
        'name' => 'Jane Doe',
    ]);

    $kit->customers->update($customer['id'], ['phone' => '+31612345678']);

    $subscriptions = $kit->subscriptions->listByCustomer($customer['id']);
    print_r($subscriptions);

    $kit->customers->delete($customer['id']);
}

createOneTimePayment($kit);
createSubscriptionCheckout($kit);
manageCustomer($kit);
