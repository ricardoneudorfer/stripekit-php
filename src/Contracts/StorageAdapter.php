<?php

declare(strict_types=1);

namespace StripeKit\Contracts;

use ReflectionMethod;

abstract class StorageAdapter
{
    public function overrides(string $method): bool
    {
        return (new ReflectionMethod($this, $method))->getDeclaringClass()->getName() !== self::class;
    }

    /**
     * @param array<string, mixed> $session
     */
    public function saveCheckoutSession(array $session): void
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCheckoutSession(string $checkoutId): ?array
    {
        return null;
    }

    /**
     * @return array{id: string|int, stripeCustomerId?: string|null}|null
     */
    public function findUserByEmail(string $email): ?array
    {
        return null;
    }

    /**
     * @return array{id: string|int, stripeCustomerId?: string|null}|null
     */
    public function findUserByStripeCustomerId(string $customerId): ?array
    {
        return null;
    }

    /**
     * @return array{id: string|int, stripeCustomerId?: string|null}|null
     */
    public function findUserById(string|int $id): ?array
    {
        return null;
    }

    /**
     * @param array<string, mixed> $record
     */
    public function saveCustomer(array $record): void
    {
    }

    /**
     * @param array<string, mixed> $record
     */
    public function saveSubscription(array $record): void
    {
    }

    /**
     * @param array<string, mixed> $record
     */
    public function savePayment(array $record): void
    {
    }

    /**
     * @param array<string, mixed> $record
     */
    public function saveInvoice(array $record): void
    {
    }

    /**
     * @param list<array<string, mixed>> $records
     */
    public function savePaymentMethods(string|int $userId, array $records): void
    {
    }

    /**
     * @param array<string, mixed> $record
     */
    public function saveCoupon(array $record): void
    {
    }

    public function markInvoiceDeleted(string $stripeInvoiceId): void
    {
    }

    public function hasProcessedWebhookEvent(string $eventId): bool
    {
        return false;
    }

    public function markWebhookEventProcessed(string $eventId, string $type): void
    {
    }
}
