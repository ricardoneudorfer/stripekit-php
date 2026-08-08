<?php

declare(strict_types=1);

namespace StripeKit\Modules;

use Stripe\Invoice;
use StripeKit\Exceptions\StripeOperationError;
use StripeKit\Exceptions\ValidationError;
use StripeKit\Support\Timezone;
use Throwable;

class InvoicesModule extends BaseModule
{
    /**
     * @return array<string, mixed>
     */
    private function mapInvoice(Invoice $inv, string|int|null $userId = null): array
    {
        $timezone = $this->config['timezone'];

        $lineItems = array_map(function ($line) use ($inv) {
            $taxAmount = 0;
            foreach ($line->tax_amounts ?? [] as $tax) {
                $taxAmount += $tax->amount ?? 0;
            }

            return [
                'description' => $line->description ?? 'Invoice item',
                'amount' => $line->amount ?? 0,
                'currency' => $line->currency ?? $inv->currency ?? $this->config['currency'],
                'quantity' => $line->quantity ?? 1,
                'taxAmount' => $taxAmount,
            ];
        }, $inv->lines->data ?? []);

        $subtotal = $inv->subtotal ?? array_sum(array_column($lineItems, 'amount'));
        $taxAmount = $inv->tax ?? array_sum(array_column($lineItems, 'taxAmount'));

        $dueAt = Timezone::unixToTimezone($inv->due_date, $timezone);
        $paidAt = Timezone::unixToTimezone($inv->status_transitions?->paid_at, $timezone);
        $issuedAt = Timezone::unixToTimezone($inv->created, $timezone);

        $customer = $inv->customer;
        $subscription = $inv->subscription;
        $paymentIntent = $inv->payment_intent;

        return [
            'id' => $inv->id ?? '',
            'userId' => $userId,
            'customerId' => is_string($customer) ? $customer : ($customer->id ?? null),
            'subscriptionId' => is_string($subscription) ? $subscription : ($subscription->id ?? null),
            'paymentIntentId' => is_string($paymentIntent) ? $paymentIntent : ($paymentIntent->id ?? null),
            'number' => $inv->number,
            'amountDue' => $inv->amount_due,
            'amountPaid' => $inv->amount_paid,
            'subtotal' => $subtotal,
            'taxAmount' => $taxAmount,
            'taxRate' => null,
            'currency' => $inv->currency,
            'status' => $inv->status ?? 'draft',
            'description' => $inv->description ?? ($lineItems[0]['description'] ?? null),
            'hostedInvoiceUrl' => $inv->hosted_invoice_url ?? null,
            'lineItems' => $lineItems,
            'dueAtUtc' => $dueAt['utc'],
            'paidAtUtc' => $paidAt['utc'],
            'issuedAtUtc' => $issuedAt['utc'] ?? Timezone::nowUtcIso(),
            'dueAtLocal' => $dueAt['local'],
            'paidAtLocal' => $paidAt['local'],
            'issuedAtLocal' => $issuedAt['local'] ?? Timezone::nowUtcIso(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function retrieve(string $invoiceId, string|int|null $userId = null): array
    {
        try {
            $inv = $this->stripe->invoices->retrieve($invoiceId, [
                'expand' => ['lines.data', 'payment_intent', 'customer'],
            ]);
            return $this->mapInvoice($inv, $userId);
        } catch (Throwable $error) {
            throw StripeOperationError::fromError($error, sprintf('Could not retrieve invoice "%s".', $invoiceId));
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listByCustomer(string $customerId, ?string $status = null): array
    {
        try {
            $result = $this->stripe->invoices->all(['customer' => $customerId, 'status' => $status, 'limit' => 100, 'expand' => ['data.lines.data']]);
            return array_map(fn (Invoice $inv) => $this->mapInvoice($inv), $result->data);
        } catch (Throwable $error) {
            throw StripeOperationError::fromError($error, sprintf('Could not list invoices for customer "%s".', $customerId));
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listBySubscription(string $subscriptionId): array
    {
        try {
            $result = $this->stripe->invoices->all(['subscription' => $subscriptionId, 'limit' => 100, 'expand' => ['data.lines.data']]);
            return array_map(fn (Invoice $inv) => $this->mapInvoice($inv), $result->data);
        } catch (Throwable $error) {
            throw StripeOperationError::fromError($error, sprintf('Could not list invoices for subscription "%s".', $subscriptionId));
        }
    }

    /**
     * @param array{invoiceId: string, customerId: string, paymentMethodId: string, returnUrl?: string} $input
     * @return array{id: string, mode: string, status: string, amount: int, currency: string, clientSecret: string|null, hostedUrl: string|null, requiresAction: bool}
     */
    public function payWithSavedMethod(array $input): array
    {
        try {
            $invoice = $this->stripe->invoices->retrieve($input['invoiceId']);
            if ($invoice->status === 'paid') {
                throw new ValidationError(sprintf('Invoice "%s" is already paid.', $input['invoiceId']));
            }
            if ($invoice->status !== 'open') {
                throw new ValidationError(sprintf('Invoice "%s" is not open (status: %s).', $input['invoiceId'], $invoice->status));
            }

            $paid = $this->stripe->invoices->pay($input['invoiceId'], [
                'payment_method' => $input['paymentMethodId'],
                'off_session' => true,
            ]);

            $paymentIntentRef = $paid->payment_intent;
            $paymentIntentId = is_string($paymentIntentRef) ? $paymentIntentRef : ($paymentIntentRef->id ?? null);

            $status = 'succeeded';
            $clientSecret = null;
            $requiresAction = false;

            if ($paymentIntentId) {
                $pi = $this->stripe->paymentIntents->retrieve($paymentIntentId);
                $status = $pi->status;
                $clientSecret = $pi->client_secret;
                $requiresAction = $pi->status === 'requires_action';
            }

            return [
                'id' => $paymentIntentId ?? ($paid->id ?? $input['invoiceId']),
                'mode' => 'elements',
                'status' => $status,
                'amount' => $paid->amount_due,
                'currency' => $paid->currency,
                'clientSecret' => $clientSecret,
                'hostedUrl' => null,
                'requiresAction' => $requiresAction,
            ];
        } catch (ValidationError $error) {
            throw $error;
        } catch (Throwable $error) {
            throw StripeOperationError::fromError($error, sprintf('Could not pay invoice "%s" with the saved payment method.', $input['invoiceId']));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function voidInvoice(string $invoiceId): array
    {
        try {
            $inv = $this->stripe->invoices->voidInvoice($invoiceId);
            return $this->mapInvoice($inv);
        } catch (Throwable $error) {
            throw StripeOperationError::fromError($error, sprintf('Could not void invoice "%s".', $invoiceId));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function finalize(string $invoiceId): array
    {
        try {
            $inv = $this->stripe->invoices->finalizeInvoice($invoiceId);
            return $this->mapInvoice($inv);
        } catch (Throwable $error) {
            throw StripeOperationError::fromError($error, sprintf('Could not finalize invoice "%s".', $invoiceId));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function sync(string $invoiceId, string|int|null $userId = null): array
    {
        $record = $this->retrieve($invoiceId, $userId);
        $this->storage?->saveInvoice($record);
        return $record;
    }
}
