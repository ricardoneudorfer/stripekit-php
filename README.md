# StripeKit

A complete, production-ready Stripe toolkit for PHP. StripeKit wraps the official `stripe/stripe-php` SDK with a simple, opinionated layer for customers, payment methods, payments, checkout, subscriptions, invoices, coupons and webhooks — so you can integrate Stripe into your own application without becoming a Stripe API expert.

You stay in full control of your database and UI. StripeKit only talks to Stripe: it creates and reads Stripe objects, normalizes the responses into clean, timezone-aware records, and — if you give it a storage adapter — persists that normalized state for you.

## Why StripeKit

- **One `init()` call.** Provide your secret key, choose `api` or `elements` mode, optionally set a timezone, and every module is ready to use.
- **You choose the flow.** In `api` mode, payment and checkout calls return a hosted Stripe URL you redirect the customer to. In `elements` mode, they return a `clientSecret` your own frontend uses to confirm the payment with Stripe Elements. In `both` mode, you decide per call.
- **Elements are fully optional.** The browser-side helpers ship as a separate JS asset under `resources/js/stripekit-elements.js`. Stripe Elements is inherently browser code, so it cannot run in PHP — StripeKit ships it as a companion frontend module you serve alongside your PHP backend. If you don't need Stripe Elements, you never have to include it.
- **Timezones handled correctly.** Every timestamp StripeKit returns is first normalized to UTC (Stripe's native format), then — if you configured a timezone — converted once more into that timezone for display. Both values are always returned so you never have to do the math yourself.
- **You own your data.** StripeKit never requires a database. Pass an optional `storage` adapter to persist customers, subscriptions, invoices, payments and payment methods in your own schema; without one, StripeKit still works, it just won't persist anything for you.
- **Covers the whole billing surface.** Customers, payment methods, one-off payments, checkout (payment or subscription, with custom fields and coupons), subscriptions, invoices, coupons/promotion codes, and webhooks with automatic state sync.

## Installation

```bash
composer require ricardoneudorfer/stripekit
```

`stripe/stripe-php` is installed automatically as a dependency.

If you plan to use Stripe Elements in the browser, serve the bundled asset at
`vendor/ricardoneudorfer/stripekit/resources/js/stripekit-elements.js` from your
public web root (or copy/symlink it into your own asset pipeline).

## Quick start

```php
<?php

require 'vendor/autoload.php';

use StripeKit\StripeKit;

$kit = StripeKit::init([
    'secretKey' => getenv('STRIPE_SECRET_KEY'),
    'mode' => 'api',
    'timezone' => 'Europe/Amsterdam',
    'currency' => 'eur',
]);

$payment = $kit->payments->create([
    'amount' => 2500,
    'currency' => 'eur',
    'email' => 'customer@example.com',
    'description' => 'Pro plan',
]);

echo $payment['hostedUrl'];
```

Every action StripeKit can perform is available under `$kit-><module>-><action>()`. Initialize `StripeKit` once and share the instance across your app (e.g. via a DI container or a singleton factory).

## Initialization

```php
StripeKit::init([
    'secretKey' => string,
    'publishableKey' => string,          // optional
    'webhookSecret' => string,           // optional
    'mode' => 'api' | 'elements' | 'both',
    'timezone' => string,                // optional
    'currency' => string,                // optional
    'successUrl' => string,              // optional
    'cancelUrl' => string,                // optional
    'storage' => StorageAdapter,          // optional
    'apiVersion' => string,               // optional
    'appInfo' => ['name' => string, 'version' => string, 'url' => string], // optional
    'debug' => bool,                      // optional
    'maxNetworkRetries' => int,           // optional
    'timeout' => int,                     // optional, milliseconds
]);
```

| Option | Required | Description |
| --- | --- | --- |
| `secretKey` | Yes | Your Stripe secret key (`sk_live_...` / `sk_test_...`). Never expose this in the browser. |
| `mode` | Yes | `'api'`, `'elements'`, or `'both'`. Explained below. |
| `publishableKey` | Only for `elements`/`both` | Your Stripe publishable key. Not used server-side, but returned via `$kit->toClientConfig()` so your frontend can fetch it from your own backend instead of hardcoding it. |
| `webhookSecret` | Only if using `$kit->webhooks` | Your endpoint's signing secret from the Stripe Dashboard. |
| `timezone` | No, defaults to `"UTC"` | Any IANA timezone, e.g. `"Europe/Amsterdam"`, `"America/New_York"`. See [Timezones](#timezones). |
| `currency` | No, defaults to `"usd"` | Default 3-letter ISO currency for calls that don't specify one. |
| `successUrl` / `cancelUrl` | No | Default redirect URLs used by hosted Checkout Sessions when not passed per call. |
| `storage` | No | A `StorageAdapter` implementation. See [Storage adapter](#storage-adapter). |

If required options are missing or invalid, `StripeKit::init()` throws a `ConfigurationError` immediately, so misconfiguration is caught at boot time rather than mid-request.

### Choosing a mode

This is the single most important decision StripeKit asks you to make, because it decides what your payment and checkout calls hand back to you:

- **`mode: 'api'`** — StripeKit creates a Stripe-hosted Checkout Session and returns `hostedUrl`. You redirect the customer there; Stripe hosts the entire payment form. Nothing to build on your frontend.
- **`mode: 'elements'`** — StripeKit creates a `PaymentIntent` (or `SetupIntent`) and returns `clientSecret`. You mount your own Stripe Elements form (using the optional `resources/js/stripekit-elements.js` helper, or your own code) and confirm the payment yourself. Full control over your UI.
- **`mode: 'both'`** — StripeKit defaults to the `'api'` behavior, but every call that creates a payment or checkout accepts a `mode` (or `flowOverride`) argument to choose per call.

```php
$kit->payments->create(['amount' => 1000, 'mode' => 'elements']); // works even if global mode is 'api', as long as it's 'both'
```

## Timezones

Stripe stores every timestamp as a Unix epoch — which is UTC by definition. StripeKit always does the conversion in two explicit steps, never one:

1. **Normalize to UTC.** The raw Unix timestamp is converted into an ISO-8601 UTC string. This is always available as the `...Utc` field (e.g. `currentPeriodEndUtc`, `issuedAtUtc`, `paidAtUtc`).
2. **Convert to your configured timezone.** If you set a `timezone` on init (anything other than `"UTC"`, the default), that UTC value is converted once more into your chosen timezone and exposed as the matching `...Local` field (e.g. `currentPeriodEndLocal`).

```php
$kit = StripeKit::init(['secretKey' => $secretKey, 'mode' => 'api', 'timezone' => 'Asia/Tokyo']);

$sub = $kit->subscriptions->retrieve('sub_123');
echo $sub['currentPeriodEndUtc'];   // "2026-09-01T00:00:00.000Z"
echo $sub['currentPeriodEndLocal']; // "2026-09-01T09:00:00" in Asia/Tokyo
```

If you don't set a `timezone`, both fields are UTC, and no double conversion happens. You can also use the exported helpers directly:

```php
use StripeKit\Support\Timezone;

Timezone::unixToTimezone(1735689600, 'Europe/Amsterdam');
Timezone::nowInTimezone('Europe/Amsterdam');
```

## Storage adapter

StripeKit does not require a database, but every module that creates or reads Stripe state will call into an optional `storage` adapter if you provide one, so your own database always reflects reality without you writing that glue code yourself.

```php
use StripeKit\Contracts\StorageAdapter;

class MyStorageAdapter extends StorageAdapter
{
    public function findUserByEmail(string $email): ?array { /* return ['id' => ..., 'stripeCustomerId' => ...] or null */ }
    public function findUserById(string|int $id): ?array { /* ... */ }
    public function saveCustomer(array $record): void { /* upsert into your users/customers table */ }
    public function saveSubscription(array $record): void { /* upsert into your subscriptions table */ }
    public function savePayment(array $record): void { /* upsert into your payments table */ }
    public function saveInvoice(array $record): void { /* upsert into your invoices table */ }
    public function savePaymentMethods(string|int $userId, array $records): void { /* replace the user's saved methods */ }
    public function saveCoupon(array $record): void { /* cache coupon/promo code metadata */ }
    public function markInvoiceDeleted(string $invoiceId): void { /* soft-delete */ }
    public function hasProcessedWebhookEvent(string $eventId): bool { /* idempotency check, recommended in production */ }
    public function markWebhookEventProcessed(string $eventId, string $type): void { /* ... */ }
    public function saveCheckoutSession(array $session): void { /* persist in-flight custom checkout sessions */ }
    public function getCheckoutSession(string $checkoutId): ?array { /* ... */ }
}

$kit = StripeKit::init(['secretKey' => $secretKey, 'mode' => 'api', 'storage' => new MyStorageAdapter()]);
```

Every method on the adapter is optional — override only what you need; `StorageAdapter` is an abstract class with safe no-op defaults. See `examples/storage-adapter-postgres.php` for a full PDO/Postgres-backed implementation.

> **Note on statelessness:** `$kit->checkout` and `$kit->webhooks` fall back to in-process memory for checkout sessions and webhook idempotency when no storage adapter is supplied. That's fine for local development or a single-instance deployment, but for production deployments running multiple instances, provide `saveCheckoutSession`/`getCheckoutSession` and `hasProcessedWebhookEvent`/`markWebhookEventProcessed` so state is shared correctly. StripeKit will log a warning whenever it falls back.

## Modules

### `$kit->customers`

```php
$kit->customers->create(['email' => $email, 'name' => $name, 'phone' => null, 'address' => null, 'metadata' => null]);
$kit->customers->findOrCreateByEmail($email);
$kit->customers->retrieve($customerId);
$kit->customers->update($customerId, ['name' => null, 'phone' => null, 'address' => null, 'defaultPaymentMethodId' => null, 'metadata' => null]);
$kit->customers->delete($customerId);
$kit->customers->list(['email' => null, 'limit' => null, 'startingAfter' => null]);
$kit->customers->sync($customerId); // re-pull from Stripe and persist via storage
```

### `$kit->paymentMethods`

```php
$kit->paymentMethods->list($customerId);
$kit->paymentMethods->attach(['paymentMethodId' => $id, 'customerId' => $customerId, 'setAsDefault' => false]);
$kit->paymentMethods->detach($paymentMethodId);
$kit->paymentMethods->setDefault($customerId, $paymentMethodId);
$kit->paymentMethods->createSetupIntent(['customerId' => $customerId, 'usage' => null]); // to save a card for later, via Elements
$kit->paymentMethods->sync($customerId);
```

### `$kit->payments`

One-off payments (PaymentIntents), respecting the `mode` you configured.

```php
$payment = $kit->payments->create([
    'amount' => 1999,            // minor currency units (cents)
    'currency' => 'usd',
    'email' => 'customer@example.com',
    'description' => 'One-time purchase',
]);
// $payment['hostedUrl']    -> set when the resolved flow is 'api'
// $payment['clientSecret'] -> set when the resolved flow is 'elements'

$kit->payments->retrieve($paymentIntentId);
$kit->payments->confirm($paymentIntentId, $paymentMethodId);
$kit->payments->cancel($paymentIntentId);

// Charge a card the customer already saved, without any customer interaction:
$kit->payments->payWithSavedMethod([
    'customerId' => $customerId,
    'paymentMethodId' => $paymentMethodId,
    'amount' => 999,
    'currency' => 'usd',
]);
```

### `$kit->checkout`

The high-level module for building your own checkout flow: one-off payments or subscriptions, with optional custom fields and coupon codes, in either flow.

```php
$checkout = $kit->checkout->create([
    'mode' => 'subscription',              // or 'payment'
    'priceId' => 'price_123',              // required for subscriptions
    'amount' => 4900,                      // required for one-off payments (and for subscriptions in 'elements' mode)
    'email' => 'customer@example.com',
    'couponCode' => 'WELCOME10',
    'customFields' => [
        ['key' => 'company_name', 'label' => 'Company name', 'required' => true],
    ],
    'fieldValues' => ['company_name' => 'Acme BV'],
]);

$kit->checkout->get($checkout['id']);
$kit->checkout->submitFields($checkout['id'], ['company_name' => 'Acme BV']);
$kit->checkout->applyCoupon(['checkoutId' => $checkout['id'], 'couponCode' => 'SAVE20', 'originalAmount' => 4900]);
$kit->checkout->markComplete($checkout['id']);
```

### `$kit->subscriptions`

```php
$kit->subscriptions->create(['customerId' => $customerId, 'priceId' => $priceId, 'quantity' => null, 'trialPeriodDays' => null, 'collectionMethod' => null]);
$kit->subscriptions->retrieve($subscriptionId);
$kit->subscriptions->cancel(['subscriptionId' => $subscriptionId, 'atPeriodEnd' => true]);
$kit->subscriptions->resume($subscriptionId);
$kit->subscriptions->toggleCollectionMethod(['subscriptionId' => $subscriptionId, 'collectionMethod' => 'send_invoice', 'daysUntilDue' => 14]);
$kit->subscriptions->updateFields(['subscriptionId' => $subscriptionId, 'fieldValues' => ['seats' => '10']]);
$kit->subscriptions->applyPromotionCode($subscriptionId, $promotionCodeId);
$kit->subscriptions->listByCustomer($customerId);
$kit->subscriptions->sync($subscriptionId);
```

### `$kit->invoices`

```php
$kit->invoices->retrieve($invoiceId);
$kit->invoices->listByCustomer($customerId, $status);
$kit->invoices->listBySubscription($subscriptionId);
$kit->invoices->payWithSavedMethod(['invoiceId' => $invoiceId, 'customerId' => $customerId, 'paymentMethodId' => $paymentMethodId]);
$kit->invoices->voidInvoice($invoiceId);
$kit->invoices->finalize($invoiceId);
$kit->invoices->sync($invoiceId);
```

Invoices include a normalized `lineItems` array and both UTC and local timestamps for `dueAt`, `paidAt` and `issuedAt`.

### `$kit->coupons`

```php
$kit->coupons->create(['code' => 'SUMMER25', 'discountType' => 'percent', 'discountValue' => 25, 'duration' => 'once']);
$kit->coupons->validate('SUMMER25'); // returns null if invalid, expired or inactive
$kit->coupons->applyToSubscription($subscriptionId, $stripePromotionCodeId);
$kit->coupons->list();
$kit->coupons->deactivate($stripePromotionCodeId);
```

### `$kit->webhooks`

```php
$result = $kit->webhooks->process([
    'payload' => $rawRequestBody, // string, must be the raw, unparsed body
    'signature' => $_SERVER['HTTP_STRIPE_SIGNATURE'],
    'handlers' => [
        'onPaymentSucceeded' => function ($paymentIntent) { /* ... */ },
        'onInvoicePaid' => function ($invoice) { /* ... */ },
        'onSubscriptionUpdated' => function ($subscription) { /* ... */ },
        'onSubscriptionDeleted' => function ($subscription) { /* ... */ },
    ],
]);
```

`$kit->webhooks->process()` verifies the Stripe signature, deduplicates by event ID, and — unless you pass `autoSync: false` — automatically re-syncs the relevant object (payment, invoice, subscription, or payment methods) via your storage adapter before calling your handler. This means your database is always updated even if you don't implement a handler for a given event.

### `$kit->sync`

A convenience module for pulling the full current state of a customer from Stripe on demand, e.g. after a support request or a manual reconciliation job:

```php
$state = $kit->sync->everythingForCustomer($customerId, $userId);
// $state['customer'], $state['subscriptions'], $state['invoices'], $state['paymentMethods']
```

## Stripe Elements (optional, browser-side)

Serve `resources/js/stripekit-elements.js` from the package alongside your app and import it only on the pages that need it — it is plain browser JavaScript and never touches your PHP process.

```html
<script type="module">
  import { PaymentElementController } from '/vendor/ricardoneudorfer/stripekit/resources/js/stripekit-elements.js';

  const controller = await PaymentElementController.create({
    publishableKey,   // fetch this from your backend via $kit->toClientConfig()
    clientSecret,     // returned by $kit->payments->create() or $kit->checkout->create() in 'elements' mode
    appearance: { theme: 'stripe' },
  });

  controller.mount({ containerSelector: '#payment-element' });

  const result = await controller.confirmPayment({
    returnUrl: 'https://yourapp.com/billing/success',
  });

  if (!result.success) {
    console.error(result.error);
  }
</script>
```

See `examples/elements-mode-frontend.html` for a full working page, and `examples/elements-mode-backend.php` for the matching PHP backend.

## Error handling

Every module throws typed exceptions you can catch and branch on:

```php
use StripeKit\Exceptions\{ValidationError, NotFoundError, StripeOperationError, ConfigurationError};

try {
    $kit->payments->create(['amount' => 10, 'currency' => 'usd']);
} catch (ValidationError $error) {
    // amount below Stripe's minimum, bad email, etc. $error->fieldErrors may be set.
} catch (StripeOperationError $error) {
    // Stripe itself rejected the request; $error->cause holds the original Stripe error.
}
```

## Currency and amounts

All amounts are always in minor currency units (cents), matching Stripe's own convention, so there's never ambiguity between `19.99` and `1999`. Helpers are available if you need to convert:

```php
use StripeKit\Support\Money;

Money::toMinorUnits(19.99, 'usd');   // 1999
Money::toMajorUnits(1999, 'usd');    // 19.99
Money::formatMoney(1999, 'usd');     // "$19.99"
```

## Project structure

```
src/
  StripeKit.php               Main class, init() and module wiring
  Support/                    Timezone, money, validation, tokens, logging
  Exceptions/                 StripeKitError and its subclasses
  Contracts/
    StorageAdapter.php        Optional persistence hook (abstract class, override what you need)
  Modules/
    BaseModule.php
    CustomersModule.php
    PaymentMethodsModule.php
    PaymentsModule.php
    CheckoutModule.php
    SubscriptionsModule.php
    InvoicesModule.php
    CouponsModule.php
    WebhooksModule.php
    SyncModule.php
resources/js/
  stripekit-elements.js       Optional browser-side Stripe Elements helper
examples/
  basic-api-mode.php
  elements-mode-backend.php
  elements-mode-frontend.html
  webhook-handler.php
  storage-adapter-postgres.php
docs/
  TYPES.md                    Reference for every array shape used by the package
```

## Requirements

- PHP >= 8.1
- `ext-intl` and `ext-json` (both installed automatically with most PHP distributions)
- `stripe/stripe-php` ^17.0 (installed automatically via Composer)

## License

MIT
