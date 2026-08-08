<?php

declare(strict_types=1);

namespace StripeKit\Exceptions;

use Exception;
use Throwable;

class StripeKitError extends Exception
{
    public readonly string $errorCode;
    public readonly int $statusCode;
    public readonly mixed $cause;

    public function __construct(string $message, string $errorCode = 'stripekit_error', int $statusCode = 400, mixed $cause = null)
    {
        parent::__construct($message, 0, $cause instanceof Throwable ? $cause : null);
        $this->errorCode = $errorCode;
        $this->statusCode = $statusCode;
        $this->cause = $cause;
    }
}
