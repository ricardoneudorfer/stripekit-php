<?php

declare(strict_types=1);

namespace StripeKit\Exceptions;

class WebhookVerificationError extends StripeKitError
{
    public function __construct(string $message, mixed $cause = null)
    {
        parent::__construct($message, 'webhook_verification_error', 400, $cause);
    }
}
