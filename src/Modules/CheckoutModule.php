<?php

declare(strict_types=1);

namespace StripeKit\Modules;

use StripeKit\Exceptions\ConfigurationError;
use StripeKit\Exceptions\NotFoundError;
use StripeKit\Exceptions\StripeOperationError;
use StripeKit\Exceptions\ValidationError;
use StripeKit\Support\Timezone;
use StripeKit\Support\Tokens;
use StripeKit\Support\Validation;
use Throwable;

class CheckoutModule extends BaseModule
{
    private ?CouponsModule $coupons = null;

    /** @var array<string, array<string, mixed>> */
    private static array $inMemoryFallbackStore = [];

    public function attachCouponsModule(CouponsModule $coupons): void
    {
        $this->coupons = $coupons;
    }

    /**
     * @param array<string, mixed> $session
     */
    private function persistSession(array $session): void
    {
        if ($this->storage !== null && $this->storage->overrides('saveCheckoutSession')) {
            $this->storage->saveCheckoutSession($session);
        } else {
            self::$inMemoryFallbackStore[$session['id']] = $session;
            $this->logger->warn(
                'No `storage.saveCheckoutSession` adapter configured. Checkout sessions are held in local process memory and will not survive a restart or work across multiple instances. Provide a storage adapter for production deployments.',
            );
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadSession(string $checkoutId): ?array
    {
        if ($this->storage !== null && $this->storage->overrides('getCheckoutSession')) {
            return $this->storage->getCheckoutSession($checkoutId);
        }
        return self::$inMemoryFallbackStore[$checkoutId] ?? null;
    }

    private function resolveFlow(?string $override = null): string
    {
        if ($override) {
            return $override;
        }
        if ($this->config['mode'] === 'both') {
            return 'api';
        }
        return $this->config['mode'];
    }

    /**
     * @param array{mode: string, amount?: int, currency?: string, priceId?: string, email?: string, userId?: string|int, description?: string, metadata?: array<string, string>, customFields?: list<array<string, mixed>>, fieldValues?: array<string, string>, successUrl?: string, cancelUrl?: string, couponCode?: string, flowOverride?: string} $input
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        if ($input['mode'] !== 'payment' && $input['mode'] !== 'subscription') {
            throw new ValidationError('Checkout `mode` must be "payment" or "subscription".');
        }

        $flow = $this->resolveFlow($input['flowOverride'] ?? null);
        $currency = strtolower($input['currency'] ?? $this->config['currency']);
        Validation::assertValidCurrency($currency);

        $email = !empty($input['email']) ? Validation::normalizeEmail($input['email']) : null;
        if ($email) {
            Validation::assertValidEmail($email);
        }

        $customerId = null;
        $storedUser = null;

        if (isset($input['userId'])) {
            $storedUser = $this->storage?->findUserById($input['userId']);
            $customerId = $storedUser['stripeCustomerId'] ?? null;
        } elseif ($email) {
            $storedUser = $this->storage?->findUserByEmail($email);
            $customerId = $storedUser['stripeCustomerId'] ?? null;
        }

        if (!$customerId && $email) {
            $customer = $this->stripe->customers->create([
                'email' => $email,
                'metadata' => ['source' => 'stripekit_guest_checkout'],
            ]);
            $customerId = $customer->id;
        }

        $fieldSchema = $input['customFields'] ?? [];
        $fieldValues = [];

        if (!empty($input['fieldValues']) && count($fieldSchema) > 0) {
            $result = Validation::validateCustomFieldSchema($fieldSchema, $input['fieldValues']);
            if (count($result['errors']) > 0) {
                throw new ValidationError('Custom field validation failed.', $result['errors']);
            }
            $fieldValues = $result['values'];
        }

        $checkoutId = Tokens::generateCheckoutId();
        $metadata = array_merge(
            ['source' => 'stripekit_checkout', 'checkout_id' => $checkoutId],
            $input['metadata'] ?? [],
        );
        foreach ($fieldValues as $key => $value) {
            $metadata['field_' . $key] = $value;
        }

        $result = null;

        try {
            if ($input['mode'] === 'subscription') {
                if (empty($input['priceId'])) {
                    throw new ValidationError('Subscriptions require a `priceId`.');
                }
                if (!$customerId) {
                    throw new ValidationError('Subscriptions require an `email` or an existing `userId` with a Stripe customer.');
                }

                if ($flow === 'api') {
                    $session = $this->stripe->checkout->sessions->create([
                        'mode' => 'subscription',
                        'customer' => $customerId,
                        'line_items' => [['price' => $input['priceId'], 'quantity' => 1]],
                        'metadata' => $metadata,
                        'subscription_data' => ['metadata' => $metadata],
                        'discounts' => !empty($input['couponCode']) ? [['promotion_code' => $this->resolvePromotionCodeId($input['couponCode'])]] : null,
                        'success_url' => ($input['successUrl'] ?? $this->config['successUrl'] ?? 'https://example.com/success') . '?session_id={CHECKOUT_SESSION_ID}',
                        'cancel_url' => $input['cancelUrl'] ?? $this->config['cancelUrl'] ?? 'https://example.com/cancel',
                    ]);

                    $subscription = $session->subscription;

                    $result = [
                        'id' => $session->id,
                        'mode' => 'subscription',
                        'flow' => 'api',
                        'clientSecret' => null,
                        'hostedUrl' => $session->url,
                        'paymentIntentId' => null,
                        'subscriptionId' => is_string($subscription) ? $subscription : null,
                        'amount' => 0,
                        'currency' => $currency,
                        'requiresFields' => count($fieldSchema) > 0 && count($fieldValues) === 0,
                        'fieldSchema' => $fieldSchema,
                        'expiresAtUtc' => Timezone::addDaysUtcIso(Timezone::nowUtcIso(), 1),
                    ];
                } else {
                    if (empty($input['amount'])) {
                        throw new ValidationError('An initial `amount` is required to open a subscription in Elements mode.');
                    }
                    Validation::assertMinimumAmount($input['amount']);

                    $paymentIntent = $this->stripe->paymentIntents->create([
                        'amount' => $input['amount'],
                        'currency' => $currency,
                        'customer' => $customerId,
                        'automatic_payment_methods' => ['enabled' => true],
                        'description' => $input['description'] ?? 'Subscription setup',
                        'setup_future_usage' => 'off_session',
                        'metadata' => $metadata,
                    ]);

                    $result = [
                        'id' => $checkoutId,
                        'mode' => 'subscription',
                        'flow' => 'elements',
                        'clientSecret' => $paymentIntent->client_secret,
                        'hostedUrl' => null,
                        'paymentIntentId' => $paymentIntent->id,
                        'subscriptionId' => null,
                        'amount' => $input['amount'],
                        'currency' => $currency,
                        'requiresFields' => count($fieldSchema) > 0 && count($fieldValues) === 0,
                        'fieldSchema' => $fieldSchema,
                        'expiresAtUtc' => Timezone::addDaysUtcIso(Timezone::nowUtcIso(), 1),
                    ];
                }
            } else {
                if (empty($input['amount'])) {
                    throw new ValidationError('Payment checkouts require an `amount` in minor currency units.');
                }
                Validation::assertMinimumAmount($input['amount']);

                if ($flow === 'api') {
                    $session = $this->stripe->checkout->sessions->create([
                        'mode' => 'payment',
                        'customer' => $customerId,
                        'customer_email' => !$customerId && $email ? $email : null,
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
                        'metadata' => $metadata,
                        'payment_intent_data' => ['metadata' => $metadata],
                        'discounts' => !empty($input['couponCode']) ? [['promotion_code' => $this->resolvePromotionCodeId($input['couponCode'])]] : null,
                        'success_url' => ($input['successUrl'] ?? $this->config['successUrl'] ?? 'https://example.com/success') . '?session_id={CHECKOUT_SESSION_ID}',
                        'cancel_url' => $input['cancelUrl'] ?? $this->config['cancelUrl'] ?? 'https://example.com/cancel',
                    ]);

                    $paymentIntentId = $session->payment_intent;

                    $result = [
                        'id' => $session->id,
                        'mode' => 'payment',
                        'flow' => 'api',
                        'clientSecret' => null,
                        'hostedUrl' => $session->url,
                        'paymentIntentId' => is_string($paymentIntentId) ? $paymentIntentId : null,
                        'subscriptionId' => null,
                        'amount' => $input['amount'],
                        'currency' => $currency,
                        'requiresFields' => count($fieldSchema) > 0 && count($fieldValues) === 0,
                        'fieldSchema' => $fieldSchema,
                        'expiresAtUtc' => Timezone::addDaysUtcIso(Timezone::nowUtcIso(), 1),
                    ];
                } else {
                    $paymentIntent = $this->stripe->paymentIntents->create([
                        'amount' => $input['amount'],
                        'currency' => $currency,
                        'customer' => $customerId,
                        'receipt_email' => $email,
                        'description' => $input['description'] ?? null,
                        'automatic_payment_methods' => ['enabled' => true],
                        'metadata' => $metadata,
                    ]);

                    $result = [
                        'id' => $checkoutId,
                        'mode' => 'payment',
                        'flow' => 'elements',
                        'clientSecret' => $paymentIntent->client_secret,
                        'hostedUrl' => null,
                        'paymentIntentId' => $paymentIntent->id,
                        'subscriptionId' => null,
                        'amount' => $input['amount'],
                        'currency' => $currency,
                        'requiresFields' => count($fieldSchema) > 0 && count($fieldValues) === 0,
                        'fieldSchema' => $fieldSchema,
                        'expiresAtUtc' => Timezone::addDaysUtcIso(Timezone::nowUtcIso(), 1),
                    ];
                }
            }
        } catch (ValidationError $error) {
            throw $error;
        } catch (Throwable $error) {
            throw StripeOperationError::fromError($error, 'Could not create checkout.');
        }

        $this->persistSession([
            'id' => $checkoutId,
            'mode' => $input['mode'],
            'flow' => $flow,
            'amount' => $result['amount'],
            'currency' => $currency,
            'priceId' => $input['priceId'] ?? null,
            'description' => $input['description'] ?? null,
            'metadata' => $input['metadata'] ?? [],
            'customFields' => $fieldSchema,
            'fieldValues' => $fieldValues,
            'email' => $email,
            'userId' => $input['userId'] ?? $storedUser['id'] ?? null,
            'couponCode' => $input['couponCode'] ?? null,
            'stripePaymentIntentId' => $result['paymentIntentId'],
            'stripeSubscriptionId' => $result['subscriptionId'],
            'clientSecret' => $result['clientSecret'],
            'hostedUrl' => $result['hostedUrl'],
            'status' => 'open',
            'createdAtUtc' => Timezone::nowUtcIso(),
            'expiresAtUtc' => $result['expiresAtUtc'],
        ]);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $checkoutId): array
    {
        $session = $this->loadSession($checkoutId);
        if (!$session) {
            throw new NotFoundError(sprintf('Checkout session "%s" was not found.', $checkoutId));
        }

        return [
            'id' => $session['id'],
            'mode' => $session['mode'],
            'flow' => $session['flow'],
            'clientSecret' => $session['clientSecret'],
            'hostedUrl' => $session['hostedUrl'],
            'paymentIntentId' => $session['stripePaymentIntentId'],
            'subscriptionId' => $session['stripeSubscriptionId'],
            'amount' => $session['amount'],
            'currency' => $session['currency'],
            'requiresFields' => count($session['customFields']) > 0 && count($session['fieldValues']) === 0,
            'fieldSchema' => $session['customFields'],
            'expiresAtUtc' => $session['expiresAtUtc'],
            'fieldValues' => $session['fieldValues'],
            'status' => $session['status'],
        ];
    }

    /**
     * @param array<string, string> $submitted
     * @return array<string, string>
     */
    public function submitFields(string $checkoutId, array $submitted): array
    {
        $session = $this->loadSession($checkoutId);
        if (!$session) {
            throw new NotFoundError(sprintf('Checkout session "%s" was not found.', $checkoutId));
        }
        if ($session['status'] !== 'open') {
            throw new ValidationError('This checkout session is no longer open.');
        }

        $result = Validation::validateCustomFieldSchema($session['customFields'], $submitted);
        if (count($result['errors']) > 0) {
            throw new ValidationError('Custom field validation failed.', $result['errors']);
        }

        $session['fieldValues'] = $result['values'];

        $metadataPatch = [];
        foreach ($result['values'] as $key => $value) {
            $metadataPatch['field_' . $key] = $value;
        }

        try {
            if ($session['stripePaymentIntentId']) {
                $this->stripe->paymentIntents->update($session['stripePaymentIntentId'], ['metadata' => $metadataPatch]);
            } elseif ($session['stripeSubscriptionId']) {
                $this->stripe->subscriptions->update($session['stripeSubscriptionId'], ['metadata' => $metadataPatch]);
            }
        } catch (Throwable) {
        }

        $this->persistSession($session);
        return $result['values'];
    }

    private function resolvePromotionCodeId(string $code): string
    {
        if (!$this->coupons) {
            throw new ConfigurationError('Coupons module is not attached to Checkout module.');
        }
        $coupon = $this->coupons->validate($code);
        if (!$coupon) {
            throw new ValidationError(sprintf('Coupon code "%s" is invalid or expired.', $code));
        }
        return $coupon['stripePromotionCodeId'];
    }

    /**
     * @param array{checkoutId: string, couponCode: string|null, originalAmount: int, paymentIntentId?: string|null} $input
     * @return array{newAmount: int, isFree: bool, clientSecret: string|null}
     */
    public function applyCoupon(array $input): array
    {
        $session = $this->loadSession($input['checkoutId']);
        if (!$session) {
            throw new NotFoundError(sprintf('Checkout session "%s" was not found.', $input['checkoutId']));
        }

        if (empty($input['couponCode'])) {
            $session['couponCode'] = null;
            if ($session['stripePaymentIntentId']) {
                $pi = $this->stripe->paymentIntents->update($session['stripePaymentIntentId'], [
                    'amount' => $input['originalAmount'],
                ]);
                $session['amount'] = $input['originalAmount'];
                $session['clientSecret'] = $pi->client_secret;
                $this->persistSession($session);
                return ['newAmount' => $input['originalAmount'], 'isFree' => false, 'clientSecret' => $pi->client_secret];
            }
            $this->persistSession($session);
            return ['newAmount' => $input['originalAmount'], 'isFree' => false, 'clientSecret' => $session['clientSecret']];
        }

        if (!$this->coupons) {
            throw new ConfigurationError('Coupons module is not attached to Checkout module.');
        }
        $coupon = $this->coupons->validate($input['couponCode']);
        if (!$coupon) {
            throw new ValidationError(sprintf('Coupon code "%s" is invalid or expired.', $input['couponCode']));
        }

        $newAmount = $coupon['discountType'] === 'percent'
            ? max(0, (int) round($input['originalAmount'] * (1 - $coupon['discountValue'] / 100)))
            : max(0, $input['originalAmount'] - (int) round($coupon['discountValue'] * 100));

        $session['couponCode'] = $input['couponCode'];

        if ($newAmount === 0) {
            $session['amount'] = 0;
            $this->persistSession($session);
            return ['newAmount' => 0, 'isFree' => true, 'clientSecret' => null];
        }

        if (!$session['stripePaymentIntentId']) {
            throw new ValidationError('This checkout session has no active payment intent to discount.');
        }

        $pi = $this->stripe->paymentIntents->update($session['stripePaymentIntentId'], ['amount' => $newAmount]);
        $session['amount'] = $newAmount;
        $session['clientSecret'] = $pi->client_secret;
        $this->persistSession($session);

        return ['newAmount' => $newAmount, 'isFree' => false, 'clientSecret' => $pi->client_secret];
    }

    public function markComplete(string $checkoutId): void
    {
        $session = $this->loadSession($checkoutId);
        if ($session) {
            $session['status'] = 'complete';
            $this->persistSession($session);
        }
    }

    /**
     * @return array{token: string, hashedToken: string}
     */
    public function createGuestClaimToken(string $email): array
    {
        $token = Tokens::randomToken(32);
        return ['token' => $token, 'hashedToken' => Tokens::hashToken($token)];
    }
}
