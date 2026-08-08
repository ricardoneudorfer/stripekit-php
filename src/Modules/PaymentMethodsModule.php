<?php

declare(strict_types=1);

namespace StripeKit\Modules;

use Stripe\PaymentMethod;
use StripeKit\Exceptions\StripeOperationError;
use StripeKit\Support\Timezone;
use Throwable;

class PaymentMethodsModule extends BaseModule
{
    /**
     * @return array<string, mixed>
     */
    private function mapPaymentMethod(PaymentMethod $pm, ?string $defaultId, string|int|null $userId = null): array
    {
        $isCard = $pm->type === 'card' && $pm->card;
        $isSepa = $pm->type === 'sepa_debit' && $pm->sepa_debit;

        $customer = $pm->customer;

        return [
            'id' => $pm->id,
            'userId' => $userId,
            'customerId' => is_string($customer) ? $customer : ($customer->id ?? null),
            'type' => $pm->type,
            'brand' => $isCard ? $pm->card->brand : null,
            'last4' => $isCard ? $pm->card->last4 : ($isSepa ? $pm->sepa_debit->last4 : null),
            'expMonth' => $isCard ? $pm->card->exp_month : null,
            'expYear' => $isCard ? $pm->card->exp_year : null,
            'isDefault' => $defaultId === $pm->id,
            'createdAtUtc' => Timezone::unixToUtcIso($pm->created),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(string $customerId, string|int|null $userId = null): array
    {
        try {
            $methods = $this->stripe->paymentMethods->all(['customer' => $customerId, 'limit' => 100]);
            $customer = $this->stripe->customers->retrieve($customerId);

            $defaultId = null;
            if (!($customer->deleted ?? false)) {
                $dpm = $customer->invoice_settings?->default_payment_method ?? null;
                $defaultId = is_string($dpm) ? $dpm : ($dpm->id ?? null);
            }

            $records = array_map(fn (PaymentMethod $pm) => $this->mapPaymentMethod($pm, $defaultId, $userId), $methods->data);
            if ($userId !== null) {
                $this->storage?->savePaymentMethods($userId, $records);
            }
            return $records;
        } catch (Throwable $error) {
            throw StripeOperationError::fromError($error, sprintf('Could not list payment methods for customer "%s".', $customerId));
        }
    }

    /**
     * @param array{paymentMethodId: string, customerId: string, setAsDefault?: bool} $input
     * @return array<string, mixed>
     */
    public function attach(array $input): array
    {
        try {
            $pm = $this->stripe->paymentMethods->attach($input['paymentMethodId'], [
                'customer' => $input['customerId'],
            ]);

            if (!empty($input['setAsDefault'])) {
                $this->stripe->customers->update($input['customerId'], [
                    'invoice_settings' => ['default_payment_method' => $input['paymentMethodId']],
                ]);
            }

            return $this->mapPaymentMethod($pm, !empty($input['setAsDefault']) ? $input['paymentMethodId'] : null);
        } catch (Throwable $error) {
            throw StripeOperationError::fromError($error, sprintf('Could not attach payment method "%s".', $input['paymentMethodId']));
        }
    }

    /**
     * @return array{id: string, detached: true}
     */
    public function detach(string $paymentMethodId): array
    {
        try {
            $pm = $this->stripe->paymentMethods->detach($paymentMethodId);
            return ['id' => $pm->id, 'detached' => true];
        } catch (Throwable $error) {
            throw StripeOperationError::fromError($error, sprintf('Could not detach payment method "%s".', $paymentMethodId));
        }
    }

    public function setDefault(string $customerId, string $paymentMethodId): void
    {
        try {
            $this->stripe->customers->update($customerId, [
                'invoice_settings' => ['default_payment_method' => $paymentMethodId],
            ]);
        } catch (Throwable $error) {
            throw StripeOperationError::fromError($error, sprintf('Could not set default payment method for customer "%s".', $customerId));
        }
    }

    /**
     * @param array{customerId: string, usage?: string, metadata?: array<string, string>} $input
     * @return array{id: string, clientSecret: string|null, status: string}
     */
    public function createSetupIntent(array $input): array
    {
        try {
            $setupIntent = $this->stripe->setupIntents->create([
                'customer' => $input['customerId'],
                'usage' => $input['usage'] ?? 'off_session',
                'automatic_payment_methods' => ['enabled' => true],
                'metadata' => $input['metadata'] ?? null,
            ]);

            return [
                'id' => $setupIntent->id,
                'clientSecret' => $setupIntent->client_secret,
                'status' => $setupIntent->status,
            ];
        } catch (Throwable $error) {
            throw StripeOperationError::fromError($error, 'Could not create setup intent.');
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function sync(string $customerId, string|int|null $userId = null): array
    {
        return $this->list($customerId, $userId);
    }
}
