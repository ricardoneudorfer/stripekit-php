# StripeKit for PHP — Type Reference

PHP has no compile-time interfaces for plain data, so StripeKit represents every
input and result as a documented associative array (the same approach the
Stripe PHP SDK itself uses internally). This file is the PHP equivalent of the
original `src/types/*.ts` definitions and lists the shape of every array you
will send to, or receive from, StripeKit.

## Config (`StripeKit::init()`)

```
array{
    secretKey: string,
    publishableKey?: string|null,
    webhookSecret?: string|null,
    mode: 'api'|'elements'|'both',
    timezone?: string,               // IANA timezone, defaults to "UTC"
    apiVersion?: string|null,
    appInfo?: array{name: string, version?: string, url?: string}|null,
    currency?: string,               // defaults to "usd"
    successUrl?: string|null,
    cancelUrl?: string|null,
    storage?: \StripeKit\Contracts\StorageAdapter|null,
    debug?: bool,
    maxNetworkRetries?: int,         // defaults to 2
    timeout?: int|null,              // milliseconds
}
```

## Customers

**CreateCustomerInput**
```
array{
    email: string,
    name?: string,
    phone?: string,
    description?: string,
    address?: array{line1?: string, line2?: string, city?: string, state?: string, postalCode?: string, country?: string},
    taxId?: string,
    metadata?: array<string, string>,
    userId?: string|int,
}
```

**KitCustomerRecord** (returned by `create`, `retrieve`, `update`, `sync`)
```
array{
    id: string,
    userId: string|int|null,
    email: string|null,
    name: string|null,
    phone: string|null,
    description: string|null,
    address: array{...}|null,
    defaultPaymentMethodId: string|null,
    metadata: array<string, string>,
    createdAtUtc: string|null,
    deleted: bool,
}
```

## Payment methods

**KitPaymentMethodRecord**
```
array{
    id: string,
    userId: string|int|null,
    customerId: string|null,
    type: string,
    brand: string|null,
    last4: string|null,
    expMonth: int|null,
    expYear: int|null,
    isDefault: bool,
    createdAtUtc: string|null,
}
```

## Payments

**CreatePaymentInput**
```
array{
    amount: int,                 // minor currency units
    currency?: string,
    customerId?: string,
    email?: string,
    userId?: string|int,
    description?: string,
    metadata?: array<string, string>,
    paymentMethodId?: string,
    receiptEmail?: string,
    offSession?: bool,
    confirm?: bool,
    captureMethod?: 'automatic'|'manual',
    statementDescriptor?: string,
    applicationFeeAmount?: int,
    returnUrl?: string,
    mode?: 'api'|'elements',
}
```

**PaymentResult**
```
array{
    id: string,
    mode: 'api'|'elements',
    status: string,
    amount: int,
    currency: string,
    clientSecret: string|null,
    hostedUrl: string|null,
    requiresAction: bool,
}
```

## Checkout

**CreateCheckoutInput**
```
array{
    mode: 'payment'|'subscription',
    amount?: int,
    currency?: string,
    priceId?: string,
    email?: string,
    userId?: string|int,
    description?: string,
    metadata?: array<string, string>,
    customFields?: list<array{key: string, label: string, required?: bool, pattern?: string, patternHint?: string}>,
    fieldValues?: array<string, string>,
    successUrl?: string,
    cancelUrl?: string,
    couponCode?: string,
    flowOverride?: 'api'|'elements',
}
```

**CheckoutResult**
```
array{
    id: string,
    mode: 'payment'|'subscription',
    flow: 'api'|'elements',
    clientSecret: string|null,
    hostedUrl: string|null,
    paymentIntentId: string|null,
    subscriptionId: string|null,
    amount: int,
    currency: string,
    requiresFields: bool,
    fieldSchema: list<array<string, mixed>>,
    expiresAtUtc: string,
}
```

## Subscriptions

**CreateSubscriptionInput**
```
array{
    customerId: string,
    priceId: string,
    userId?: string|int,
    quantity?: int,
    trialPeriodDays?: int,
    collectionMethod?: 'charge_automatically'|'send_invoice',
    daysUntilDue?: int,
    defaultPaymentMethodId?: string,
    promotionCode?: string,
    metadata?: array<string, string>,
    fieldValues?: array<string, string>,
}
```

**KitSubscriptionRecord**
```
array{
    id: string,
    userId: string|int|null,
    customerId: string|null,
    priceId: string|null,
    status: string,
    currentPeriodStartUtc: string|null,
    currentPeriodEndUtc: string|null,
    currentPeriodStartLocal: string|null,
    currentPeriodEndLocal: string|null,
    cancelAtPeriodEnd: bool,
    canceledAtUtc: string|null,
    trialEndUtc: string|null,
    collectionMethod: string|null,
    metadata: array<string, string>,
    fieldValues: array<string, string>,
    createdAtUtc: string,
}
```

## Invoices

**KitInvoiceRecord**
```
array{
    id: string,
    userId: string|int|null,
    customerId: string|null,
    subscriptionId: string|null,
    paymentIntentId: string|null,
    number: string|null,
    amountDue: int,
    amountPaid: int,
    subtotal: int,
    taxAmount: int,
    taxRate: mixed|null,
    currency: string,
    status: string,
    description: string|null,
    hostedInvoiceUrl: string|null,
    lineItems: list<array{description: string, amount: int, currency: string, quantity: int, taxAmount: int}>,
    dueAtUtc: string|null,
    paidAtUtc: string|null,
    issuedAtUtc: string,
    dueAtLocal: string|null,
    paidAtLocal: string|null,
    issuedAtLocal: string,
}
```

## Coupons

**CreateCouponInput**
```
array{
    code: string,
    name?: string,
    discountType: 'percent'|'amount',
    discountValue: float,
    currency?: string,
    duration?: 'once'|'repeating'|'forever',
    durationInMonths?: int,
    maxRedemptions?: int,
    expiresAt?: string,           // any strtotime()-parsable date
}
```

**KitCouponRecord**
```
array{
    code: string,
    stripeCouponId: string,
    stripePromotionCodeId: string,
    name: string,
    discountType: 'percent'|'amount',
    discountValue: float,
    currency: string|null,
    duration: string,
    durationInMonths: int|null,
    maxRedemptions: int|null,
    timesRedeemed: int,
    expiresAtUtc: string|null,
    active: bool,
    description: string,
}
```

## Webhooks

`$kit->webhooks->process()` accepts:
```
array{
    payload: string,
    signature: string,
    handlers?: array<string, callable>,
    autoSync?: bool,
}
```

Available handler keys: `onPaymentSucceeded`, `onPaymentFailed`, `onInvoiceCreated`,
`onInvoiceUpdated`, `onInvoicePaid`, `onInvoicePaymentFailed`, `onInvoiceVoided`,
`onInvoiceDeleted`, `onSubscriptionCreated`, `onSubscriptionUpdated`,
`onSubscriptionDeleted`, `onSetupIntentSucceeded`, `onCheckoutSessionCompleted`,
`onUnhandledEvent`. Each handler receives `(\Stripe\StripeObject $object, array{event: \Stripe\Event} $context)`.

## Storage adapter

See `src/Contracts/StorageAdapter.php`. It is an abstract class rather than a PHP
`interface` so that, exactly like the optional methods on the original TypeScript
`StorageAdapter` interface, you only need to override the methods your
application actually uses — everything else safely no-ops.
