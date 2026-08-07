# StripeKit for PHP

> **Coming Soon** — StripeKit for PHP is currently in the planning and early development stage.

StripeKit is a developer-friendly Composer package for working with Stripe without requiring every project to implement the Stripe API from scratch. It is intended to provide a simple, consistent interface for payments, invoices, subscriptions, customers, products, prices, and related Stripe operations.

The goal is to make common Stripe workflows easier to integrate while still allowing applications to retain control over their own data, database structure, and business logic.

## Important Notice

This README describes the **planned functionality and possible API design**. The examples, namespaces, imports, class names, method names, initialization options, return values, and feature list are subject to change as development progresses.

The examples below are illustrative only. They do not represent a finished or currently available implementation.

## Planned Features

- Payment creation, confirmation, capture, cancellation, and status handling.
- Customer and payment method management.
- Invoice creation, item management, updating, finalizing, sending, paying, voiding, and deleting draft invoices.
- Subscription creation, updating, pausing, resuming, cancelling, and scheduled cancellation.
- Product and price management.
- Optional database integration for storing Stripe-related data.
- Support for custom application tables and custom data storage.
- Automatic table management when custom tables are disabled.
- Consistent success and failure responses for operations handled by the module.
- Configurable timezone support, using UTC by default.

## Installation

Installation details will be added when the first development version is released.

A possible future Composer configuration may look like this:

```bash
composer require stripe-kit/stripe-kit
```

The package name, vendor name, namespace, and installation process may change before the first release.

## Initialization

StripeKit will require initialization before it can be used. StripeKit does not provide its own hosted database. A database connection must therefore be supplied by the application.

A possible initialization structure:

```php
use StripeKit\StripeKit;

$stripeKit = StripeKit::init([
    'stripe_secret_key' => $_ENV['STRIPE_SECRET_KEY'],

    'database' => [
        'host' => $_ENV['DB_HOST'],
        'port' => 3306,
        'database' => $_ENV['DB_NAME'],
        'username' => $_ENV['DB_USERNAME'],
        'password' => $_ENV['DB_PASSWORD'],
    ],

    // Recommended for applications that require custom data storage.
    'use_custom_tables' => true,

    // UTC is used when this option is omitted.
    'timezone' => 'Europe/Amsterdam',
]);
```

The database configuration is expected to be required because StripeKit has no separate database of its own. The exact database driver, supported databases, option names, and initialization flow are still being evaluated.

### Custom Tables

Using `use_custom_tables => true` is expected to be the recommended option for applications that need full control over the data they store.

With custom tables enabled, the application may be responsible for providing or configuring the required tables and data mappings. This allows developers to decide which additional information should be stored, how records are related to users, and how Stripe data fits into an existing database structure.

The final custom-table API and schema requirements will be documented before the first stable release.

### Managed Tables

When `use_custom_tables` is set to `false`, StripeKit is planned to manage the required tables, inserts, updates, and related database operations automatically.

```php
$stripeKit = StripeKit::init([
    'stripe_secret_key' => $_ENV['STRIPE_SECRET_KEY'],
    'database' => [
        'host' => $_ENV['DB_HOST'],
        'port' => 3306,
        'database' => $_ENV['DB_NAME'],
        'username' => $_ENV['DB_USERNAME'],
        'password' => $_ENV['DB_PASSWORD'],
    ],
    'use_custom_tables' => false,
    'timezone' => 'UTC',
]);
```

In this mode, the module is planned to return a simple success or failure result for database setup and related tasks. This should allow an application to stop startup when initialization fails or continue when the setup succeeds.

A possible result format:

```php
[
    'success' => true,
]
```

or:

```php
[
    'success' => false,
    'error' => 'Database initialization failed',
]
```

The final response format may change.

## Planned Payment Usage

The following is an example of a possible payment workflow:

```php
$payment = $stripeKit->payments()->create([
    'amount' => 2500,
    'currency' => 'eur',
    'customer_id' => 'cus_example',
    'payment_method_id' => 'pm_example',
    'description' => 'Example order',
]);
```

Possible payment operations may include:

```php
$stripeKit->payments()->get($paymentId);
$stripeKit->payments()->update($paymentId, [
    'description' => 'Updated order',
]);
$stripeKit->payments()->capture($paymentId);
$stripeKit->payments()->cancel($paymentId);
```

The exact distinction between creating, confirming, capturing, and cancelling payments will depend on the final StripeKit API design.

## Planned Invoice Usage

Applications will be expected to provide invoice data such as customer information, invoice items, quantities, prices, tax settings, and metadata.

```php
$invoice = $stripeKit->invoices()->create([
    'customer_id' => 'cus_example',
    'collection_method' => 'send_invoice',
    'days_until_due' => 14,
    'currency' => 'eur',
    'items' => [
        [
            'description' => 'Website development',
            'quantity' => 1,
            'unit_amount' => 125000,
        ],
        [
            'description' => 'Hosting',
            'quantity' => 1,
            'unit_amount' => 2500,
        ],
    ],
    'metadata' => [
        'order_id' => 'order_123',
    ],
]);
```

Planned invoice operations may include:

```php
$stripeKit->invoices()->addItem($invoiceId, [
    'description' => 'Additional support',
    'quantity' => 2,
    'unit_amount' => 5000,
    'currency' => 'eur',
]);

$stripeKit->invoices()->update($invoiceId, [
    'description' => 'Updated invoice description',
]);

$stripeKit->invoices()->finalize($invoiceId);
$stripeKit->invoices()->send($invoiceId);
$stripeKit->invoices()->pay($invoiceId);
$stripeKit->invoices()->void($invoiceId);
$stripeKit->invoices()->delete($invoiceId); // Intended for draft invoices only.
```

Invoice actions will be restricted according to Stripe's invoice status rules. For example, draft invoices may be updated or deleted, while finalized invoices may require different actions such as payment, voiding, or marking as uncollectible.

## Planned Subscription Usage

StripeKit is intended to simplify the complete subscription lifecycle.

```php
$subscription = $stripeKit->subscriptions()->create([
    'customer_id' => 'cus_example',
    'price_id' => 'price_example',
    'quantity' => 1,
]);
```

Possible subscription operations:

```php
$stripeKit->subscriptions()->update($subscriptionId, [
    'price_id' => 'price_new_example',
    'quantity' => 2,
]);

$stripeKit->subscriptions()->pause($subscriptionId);
$stripeKit->subscriptions()->resume($subscriptionId);
$stripeKit->subscriptions()->cancel($subscriptionId);
$stripeKit->subscriptions()->cancelAtPeriodEnd($subscriptionId);
$stripeKit->subscriptions()->resumeCancellation($subscriptionId);
```

The final implementation may use different method names or expose additional options for pause behavior, proration, billing cycle changes, trial periods, and cancellation timing.

## Database Responsibility

StripeKit is planned to handle the repetitive database work required by common Stripe integrations, including records, relationships, inserts, updates, and synchronization data. However, the application must always provide database credentials because StripeKit does not include or host a database.

Applications that require maximum customization should use custom tables. Applications that prefer convenience may disable custom tables and allow StripeKit to manage the required database structure.

## Development Status

StripeKit for PHP is **Coming Soon**.

No stable API, production-ready release, or backwards-compatibility guarantee is available yet. Follow this repository for development updates, documentation changes, and release announcements.

## License

The license and contribution guidelines will be added before the first public release.