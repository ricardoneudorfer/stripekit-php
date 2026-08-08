<?php

declare(strict_types=1);

namespace StripeKit\Modules;

use Stripe\StripeClient;
use StripeKit\Contracts\StorageAdapter;
use StripeKit\Support\Logger;

abstract class BaseModule
{
    protected readonly StripeClient $stripe;

    /** @var array<string, mixed> */
    protected readonly array $config;

    protected readonly ?StorageAdapter $storage;

    protected readonly Logger $logger;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(StripeClient $stripe, array $config, Logger $logger)
    {
        $this->stripe = $stripe;
        $this->config = $config;
        $this->storage = $config['storage'] ?? null;
        $this->logger = $logger;
    }
}
