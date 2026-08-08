<?php

declare(strict_types=1);

namespace StripeKit\Exceptions;

class ConfigurationError extends StripeKitError
{
    public function __construct(string $message)
    {
        parent::__construct($message, 'configuration_error', 500);
    }
}
