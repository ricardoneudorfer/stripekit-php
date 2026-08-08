<?php

declare(strict_types=1);

namespace StripeKit\Modules;

use Stripe\Subscription;
use StripeKit\Exceptions\StripeOperationError;
use StripeKit\Exceptions\ValidationError;
use StripeKit\Support\Timezone;
use StripeKit\Support\Validation;
use Throwable;

class SubscriptionsModule extends BaseModule
{
    /**
     * @param array<string, string> $metadata
     * @return array<string, string>
     */
    private function extractFieldValuesFromMetadata(array $metadata): array
    {
        $out = [];
        foreach ($metadata as $key => $value) {
            if (str_starts_with($key, 'field_')) {
                $out[substr($key, 6)] = $value;
            }
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapSubscription(Subscription $sub, string|int|null $userId = null): array
    {
        $item = $sub->items->data[0] ?? null;
        $timezone = $this->config['timezone'];

        $periodStart = Timezone::unixToTimezone($sub->current_period_start, $timezone);
        $periodEnd = Timezone::unixToTimezone($sub->current_period_end, $timezone);

        $customer = $sub->customer;
        $metadata = $sub->metadata ? $sub->metadata->toArray() : [];

        return [
            'id' => $sub->id,
            'userId' => $userId,
            'customerId' => is_string($customer) ? $customer : ($customer->id ?? null),
            'priceId' => $item?->price?->id ?? null,
            'status' => $sub->status,
            'currentPeriodStartUtc' => $periodStart['utc'],
            'currentPeriodEndUtc' => $periodEnd['utc'],
            'currentPeriodStartLocal' => $periodStart['local'],
            'currentPeriodEndLocal' => $periodEnd['local'],
            'cancelAtPeriodEnd' => $sub->cancel_at_period_end,
            'canceledAtUtc' => Timezone::unixToUtcIso($sub->canceled_at),
            'trialEndUtc' => Timezone::unixToUtcIso($sub->trial_end),
            'collectionMethod' => $sub->collection_method ?? null,
            'metadata' => $metadata,
            'fieldValues' => $this->extractFieldValuesFromMetadata($metadata),
            'createdAtUtc' => Timezone::unixToUtcIso($sub->created) ?? Timezone::nowUtcIso(),
        ];
    }

    /**
     * @param array{customerId: string, priceId: string, userId?: string|int, quantity?: int, trialPeriodDays?: int, collectionMethod?: string, daysUntilDue?: int, defaultPaymentMethodId?: string, promotionCode?: string, metadata?: array<string, string>, fieldValues?: array<string, string>} $input
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        $metadata = array_merge(['source' => 'stripekit'], $input['metadata'] ?? []);
        foreach ($input['fieldValues'] ?? [] as $key => $value) {
            $metadata['field_' . $key] = $value;
        }

        try {
            $sub = $this->stripe->subscriptions->create([
                'customer' => $input['customerId'],
                'items' => [['price' => $input['priceId'], 'quantity' => $input['quantity'] ?? 1]],
                'trial_period_days' => $input['trialPeriodDays'] ?? null,
                'collection_method' => $input['collectionMethod'] ?? 'charge_automatically',
                'days_until_due' => ($input['collectionMethod'] ?? null) === 'send_invoice' ? ($input['daysUntilDue'] ?? 7) : null,
                'default_payment_method' => $input['defaultPaymentMethodId'] ?? null,
                'promotion_code' => $input['promotionCode'] ?? null,
                'metadata' => $metadata,
                'expand' => ['items.data'],
            ]);

            $record = $this->mapSubscription($sub, $input['userId'] ?? null);
            $this->storage?->saveSubscription($record);
            return $record;
        } catch (Throwable $error) {
            throw StripeOperationError::fromError($error, 'Could not create subscription.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function retrieve(string $subscriptionId, string|int|null $userId = null): array
    {
        try {
            $sub = $this->stripe->subscriptions->retrieve($subscriptionId, ['expand' => ['items.data']]);
            return $this->mapSubscription($sub, $userId);
        } catch (Throwable $error) {
            throw StripeOperationError::fromError($error, sprintf('Could not retrieve subscription "%s".', $subscriptionId));
        }
    }

    /**
     * @param array{subscriptionId: string, atPeriodEnd?: bool, cancellationReason?: string} $input
     * @return array<string, mixed>
     */
    public function cancel(array $input): array
    {
        try {
            $sub = !empty($input['atPeriodEnd'])
                ? $this->stripe->subscriptions->update($input['subscriptionId'], [
                    'cancel_at_period_end' => true,
                    'cancellation_details' => !empty($input['cancellationReason']) ? ['comment' => $input['cancellationReason']] : null,
                ])
                : $this->stripe->subscriptions->cancel($input['subscriptionId'], [
                    'cancellation_details' => !empty($input['cancellationReason']) ? ['comment' => $input['cancellationReason']] : null,
                ]);

            $record = $this->mapSubscription($sub);
            $this->storage?->saveSubscription($record);
            return $record;
        } catch (Throwable $error) {
            throw StripeOperationError::fromError($error, sprintf('Could not cancel subscription "%s".', $input['subscriptionId']));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function resume(string $subscriptionId): array
    {
        try {
            $sub = $this->stripe->subscriptions->update($subscriptionId, ['cancel_at_period_end' => false]);
            $record = $this->mapSubscription($sub);
            $this->storage?->saveSubscription($record);
            return $record;
        } catch (Throwable $error) {
            throw StripeOperationError::fromError($error, sprintf('Could not resume subscription "%s".', $subscriptionId));
        }
    }

    /**
     * @param array{subscriptionId: string, collectionMethod: string, daysUntilDue?: int} $input
     * @return array<string, mixed>
     */
    public function toggleCollectionMethod(array $input): array
    {
        try {
            $sub = $this->stripe->subscriptions->update($input['subscriptionId'], [
                'collection_method' => $input['collectionMethod'],
                'days_until_due' => $input['collectionMethod'] === 'send_invoice' ? ($input['daysUntilDue'] ?? 7) : null,
            ]);
            $record = $this->mapSubscription($sub);
            $this->storage?->saveSubscription($record);
            return $record;
        } catch (Throwable $error) {
            throw StripeOperationError::fromError($error, sprintf('Could not update collection method for subscription "%s".', $input['subscriptionId']));
        }
    }

    /**
     * @param array{subscriptionId: string, fieldValues: array<string, string>, schema?: list<array<string, mixed>>, intervalDays?: int} $input
     * @return array{fieldValues: array<string, string>, nextUpdateAvailableAtUtc: string}
     */
    public function updateFields(array $input): array
    {
        if (!empty($input['schema'])) {
            $result = Validation::validateCustomFieldSchema($input['schema'], $input['fieldValues']);
            if (count($result['errors']) > 0) {
                throw new ValidationError('Custom field validation failed.', $result['errors']);
            }
            $input['fieldValues'] = $result['values'];
        }

        $metadataPatch = [];
        foreach ($input['fieldValues'] as $key => $value) {
            $metadataPatch['field_' . $key] = $value;
        }

        try {
            $sub = $this->stripe->subscriptions->update($input['subscriptionId'], ['metadata' => $metadataPatch]);
            $record = $this->mapSubscription($sub);
            $this->storage?->saveSubscription($record);

            return [
                'fieldValues' => $input['fieldValues'],
                'nextUpdateAvailableAtUtc' => Timezone::addDaysUtcIso(Timezone::nowUtcIso(), $input['intervalDays'] ?? 30),
            ];
        } catch (Throwable $error) {
            throw StripeOperationError::fromError($error, sprintf('Could not update custom fields for subscription "%s".', $input['subscriptionId']));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function applyPromotionCode(string $subscriptionId, string $stripePromotionCodeId): array
    {
        try {
            $sub = $this->stripe->subscriptions->update($subscriptionId, ['promotion_code' => $stripePromotionCodeId]);
            $record = $this->mapSubscription($sub);
            $this->storage?->saveSubscription($record);
            return $record;
        } catch (Throwable $error) {
            throw StripeOperationError::fromError($error, sprintf('Could not apply promotion code to subscription "%s".', $subscriptionId));
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listByCustomer(string $customerId, ?string $status = null): array
    {
        try {
            $result = $this->stripe->subscriptions->all(['customer' => $customerId, 'status' => $status, 'expand' => ['data.items.data']]);
            return array_map(fn (Subscription $sub) => $this->mapSubscription($sub), $result->data);
        } catch (Throwable $error) {
            throw StripeOperationError::fromError($error, sprintf('Could not list subscriptions for customer "%s".', $customerId));
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByMetadata(string $key, string $value): ?array
    {
        try {
            $result = $this->stripe->subscriptions->search([
                'query' => sprintf("metadata['%s']:'%s' AND status:'active'", $key, $value),
                'limit' => 1,
            ]);
            $match = $result->data[0] ?? null;
            return $match ? $this->mapSubscription($match) : null;
        } catch (Throwable $error) {
            throw StripeOperationError::fromError($error, sprintf('Could not search subscriptions by metadata "%s".', $key));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function sync(string $subscriptionId, string|int|null $userId = null): array
    {
        $record = $this->retrieve($subscriptionId, $userId);
        $this->storage?->saveSubscription($record);
        return $record;
    }
}
