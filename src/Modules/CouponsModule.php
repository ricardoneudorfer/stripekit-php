<?php

declare(strict_types=1);

namespace StripeKit\Modules;

use StripeKit\Exceptions\StripeOperationError;
use StripeKit\Exceptions\ValidationError;
use StripeKit\Support\Money;
use StripeKit\Support\Timezone;
use Throwable;

class CouponsModule extends BaseModule
{
    /**
     * @param array<string, mixed> $record
     */
    private function describe(array $record): string
    {
        $description = $record['discountType'] === 'percent'
            ? sprintf('%s%% off', $record['discountValue'])
            : sprintf('%s off', Money::formatMoney((int) round($record['discountValue'] * 100), $record['currency'] ?? $this->config['currency']));

        if ($record['duration'] === 'forever') {
            $description .= ' forever';
        } elseif ($record['duration'] === 'repeating') {
            $description .= sprintf(' for %s month(s)', $record['durationInMonths']);
        } else {
            $description .= ' (first payment)';
        }

        return $description;
    }

    /**
     * @param array{code: string, name?: string, discountType: string, discountValue: float, currency?: string, duration?: string, durationInMonths?: int, maxRedemptions?: int, expiresAt?: string} $input
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        $code = strtoupper(trim($input['code']));
        if (!$code || $input['discountValue'] <= 0) {
            throw new ValidationError('A coupon `code` and a positive `discountValue` are required.');
        }

        try {
            $stripeParams = [
                'duration' => $input['duration'] ?? 'once',
                'name' => $input['name'] ?? $code,
            ];

            if ($input['discountType'] === 'percent') {
                $stripeParams['percent_off'] = $input['discountValue'];
            } else {
                $stripeParams['amount_off'] = (int) round($input['discountValue'] * 100);
                $stripeParams['currency'] = strtolower($input['currency'] ?? $this->config['currency']);
            }

            if ($stripeParams['duration'] === 'repeating') {
                $stripeParams['duration_in_months'] = $input['durationInMonths'] ?? 1;
            }
            if (!empty($input['maxRedemptions'])) {
                $stripeParams['max_redemptions'] = $input['maxRedemptions'];
            }

            $coupon = $this->stripe->coupons->create($stripeParams);
            $promotionCode = $this->stripe->promotionCodes->create([
                'coupon' => $coupon->id,
                'code' => $code,
                'expires_at' => !empty($input['expiresAt']) ? (int) floor(strtotime($input['expiresAt']) ) : null,
            ]);

            $record = [
                'code' => $code,
                'stripeCouponId' => $coupon->id,
                'stripePromotionCodeId' => $promotionCode->id,
                'name' => $input['name'] ?? $code,
                'discountType' => $input['discountType'],
                'discountValue' => $input['discountValue'],
                'currency' => $coupon->currency ?? null,
                'duration' => $coupon->duration,
                'durationInMonths' => $coupon->duration_in_months ?? null,
                'maxRedemptions' => $coupon->max_redemptions ?? null,
                'timesRedeemed' => $coupon->times_redeemed ?? 0,
                'expiresAtUtc' => Timezone::unixToUtcIso($promotionCode->expires_at),
                'active' => true,
            ];

            $full = array_merge($record, ['description' => $this->describe($record)]);
            $this->storage?->saveCoupon($full);
            return $full;
        } catch (Throwable $error) {
            throw StripeOperationError::fromError($error, sprintf('Could not create coupon "%s".', $code));
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function validate(string $code): ?array
    {
        $normalized = strtoupper(trim($code));
        if (!$normalized) {
            return null;
        }

        try {
            $promotionCodes = $this->stripe->promotionCodes->all([
                'code' => $normalized,
                'limit' => 1,
                'expand' => ['data.coupon'],
            ]);

            $promo = $promotionCodes->data[0] ?? null;
            if (!$promo || $promo->active === false) {
                return null;
            }

            $coupon = $promo->coupon;
            if (!$coupon) {
                return null;
            }

            $record = [
                'code' => $normalized,
                'stripeCouponId' => $coupon->id,
                'stripePromotionCodeId' => $promo->id,
                'name' => $coupon->name ?? $normalized,
                'discountType' => $coupon->percent_off !== null ? 'percent' : 'amount',
                'discountValue' => $coupon->percent_off ?? (int) round(($coupon->amount_off ?? 0) / 100),
                'currency' => $coupon->currency ?? null,
                'duration' => $coupon->duration,
                'durationInMonths' => $coupon->duration_in_months ?? null,
                'maxRedemptions' => $promo->max_redemptions ?? null,
                'timesRedeemed' => $promo->times_redeemed ?? 0,
                'expiresAtUtc' => Timezone::unixToUtcIso($promo->expires_at),
                'active' => true,
            ];

            $full = array_merge($record, ['description' => $this->describe($record)]);
            $this->storage?->saveCoupon($full);
            return $full;
        } catch (Throwable $error) {
            $this->logger->warn(sprintf('Coupon validation failed for code "%s":', $normalized), $error);
            return null;
        }
    }

    public function applyToSubscription(string $subscriptionId, string $stripePromotionCodeId): bool
    {
        try {
            $this->stripe->subscriptions->update($subscriptionId, ['promotion_code' => $stripePromotionCodeId]);
            return true;
        } catch (Throwable $error) {
            throw StripeOperationError::fromError($error, sprintf('Could not apply coupon to subscription "%s".', $subscriptionId));
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $limit = 50): array
    {
        try {
            $result = $this->stripe->promotionCodes->all(['limit' => $limit, 'expand' => ['data.coupon']]);

            $records = [];
            foreach ($result->data as $promo) {
                if (!$promo->coupon) {
                    continue;
                }
                $coupon = $promo->coupon;
                $record = [
                    'code' => $promo->code,
                    'stripeCouponId' => $coupon->id,
                    'stripePromotionCodeId' => $promo->id,
                    'name' => $coupon->name ?? $promo->code,
                    'discountType' => $coupon->percent_off !== null ? 'percent' : 'amount',
                    'discountValue' => $coupon->percent_off ?? (int) round(($coupon->amount_off ?? 0) / 100),
                    'currency' => $coupon->currency ?? null,
                    'duration' => $coupon->duration,
                    'durationInMonths' => $coupon->duration_in_months ?? null,
                    'maxRedemptions' => $promo->max_redemptions ?? null,
                    'timesRedeemed' => $promo->times_redeemed ?? 0,
                    'expiresAtUtc' => Timezone::unixToUtcIso($promo->expires_at),
                    'active' => $promo->active,
                ];
                $records[] = array_merge($record, ['description' => $this->describe($record)]);
            }
            return $records;
        } catch (Throwable $error) {
            throw StripeOperationError::fromError($error, 'Could not list coupons.');
        }
    }

    /**
     * @return array{ok: true}
     */
    public function deactivate(string $stripePromotionCodeId): array
    {
        try {
            $this->stripe->promotionCodes->update($stripePromotionCodeId, ['active' => false]);
            return ['ok' => true];
        } catch (Throwable $error) {
            throw StripeOperationError::fromError($error, sprintf('Could not deactivate promotion code "%s".', $stripePromotionCodeId));
        }
    }
}
