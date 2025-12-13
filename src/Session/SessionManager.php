<?php

declare(strict_types=1);

namespace Toporia\Framework\Session;

use Toporia\Framework\Session\Contracts\SessionStoreInterface;
use Toporia\Framework\Session\Drivers\{FileSessionDriver, DatabaseSessionDriver, RedisSessionDriver, CookieSessionDriver};
use Toporia\Framework\Database\Contracts\ConnectionInterface;
use Toporia\Framework\Http\CookieJar;
use Redis;

/**
 * Class SessionManager
 *
 * Manages session stores with multiple driver support.
 * Provides unified interface for session management across different storage backends.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Session
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class SessionManager
{
    /**
     * @var array<string, Store> Resolved session stores
     */
    private array $stores = [];

    /**
     * @param array $config Session configuration
     * @param ConnectionInterface|null $connection Database connection (for database driver)
     * @param Redis|null $redis Redis instance (for redis driver)
     * @param CookieJar|null $cookieJar Cookie jar (for cookie driver)
     */
    public function __construct(
        private array $config,
        private ?ConnectionInterface $connection = null,
        private ?Redis $redis = null,
        private ?CookieJar $cookieJar = null
    ) {}

    /**
     * Get a session store instance.
     *
     * Performance: O(1) after first call (cached)
     *
     * @param string|null $name Store name (null = default)
     * @return Store Session store instance
     */
    public function store(?string $name = null): Store
    {
        $name = $name ?? $this->getDefaultStore();

        if (!isset($this->stores[$name])) {
            $this->stores[$name] = $this->createStore($name);
        }

        return $this->stores[$name];
    }

    /**
     * Get the default session store.
     *
     * @return Store
     */
    public function driver(?string $driver = null): Store
    {
        $driver = $driver ?? $this->getDefaultDriver();
        return $this->store($driver);
    }

    /**
     * Create a session store instance.
     *
     * @param string $name Store name
     * @return Store
     */
    private function createStore(string $name): Store
    {
        $config = $this->getStoreConfig($name);
        $driver = $this->createDriver($config);
        $storeName = $config['name'] ?? 'PHPSESSID';

        return new Store($driver, $storeName);
    }

    /**
     * Create a session driver instance.
     *
     * @param array $config Driver configuration
     * @return SessionStoreInterface
     */
    private function createDriver(array $config): SessionStoreInterface
    {
        $driver = $config['driver'] ?? 'file';
        // Cast to int to handle string values from env() or config
        $lifetime = (int) ($config['lifetime'] ?? 7200);
        $name = $config['name'] ?? 'PHPSESSID';

        return match ($driver) {
            'file' => new FileSessionDriver(
                $config['path'] ?? sys_get_temp_dir() . '/sessions',
                $name,
                $lifetime
            ),
            'database' => new DatabaseSessionDriver(
                $this->connection ?? throw new \RuntimeException('Database connection required for database session driver'),
                $config['table'] ?? 'sessions',
                $name,
                $lifetime
            ),
            'redis' => new RedisSessionDriver(
                $this->redis ?? throw new \RuntimeException('Redis instance required for redis session driver'),
                $name,
                $lifetime,
                $config['prefix'] ?? 'session:'
            ),
            'cookie' => new CookieSessionDriver(
                $this->cookieJar ?? throw new \RuntimeException('CookieJar required for cookie session driver'),
                $name,
                $lifetime
            ),
            default => throw new \InvalidArgumentException("Unsupported session driver: {$driver}"),
        };
    }

    /**
     * Get store configuration.
     *
     * @param string $name Store name
     * @return array
     */
    private function getStoreConfig(string $name): array
    {
        $stores = $this->config['stores'] ?? [];
        if (isset($stores[$name])) {
            return $stores[$name];
        }

        // Fallback to default store config
        return [
            'driver' => $this->getDefaultDriver(),
            'lifetime' => $this->config['lifetime'] ?? 7200,
            'name' => $this->config['name'] ?? 'PHPSESSID',
        ];
    }

    /**
     * Get default store name.
     *
     * @return string
     */
    private function getDefaultStore(): string
    {
        return $this->config['default'] ?? 'default';
    }

    /**
     * Get default driver name.
     *
     * @return string
     */
    private function getDefaultDriver(): string
    {
        $defaultStore = $this->getDefaultStore();
        $stores = $this->config['stores'] ?? [];
        return $stores[$defaultStore]['driver'] ?? 'file';
    }
}

