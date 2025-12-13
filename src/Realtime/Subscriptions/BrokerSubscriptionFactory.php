<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Subscriptions;

use Toporia\Framework\Realtime\Contracts\BrokerSubscriptionStrategyInterface;

/**
 * Class BrokerSubscriptionFactory
 *
 * Factory for creating broker subscription strategies.
 * Supports registration of custom strategies for extensibility.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Realtime\Subscriptions
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class BrokerSubscriptionFactory
{
    /** @var array<string, BrokerSubscriptionStrategyInterface> */
    private array $strategies = [];

    /**
     * Create factory with default strategies.
     *
     * @param array $config Configuration for strategies
     * @return self
     */
    public static function createWithDefaults(array $config = []): self
    {
        $factory = new self();

        // Register default strategies
        $factory->register(new RedisBrokerSubscriptionStrategy($config['redis'] ?? []));
        $factory->register(new RabbitMqBrokerSubscriptionStrategy($config['rabbitmq'] ?? []));
        $factory->register(new KafkaBrokerSubscriptionStrategy($config['kafka'] ?? []));

        return $factory;
    }

    /**
     * Register a broker subscription strategy.
     *
     * @param BrokerSubscriptionStrategyInterface $strategy
     * @return self
     */
    public function register(BrokerSubscriptionStrategyInterface $strategy): self
    {
        $this->strategies[$strategy->getName()] = $strategy;
        return $this;
    }

    /**
     * Create/get a strategy for the given broker name.
     *
     * @param string $brokerName
     * @return BrokerSubscriptionStrategyInterface|null
     */
    public function create(string $brokerName): ?BrokerSubscriptionStrategyInterface
    {
        // Direct lookup by name
        if (isset($this->strategies[$brokerName])) {
            return $this->strategies[$brokerName];
        }

        // Fallback: check supports() method
        foreach ($this->strategies as $strategy) {
            if ($strategy->supports($brokerName)) {
                return $strategy;
            }
        }

        return null;
    }

    /**
     * Check if a strategy exists for the given broker name.
     *
     * @param string $brokerName
     * @return bool
     */
    public function has(string $brokerName): bool
    {
        return $this->create($brokerName) !== null;
    }

    /**
     * Get all registered strategy names.
     *
     * @return array<string>
     */
    public function getAvailableStrategies(): array
    {
        return array_keys($this->strategies);
    }
}
