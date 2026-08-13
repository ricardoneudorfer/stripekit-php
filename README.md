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

Full documentation is available at:
https://docs.explorericardo.com/packagist-packages/stripekit/installation