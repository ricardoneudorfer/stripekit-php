<?php

declare(strict_types=1);

namespace StripeKit\Support;

class Logger
{
    private readonly bool $enabled;

    public function __construct(bool $enabled)
    {
        $this->enabled = $enabled;
    }

    public function debug(mixed ...$args): void
    {
        if ($this->enabled) {
            error_log('[StripeKit] ' . $this->stringify($args));
        }
    }

    public function info(mixed ...$args): void
    {
        if ($this->enabled) {
            error_log('[StripeKit] ' . $this->stringify($args));
        }
    }

    public function warn(mixed ...$args): void
    {
        error_log('[StripeKit] ' . $this->stringify($args));
    }

    public function error(mixed ...$args): void
    {
        error_log('[StripeKit] ' . $this->stringify($args));
    }

    private function stringify(array $args): string
    {
        return implode(' ', array_map(
            static function (mixed $arg): string {
                if (is_string($arg)) {
                    return $arg;
                }
                if ($arg instanceof \Throwable) {
                    return $arg->getMessage();
                }
                return (string) json_encode($arg, JSON_UNESCAPED_SLASHES);
            },
            $args,
        ));
    }
}
