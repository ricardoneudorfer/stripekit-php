<?php

declare(strict_types=1);

namespace StripeKit\Modules;

use Stripe\Event;
use Stripe\StripeClient;
use Stripe\Webhook;
use StripeKit\Contracts\StorageAdapter;
use StripeKit\Exceptions\ConfigurationError;
use StripeKit\Exceptions\WebhookVerificationError;
use StripeKit\Support\Logger;
use Throwable;

class WebhooksModule extends BaseModule
{
    /** @var array{subscriptions: SubscriptionsModule, payments: PaymentsModule, invoices: InvoicesModule, paymentMethods: PaymentMethodsModule} */
    private readonly array $deps;

    /** @var array<string, true> */
    private static array $seenEventsFallback = [];

    /**
     * @param array<string, mixed> $config
     * @param array{subscriptions: SubscriptionsModule, payments: PaymentsModule, invoices: InvoicesModule, paymentMethods: PaymentMethodsModule} $deps
     */
    public function __construct(StripeClient $stripe, array $config, Logger $logger, array $deps)
    {
        parent::__construct($stripe, $config, $logger);
        $this->deps = $deps;
    }

    public function verify(string $payload, string $signature): Event
    {
        if (empty($this->config['webhookSecret'])) {
            throw new ConfigurationError('`webhookSecret` was not configured. Pass it to StripeKit.init() to verify webhooks.');
        }
        try {
            return Webhook::constructEvent($payload, $signature, $this->config['webhookSecret']);
        } catch (Throwable $error) {
            throw new WebhookVerificationError('Webhook signature verification failed.', $error);
        }
    }

    /**
     * @param array{payload: string, signature: string, handlers?: array<string, callable>, autoSync?: bool} $input
     * @return array{received: bool, duplicate: bool, eventId: string, eventType: string}
     */
    public function process(array $input): array
    {
        $event = $this->verify($input['payload'], $input['signature']);

        $alreadySeen = $this->storage !== null && $this->storage->overrides('hasProcessedWebhookEvent')
            ? $this->storage->hasProcessedWebhookEvent($event->id)
            : isset(self::$seenEventsFallback[$event->id]);

        if ($alreadySeen) {
            return ['received' => true, 'duplicate' => true, 'eventId' => $event->id, 'eventType' => $event->type];
        }

        if ($this->storage !== null && $this->storage->overrides('markWebhookEventProcessed')) {
            $this->storage->markWebhookEventProcessed($event->id, $event->type);
        } else {
            self::$seenEventsFallback[$event->id] = true;
            $this->logger->warn(
                'No `storage.markWebhookEventProcessed` adapter configured. Webhook idempotency is only tracked in local process memory. Provide a storage adapter for production deployments with multiple instances.',
            );
        }

        $autoSync = $input['autoSync'] ?? true;
        $handlers = $input['handlers'] ?? [];

        try {
            $this->dispatch($event, $handlers, $autoSync);
        } catch (Throwable $error) {
            $this->logger->error(sprintf('Webhook handler threw for event %s (%s):', $event->id, $event->type), $error);
            throw $error;
        }

        return ['received' => true, 'duplicate' => false, 'eventId' => $event->id, 'eventType' => $event->type];
    }

    /**
     * @param array<string, callable> $handlers
     */
    private function dispatch(Event $event, array $handlers, bool $autoSync): void
    {
        $object = $event->data->object;
        $context = ['event' => $event];

        switch ($event->type) {
            case 'payment_intent.succeeded':
                if ($autoSync) {
                    $this->deps['payments']->sync($object->id);
                }
                if (isset($handlers['onPaymentSucceeded'])) {
                    ($handlers['onPaymentSucceeded'])($object, $context);
                }
                break;

            case 'payment_intent.payment_failed':
                if ($autoSync) {
                    $this->deps['payments']->sync($object->id);
                }
                if (isset($handlers['onPaymentFailed'])) {
                    ($handlers['onPaymentFailed'])($object, $context);
                }
                break;

            case 'invoice.created':
                if ($autoSync && $object->id) {
                    $this->deps['invoices']->sync($object->id);
                }
                if (isset($handlers['onInvoiceCreated'])) {
                    ($handlers['onInvoiceCreated'])($object, $context);
                }
                break;

            case 'invoice.updated':
                if ($autoSync && $object->id) {
                    $this->deps['invoices']->sync($object->id);
                }
                if (isset($handlers['onInvoiceUpdated'])) {
                    ($handlers['onInvoiceUpdated'])($object, $context);
                }
                break;

            case 'invoice.paid':
                if ($autoSync && $object->id) {
                    $this->deps['invoices']->sync($object->id);
                }
                if (isset($handlers['onInvoicePaid'])) {
                    ($handlers['onInvoicePaid'])($object, $context);
                }
                break;

            case 'invoice.payment_failed':
                if ($autoSync && $object->id) {
                    $this->deps['invoices']->sync($object->id);
                }
                if (isset($handlers['onInvoicePaymentFailed'])) {
                    ($handlers['onInvoicePaymentFailed'])($object, $context);
                }
                break;

            case 'invoice.voided':
                if ($autoSync && $object->id) {
                    $this->deps['invoices']->sync($object->id);
                }
                if (isset($handlers['onInvoiceVoided'])) {
                    ($handlers['onInvoiceVoided'])($object, $context);
                }
                break;

            case 'invoice.deleted':
                if ($autoSync && $object->id) {
                    $this->storage?->markInvoiceDeleted($object->id);
                }
                if (isset($handlers['onInvoiceDeleted'])) {
                    ($handlers['onInvoiceDeleted'])($object, $context);
                }
                break;

            case 'customer.subscription.created':
                if ($autoSync) {
                    $this->deps['subscriptions']->sync($object->id);
                }
                if (isset($handlers['onSubscriptionCreated'])) {
                    ($handlers['onSubscriptionCreated'])($object, $context);
                }
                break;

            case 'customer.subscription.updated':
                if ($autoSync) {
                    $this->deps['subscriptions']->sync($object->id);
                }
                if (isset($handlers['onSubscriptionUpdated'])) {
                    ($handlers['onSubscriptionUpdated'])($object, $context);
                }
                break;

            case 'customer.subscription.deleted':
                if ($autoSync) {
                    $this->deps['subscriptions']->sync($object->id);
                }
                if (isset($handlers['onSubscriptionDeleted'])) {
                    ($handlers['onSubscriptionDeleted'])($object, $context);
                }
                break;

            case 'setup_intent.succeeded':
                $customer = $object->customer;
                $customerId = is_string($customer) ? $customer : ($customer->id ?? null);
                if ($autoSync && $customerId) {
                    $this->deps['paymentMethods']->sync($customerId);
                }
                if (isset($handlers['onSetupIntentSucceeded'])) {
                    ($handlers['onSetupIntentSucceeded'])($object, $context);
                }
                break;

            case 'checkout.session.completed':
                if (isset($handlers['onCheckoutSessionCompleted'])) {
                    ($handlers['onCheckoutSessionCompleted'])($object, $context);
                }
                break;

            default:
                if (isset($handlers['onUnhandledEvent'])) {
                    ($handlers['onUnhandledEvent'])($object, $context);
                }
                break;
        }
    }
}
