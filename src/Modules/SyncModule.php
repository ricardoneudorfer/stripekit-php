<?php

declare(strict_types=1);

namespace StripeKit\Modules;

use Stripe\StripeClient;
use StripeKit\Support\Logger;

class SyncModule extends BaseModule
{
    private readonly CustomersModule $customers;
    private readonly SubscriptionsModule $subscriptions;
    private readonly PaymentsModule $payments;
    private readonly InvoicesModule $invoices;
    private readonly PaymentMethodsModule $paymentMethods;

    /**
     * @param array<string, mixed> $config
     * @param array{customers: CustomersModule, subscriptions: SubscriptionsModule, payments: PaymentsModule, invoices: InvoicesModule, paymentMethods: PaymentMethodsModule} $deps
     */
    public function __construct(StripeClient $stripe, array $config, Logger $logger, array $deps)
    {
        parent::__construct($stripe, $config, $logger);
        $this->customers = $deps['customers'];
        $this->subscriptions = $deps['subscriptions'];
        $this->payments = $deps['payments'];
        $this->invoices = $deps['invoices'];
        $this->paymentMethods = $deps['paymentMethods'];
    }

    /**
     * @return array<string, mixed>
     */
    public function customer(string $customerId): array
    {
        return $this->customers->sync($customerId);
    }

    /**
     * @return array<string, mixed>
     */
    public function subscription(string $subscriptionId, string|int|null $userId = null): array
    {
        return $this->subscriptions->sync($subscriptionId, $userId);
    }

    /**
     * @return array<string, mixed>
     */
    public function payment(string $paymentIntentId, string|int|null $userId = null): array
    {
        return $this->payments->sync($paymentIntentId, $userId);
    }

    /**
     * @return array<string, mixed>
     */
    public function invoice(string $invoiceId, string|int|null $userId = null): array
    {
        return $this->invoices->sync($invoiceId, $userId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function paymentMethods(string $customerId, string|int|null $userId = null): array
    {
        return $this->paymentMethods->sync($customerId, $userId);
    }

    /**
     * @return array{customer: array<string, mixed>, subscriptions: list<array<string, mixed>>, invoices: list<array<string, mixed>>, paymentMethods: list<array<string, mixed>>}
     */
    public function everythingForCustomer(string $customerId, string|int|null $userId = null): array
    {
        $customer = $this->customers->sync($customerId);
        $subscriptions = $this->subscriptions->listByCustomer($customerId);
        $invoices = $this->invoices->listByCustomer($customerId);
        $methods = $this->paymentMethods->sync($customerId, $userId);

        foreach ($subscriptions as $sub) {
            $this->storage?->saveSubscription($sub);
        }
        foreach ($invoices as $inv) {
            $this->storage?->saveInvoice($inv);
        }

        return ['customer' => $customer, 'subscriptions' => $subscriptions, 'invoices' => $invoices, 'paymentMethods' => $methods];
    }
}
