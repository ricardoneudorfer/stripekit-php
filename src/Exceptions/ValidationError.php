<?php

declare(strict_types=1);

namespace StripeKit\Exceptions;

class ValidationError extends StripeKitError
{
    /** @var array<string, string>|null */
    public readonly ?array $fieldErrors;

    /**
     * @param array<string, string>|null $fieldErrors
     */
    public function __construct(string $message, ?array $fieldErrors = null)
    {
        parent::__construct($message, 'validation_error', 422);
        $this->fieldErrors = $fieldErrors;
    }
}
