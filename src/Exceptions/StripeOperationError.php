<?php

declare(strict_types=1);

namespace StripeKit\Exceptions;

class StripeOperationError extends StripeKitError
{
    public function __construct(string $message, mixed $cause = null)
    {
        parent::__construct($message, 'stripe_operation_error', 422, $cause);
    }

    public static function fromError(mixed $error, string $fallbackMessage): self
    {
        if ($error instanceof \Throwable) {
            return new self($error->getMessage() !== '' ? $error->getMessage() : $fallbackMessage, $error);
        }

        return new self($fallbackMessage, $error);
    }
}
