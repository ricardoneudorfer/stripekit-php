<?php

declare(strict_types=1);

namespace StripeKit\Modules;

use Stripe\Customer;
use StripeKit\Exceptions\NotFoundError;
use StripeKit\Exceptions\StripeOperationError;
use StripeKit\Support\Timezone;
use StripeKit\Support\Validation;
use Throwable;

class CustomersModule extends BaseModule
{
    /**
     * @return array{line1: string|null, line2: string|null, city: string|null, state: string|null, postalCode: string|null, country: string|null}|null
     */
    private function mapAddress(mixed $address): ?array
    {
        if (!$address) {
            return null;
        }

        return [
            'line1' => $address->line1 ?? null,
            'line2' => $address->line2 ?? null,
            'city' => $address->city ?? null,
            'state' => $address->state ?? null,
            'postalCode' => $address->postal_code ?? null,
            'country' => $address->country ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapCustomer(Customer $customer, string|int|null $userId = null): array
    {
        $defaultPaymentMethod = $customer->invoice_settings?->default_payment_method ?? null;

        return [
            'id' => $customer->id,
            'userId' => $userId,
            'email' => $customer->email,
            'name' => $customer->name ?? null,
            'phone' => $customer->phone ?? null,
            'description' => $customer->description ?? null,
            'address' => $this->mapAddress($customer->address ?? null),
            'defaultPaymentMethodId' => is_string($defaultPaymentMethod) ? $defaultPaymentMethod : ($defaultPaymentMethod->id ?? null),
            'metadata' => $customer->metadata ? $customer->metadata->toArray() : [],
            'createdAtUtc' => Timezone::unixToUtcIso($customer->created),
            'deleted' => false,
        ];
    }

    /**
     * @param array{email: string, name?: string, phone?: string, description?: string, address?: array<string, mixed>, taxId?: string, metadata?: array<string, string>, userId?: string|int} $input
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        Validation::assertValidEmail($input['email']);
        $email = Validation::normalizeEmail($input['email']);

        try {
            $address = $input['address'] ?? null;

            $customer = $this->stripe->customers->create([
                'email' => $email,
                'name' => $input['name'] ?? null,
                'phone' => $input['phone'] ?? null,
                'description' => $input['description'] ?? null,
                'address' => $address ? [
                    'line1' => $address['line1'] ?? null,
                    'line2' => $address['line2'] ?? null,
                    'city' => $address['city'] ?? null,
                    'state' => $address['state'] ?? null,
                    'postal_code' => $address['postalCode'] ?? null,
                    'country' => $address['country'] ?? null,
                ] : null,
                'tax_id_data' => !empty($input['taxId']) ? [['type' => 'eu_vat', 'value' => $input['taxId']]] : null,
                'metadata' => array_merge(['source' => 'stripekit'], $input['metadata'] ?? []),
            ]);

            $record = $this->mapCustomer($customer, $input['userId'] ?? null);
            $this->storage?->saveCustomer($record);
            return $record;
        } catch (Throwable $error) {
            throw StripeOperationError::fromError($error, 'Could not create Stripe customer.');
        }
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public function findOrCreateByEmail(string $email, array $extra = []): array
    {
        $normalized = Validation::normalizeEmail($email);
        $existingUser = $this->storage?->findUserByEmail($normalized);

        if (!empty($existingUser['stripeCustomerId'])) {
            return $this->retrieve($existingUser['stripeCustomerId']);
        }

        $existing = $this->stripe->customers->all(['email' => $normalized, 'limit' => 1]);
        if (count($existing->data) > 0) {
            return $this->mapCustomer($existing->data[0], $existingUser['id'] ?? null);
        }

        return $this->create(array_merge(['email' => $normalized, 'userId' => $existingUser['id'] ?? null], $extra));
    }

    /**
     * @return array<string, mixed>
     */
    public function retrieve(string $customerId): array
    {
        try {
            $customer = $this->stripe->customers->retrieve($customerId);
            if ($customer->deleted ?? false) {
                throw new NotFoundError(sprintf('Customer "%s" has been deleted in Stripe.', $customerId));
            }
            return $this->mapCustomer($customer);
        } catch (NotFoundError $error) {
            throw $error;
        } catch (Throwable $error) {
            throw StripeOperationError::fromError($error, sprintf('Could not retrieve customer "%s".', $customerId));
        }
    }

    /**
     * @param array{email?: string, name?: string, phone?: string, description?: string, address?: array<string, mixed>, defaultPaymentMethodId?: string, metadata?: array<string, string>} $input
     * @return array<string, mixed>
     */
    public function update(string $customerId, array $input): array
    {
        try {
            $address = $input['address'] ?? null;

            $customer = $this->stripe->customers->update($customerId, [
                'email' => isset($input['email']) ? Validation::normalizeEmail($input['email']) : null,
                'name' => $input['name'] ?? null,
                'phone' => $input['phone'] ?? null,
                'description' => $input['description'] ?? null,
                'address' => $address ? [
                    'line1' => $address['line1'] ?? null,
                    'line2' => $address['line2'] ?? null,
                    'city' => $address['city'] ?? null,
                    'state' => $address['state'] ?? null,
                    'postal_code' => $address['postalCode'] ?? null,
                    'country' => $address['country'] ?? null,
                ] : null,
                'invoice_settings' => !empty($input['defaultPaymentMethodId'])
                    ? ['default_payment_method' => $input['defaultPaymentMethodId']]
                    : null,
                'metadata' => $input['metadata'] ?? null,
            ]);

            $record = $this->mapCustomer($customer);
            $this->storage?->saveCustomer($record);
            return $record;
        } catch (Throwable $error) {
            throw StripeOperationError::fromError($error, sprintf('Could not update customer "%s".', $customerId));
        }
    }

    /**
     * @return array{id: string, deleted: true}
     */
    public function delete(string $customerId): array
    {
        try {
            $result = $this->stripe->customers->delete($customerId);
            $this->storage?->saveCustomer([
                'id' => $customerId,
                'userId' => null,
                'email' => null,
                'name' => null,
                'phone' => null,
                'description' => null,
                'address' => null,
                'defaultPaymentMethodId' => null,
                'metadata' => [],
                'createdAtUtc' => Timezone::nowUtcIso(),
                'deleted' => true,
            ]);
            return ['id' => $result->id, 'deleted' => true];
        } catch (Throwable $error) {
            throw StripeOperationError::fromError($error, sprintf('Could not delete customer "%s".', $customerId));
        }
    }

    /**
     * @param array{email?: string, limit?: int, startingAfter?: string} $params
     * @return list<array<string, mixed>>
     */
    public function list(array $params = []): array
    {
        try {
            $result = $this->stripe->customers->all([
                'email' => isset($params['email']) ? Validation::normalizeEmail($params['email']) : null,
                'limit' => $params['limit'] ?? 20,
                'starting_after' => $params['startingAfter'] ?? null,
            ]);
            return array_map(fn (Customer $customer) => $this->mapCustomer($customer), $result->data);
        } catch (Throwable $error) {
            throw StripeOperationError::fromError($error, 'Could not list customers.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function sync(string $customerId): array
    {
        $record = $this->retrieve($customerId);
        $this->storage?->saveCustomer($record);
        return $record;
    }
}
