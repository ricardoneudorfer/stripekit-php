<?php

declare(strict_types=1);

namespace StripeKit;

use Stripe\HttpClient\CurlClient;
use Stripe\ApiRequestor;
use Stripe\StripeClient;
use StripeKit\Contracts\StorageAdapter;
use StripeKit\Exceptions\ConfigurationError;
use StripeKit\Modules\CheckoutModule;
use StripeKit\Modules\CouponsModule;
use StripeKit\Modules\CustomersModule;
use StripeKit\Modules\InvoicesModule;
use StripeKit\Modules\PaymentMethodsModule;
use StripeKit\Modules\PaymentsModule;
use StripeKit\Modules\SubscriptionsModule;
use StripeKit\Modules\SyncModule;
use StripeKit\Modules\WebhooksModule;
use StripeKit\Support\Logger;
use StripeKit\Support\Timezone;

/**
 * @param array{
 *     secretKey: string,
 *     publishableKey?: string|null,
 *     webhookSecret?: string|null,
 *     mode: string,
 *     timezone?: string|null,
 *     apiVersion?: string|null,
 *     appInfo?: array{name: string, version?: string, url?: string}|null,
 *     currency?: string|null,
 *     successUrl?: string|null,
 *     cancelUrl?: string|null,
 *     storage?: StorageAdapter|null,
 *     debug?: bool|null,
 *     maxNetworkRetries?: int|null,
 *     timeout?: int|null,
 * } $input
 * @return array<string, mixed>
 */
function resolveStripeKitConfig(array $input): array
{
    if (empty($input['secretKey']) || !is_string($input['secretKey'])) {
        throw new ConfigurationError('`secretKey` is required to initiate StripeKit. Pass your Stripe secret key (sk_live_... or sk_test_...).');
    }

    if (str_starts_with($input['secretKey'], 'pk_')) {
        throw new ConfigurationError('You passed a publishable key as `secretKey`. Use your Stripe secret key here, and put the publishable key in `publishableKey`.');
    }

    if (empty($input['mode']) || !in_array($input['mode'], ['api', 'elements', 'both'], true)) {
        throw new ConfigurationError('`mode` is required and must be one of "api", "elements" or "both". "api" returns a hosted Stripe link, "elements" waits for confirmation from Stripe Elements on your own frontend, "both" lets you choose per call.');
    }

    if ($input['mode'] !== 'api' && empty($input['publishableKey'])) {
        error_log('[StripeKit] `publishableKey` was not provided. It is required on the frontend to mount Stripe Elements, even though StripeKit itself does not need it server-side.');
    }

    $timezone = $input['timezone'] ?? Timezone::DEFAULT_TIMEZONE;
    Timezone::assertValidTimezone($timezone);

    return [
        'secretKey' => $input['secretKey'],
        'publishableKey' => $input['publishableKey'] ?? null,
        'webhookSecret' => $input['webhookSecret'] ?? null,
        'mode' => $input['mode'],
        'timezone' => $timezone,
        'apiVersion' => $input['apiVersion'] ?? null,
        'appInfo' => $input['appInfo'] ?? null,
        'currency' => strtolower($input['currency'] ?? 'usd'),
        'successUrl' => $input['successUrl'] ?? null,
        'cancelUrl' => $input['cancelUrl'] ?? null,
        'storage' => $input['storage'] ?? null,
        'debug' => $input['debug'] ?? false,
        'maxNetworkRetries' => $input['maxNetworkRetries'] ?? 2,
        'timeout' => $input['timeout'] ?? null,
    ];
}

class StripeKit
{
    /** @var array<string, mixed> */
    public readonly array $config;

    public readonly StripeClient $raw;

    public readonly CustomersModule $customers;
    public readonly PaymentMethodsModule $paymentMethods;
    public readonly PaymentsModule $payments;
    public readonly CheckoutModule $checkout;
    public readonly SubscriptionsModule $subscriptions;
    public readonly InvoicesModule $invoices;
    public readonly CouponsModule $coupons;
    public readonly WebhooksModule $webhooks;
    public readonly SyncModule $sync;

    /**
     * @param array<string, mixed> $config
     */
    private function __construct(array $config)
    {
        $this->config = $config;

        if ($config['timeout'] !== null) {
            $curl = new CurlClient();
            $curl->setTimeout((int) ceil($config['timeout'] / 1000));
            ApiRequestor::setHttpClient($curl);
        }

        $options = ['api_key' => $config['secretKey'], 'max_network_retries' => (int) $config['maxNetworkRetries']];
        if (!empty($config['apiVersion'])) {
            $options['stripe_version'] = $config['apiVersion'];
        }
        if (!empty($config['appInfo'])) {
            $options['app_info'] = $config['appInfo'];
        }

        $this->raw = new StripeClient($options);

        $logger = new Logger((bool) $config['debug']);

        $this->customers = new CustomersModule($this->raw, $config, $logger);
        $this->paymentMethods = new PaymentMethodsModule($this->raw, $config, $logger);
        $this->payments = new PaymentsModule($this->raw, $config, $logger);
        $this->checkout = new CheckoutModule($this->raw, $config, $logger);
        $this->subscriptions = new SubscriptionsModule($this->raw, $config, $logger);
        $this->invoices = new InvoicesModule($this->raw, $config, $logger);
        $this->coupons = new CouponsModule($this->raw, $config, $logger);

        $this->checkout->attachCouponsModule($this->coupons);

        $this->sync = new SyncModule($this->raw, $config, $logger, [
            'customers' => $this->customers,
            'subscriptions' => $this->subscriptions,
            'payments' => $this->payments,
            'invoices' => $this->invoices,
            'paymentMethods' => $this->paymentMethods,
        ]);

        $this->webhooks = new WebhooksModule($this->raw, $config, $logger, [
            'subscriptions' => $this->subscriptions,
            'payments' => $this->payments,
            'invoices' => $this->invoices,
            'paymentMethods' => $this->paymentMethods,
        ]);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function init(array $config): self
    {
        return new self(resolveStripeKitConfig($config));
    }

    public function isElementsEnabled(): bool
    {
        return $this->config['mode'] === 'elements' || $this->config['mode'] === 'both';
    }

    public function isApiEnabled(): bool
    {
        return $this->config['mode'] === 'api' || $this->config['mode'] === 'both';
    }

    /**
     * @return array{publishableKey: string|null, mode: string, timezone: string}
     */
    public function toClientConfig(): array
    {
        return [
            'publishableKey' => $this->config['publishableKey'],
            'mode' => $this->config['mode'],
            'timezone' => $this->config['timezone'],
        ];
    }
}
