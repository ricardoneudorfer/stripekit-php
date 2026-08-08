<?php

declare(strict_types=1);

namespace StripeKit\Modules;

use Stripe\PaymentIntent;
use StripeKit\Exceptions\ConfigurationError;
use StripeKit\Exceptions\StripeOperationError;
use StripeKit\Support\Timezone;
use StripeKit\Support\Validation;
use Throwable;

class PaymentsModule extends BaseModule
{
    /**
     * @return array<string, mixed>
     */
    private function mapPaymentRecord(PaymentIntent $pi, string|int|null $userId = null): array
    {
        $pm = $pi->payment_method;
        $isExpandedCard = is_object($pm) && $pm->type === 'card' && $pm->card;
        $isExpandedSepa = is_object($pm) && $pm->type === 'sepa_debit' && $pm->sepa_debit;

        $customer = $pi->customer;
        $invoice = $pi->invoice;

        return [
            'id' => $pi->id,
            'userId' => $userId,
            'customerId' => is_string($customer) ? $customer : ($customer->id ?? null),
            'invoiceId' => is_string($invoice) ? $invoice : ($invoice->id ?? null),
            'amount' => $pi->amount,
            'currency' => $pi->currency,
            'status' => $pi->status,
            'description' => $pi->description,
            'paymentMethodType' => is_object($pm) ? $pm->type : null,
            'paymentMethodBrand' => $isExpandedCard ? $pm->card->brand : null,
            'paymentMethodLast4' => $isExpandedCard ? $pm->card->last4 : ($isExpandedSepa ? $pm->sepa_debit->last4 : null),
            'receiptEmail' => $pi->receipt_email,
            'metadata' => $pi->metadata ? $pi->metadata->toArray() : [],
            'createdAtUtc' => Timezone::unixToUtcIso($pi->created),
        ];
    }

    /**
     * @param array{amount: int, currency?: string, customerId?: string, email?: string, userId?: string|int, description?: string, metadata?: array<string, string>, paymentMethodId?: string, receiptEmail?: string, offSession?: bool, confirm?: bool, captureMethod?: string, statementDescriptor?: string, applicationFeeAmount?: int, returnUrl?: string, mode?: string} $input
     * @return array{id: string, mode: string, status: string, amount: int, currency: string, clientSecret: string|null, hostedUrl: string|null, requiresAction: bool}
     */
    public function create(array $input): array
    {
        $currency = strtolower($input['currency'] ?? $this->config['currency']);
        Validation::assertValidCurrency($currency);
        Validation::assertMinimumAmount($input['amount']);

        $flow = $input['mode'] ?? ($this->config['mode'] === 'both' ? 'api' : $this->config['mode']);
        if ($flow !== 'api' && $flow !== 'elements') {
            throw new ConfigurationError('Payment flow must resolve to either "api" or "elements". Set `mode` on init or pass `mode` per call.');
        }

        $metadata = array_merge(['source' => 'stripekit'], $input['metadata'] ?? []);

        try {
            if ($flow === 'api') {
                $session = $this->stripe->checkout->sessions->create([
                    'mode' => 'payment',
                    'line_items' => [
                        [
                            'price_data' => [
                                'currency' => $currency,
                                'unit_amount' => $input['amount'],
                                'product_data' => ['name' => $input['description'] ?? 'Payment'],
                            ],
                            'quantity' => 1,
                        ],
                    ],
                    'customer' => $input['customerId'] ?? null,
                    'customer_email' => empty($input['customerId']) && !empty($input['email']) ? Validation::normalizeEmail($input['email']) : null,
                    'metadata' => $metadata,
                    'payment_intent_data' => ['metadata' => $metadata],
                    'success_url' => ($this->config['successUrl'] ?? 'https://example.com/success') . '?session_id={CHECKOUT_SESSION_ID}',
                    'cancel_url' => $this->config['cancelUrl'] ?? 'https://example.com/cancel',
                ]);

                return [
                    'id' => $session->id,
                    'mode' => 'api',
                    'status' => $session->payment_status,
                    'amount' => $input['amount'],
                    'currency' => $currency,
                    'clientSecret' => null,
                    'hostedUrl' => $session->url,
                    'requiresAction' => false,
                ];
            }

            $paymentIntent = $this->stripe->paymentIntents->create([
                'amount' => $input['amount'],
                'currency' => $currency,
                'customer' => $input['customerId'] ?? null,
                'payment_method' => $input['paymentMethodId'] ?? null,
                'receipt_email' => $input['receiptEmail'] ?? (!empty($input['email']) ? Validation::normalizeEmail($input['email']) : null),
                'description' => $input['description'] ?? null,
                'statement_descriptor' => $input['statementDescriptor'] ?? null,
                'application_fee_amount' => $input['applicationFeeAmount'] ?? null,
                'capture_method' => $input['captureMethod'] ?? 'automatic',
                'automatic_payment_methods' => empty($input['paymentMethodId']) ? ['enabled' => true] : null,
                'confirm' => $input['confirm'] ?? (!empty($input['paymentMethodId']) && !empty($input['offSession'])),
                'off_session' => $input['offSession'] ?? null,
                'metadata' => $metadata,
            ]);

            $record = $this->mapPaymentRecord($paymentIntent, $input['userId'] ?? null);
            $this->storage?->savePayment($record);

            return [
                'id' => $paymentIntent->id,
                'mode' => 'elements',
                'status' => $paymentIntent->status,
                'amount' => $paymentIntent->amount,
                'currency' => $paymentIntent->currency,
                'clientSecret' => $paymentIntent->client_secret,
                'hostedUrl' => null,
                'requiresAction' => $paymentIntent->status === 'requires_action',
            ];
        } catch (Throwable $error) {
            throw StripeOperationError::fromError($error, 'Could not create payment.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function retrieve(string $paymentIntentId): array
    {
        try {
            $pi = $this->stripe->paymentIntents->retrieve($paymentIntentId, ['expand' => ['payment_method']]);
            return $this->mapPaymentRecord($pi);
        } catch (Throwable $error) {
            throw StripeOperationError::fromError($error, sprintf('Could not retrieve payment "%s".', $paymentIntentId));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function confirm(string $paymentIntentId, ?string $paymentMethodId = null): array
    {
        try {
            $pi = $this->stripe->paymentIntents->confirm($paymentIntentId, [
                'payment_method' => $paymentMethodId,
            ]);
            $record = $this->mapPaymentRecord($pi);
            $this->storage?->savePayment($record);
            return $record;
        } catch (Throwable $error) {
            throw StripeOperationError::fromError($error, sprintf('Could not confirm payment "%s".', $paymentIntentId));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function cancel(string $paymentIntentId): array
    {
        try {
            $pi = $this->stripe->paymentIntents->cancel($paymentIntentId);
            return $this->mapPaymentRecord($pi);
        } catch (Throwable $error) {
            throw StripeOperationError::fromError($error, sprintf('Could not cancel payment "%s".', $paymentIntentId));
        }
    }

    /**
     * @param array{paymentIntentId?: string, amount?: int, currency?: string, customerId: string, paymentMethodId: string, description?: string, metadata?: array<string, string>, returnUrl?: string} $input
     * @return array{id: string, mode: string, status: string, amount: int, currency: string, clientSecret: string|null, hostedUrl: string|null, requiresAction: bool}
     */
    public function payWithSavedMethod(array $input): array
    {
        try {
            if (!empty($input['paymentIntentId'])) {
                $pi = $this->stripe->paymentIntents->confirm($input['paymentIntentId'], [
                    'payment_method' => $input['paymentMethodId'],
                    'off_session' => true,
                    'return_url' => $input['returnUrl'] ?? null,
                ]);
            } else {
                if (empty($input['amount']) || empty($input['currency'])) {
                    throw new ConfigurationError('`amount` and `currency` are required when no existing paymentIntentId is provided.');
                }
                Validation::assertValidCurrency($input['currency']);
                Validation::assertMinimumAmount($input['amount']);

                $pi = $this->stripe->paymentIntents->create([
                    'amount' => $input['amount'],
                    'currency' => strtolower($input['currency']),
                    'customer' => $input['customerId'],
                    'payment_method' => $input['paymentMethodId'],
                    'automatic_payment_methods' => ['enabled' => true, 'allow_redirects' => 'never'],
                    'description' => $input['description'] ?? null,
                    'confirm' => true,
                    'off_session' => true,
                    'metadata' => array_merge(['source' => 'stripekit_saved_method'], $input['metadata'] ?? []),
                ]);
            }

            $record = $this->mapPaymentRecord($pi);
            $this->storage?->savePayment($record);

            return [
                'id' => $pi->id,
                'mode' => 'elements',
                'status' => $pi->status,
                'amount' => $pi->amount,
                'currency' => $pi->currency,
                'clientSecret' => $pi->client_secret,
                'hostedUrl' => null,
                'requiresAction' => $pi->status === 'requires_action',
            ];
        } catch (ConfigurationError $error) {
            throw $error;
        } catch (Throwable $error) {
            throw StripeOperationError::fromError($error, 'Could not charge the saved payment method.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function sync(string $paymentIntentId, string|int|null $userId = null): array
    {
        $pi = $this->stripe->paymentIntents->retrieve($paymentIntentId, ['expand' => ['payment_method']]);
        $record = $this->mapPaymentRecord($pi, $userId);
        $this->storage?->savePayment($record);
        return $record;
    }
}
