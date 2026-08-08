<?php

declare(strict_types=1);

namespace StripeKit\Exceptions;

class NotFoundError extends StripeKitError
{
    public function __construct(string $message)
    {
        parent::__construct($message, 'not_found', 404);
    }
}
