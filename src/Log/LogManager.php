<?php

declare(strict_types=1);

namespace Toporia\Framework\Log;

use Toporia\Framework\Log\Contracts\{ChannelInterface, LoggerInterface};
use Toporia\Framework\Log\Channels\{DailyFileChannel, FileChannel, StackChannel, StderrChannel, SyslogChannel};

/**
 * Class LogManager
 *
 * Log Manager - Multi-channel logger factory
 *
 * Manages multiple named log channels with lazy loading.
 * Multi-channel logger with explicit configuration.
 *
 * Features:
 * - Multiple named channels
 * - Lazy channel creation
 * - Default channel support
 * - Channel caching for performance
 *
 * Example:
 * ```php
 * $manager = new LogManager([
 *     'default' => 'daily',
 *     'channels' => [
 *         'daily' => [
 *             'driver' => 'daily',
 *             'path' => '/var/log/app',
 *             'days' => 14,
 *         ],
 *         'single' => [
 *             'driver' => 'single',
 *             'path' => '/var/log/app/app.log',
 *         ],
 *     ],
 * ]);
 *
 * $manager->channel('daily')->error('Error occurred');
 * ```
 *
 * Performance:
 * - O(1) channel lookup (cached)
 * - O(1) lazy channel creation
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Log
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class LogManager
{
    private array $config;
    private array $channels = [];

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Get a log channel instance.
     *
     * @param string|null $name Channel name (null = default)
     * @return LoggerInterface
     */
    public function channel(?string $name = null): LoggerInterface
    {
        $name = $name ?? $this->config['default'] ?? 'daily';

        // Return cached channel if exists
        if (isset($this->channels[$name])) {
            return $this->channels[$name];
        }

        // Create and cache channel
        $this->channels[$name] = $this->createChannel($name);

        return $this->channels[$name];
    }

    /**
     * Create a channel instance from configuration.
     *
     * @param string $name
     * @return LoggerInterface
     */
    private function createChannel(string $name): LoggerInterface
    {
        if (!isset($this->config['channels'][$name])) {
            throw new \InvalidArgumentException("Log channel [{$name}] is not defined.");
        }

        $config = $this->config['channels'][$name];
        $driver = $config['driver'] ?? 'daily';

        $channelImpl = match ($driver) {
            'single' => $this->createFileChannel($config),
            'daily' => $this->createDailyChannel($config),
            'stack' => $this->createStackChannel($config),
            'syslog' => $this->createSyslogChannel($config),
            'stderr' => $this->createStderrChannel($config),
            default => throw new \InvalidArgumentException("Unsupported log driver: {$driver}"),
        };

        return new Logger($channelImpl);
    }

    /**
     * Create single file channel.
     *
     * @param array $config
     * @return ChannelInterface
     */
    private function createFileChannel(array $config): ChannelInterface
    {
        return new FileChannel(
            $config['path'],
            $config['date_format'] ?? 'Y-m-d H:i:s'
        );
    }

    /**
     * Create daily rotating file channel.
     *
     * @param array $config
     * @return ChannelInterface
     */
    private function createDailyChannel(array $config): ChannelInterface
    {
        return new DailyFileChannel(
            $config['path'],
            $config['date_format'] ?? 'Y-m-d H:i:s',
            $config['days'] ?? null
        );
    }

    /**
     * Create stack (multiple channels) channel.
     *
     * @param array $config
     * @return ChannelInterface
     */
    private function createStackChannel(array $config): ChannelInterface
    {
        $channels = [];

        foreach ($config['channels'] as $channelName) {
            // Create the underlying channel for stacking
            $channelConfig = $this->config['channels'][$channelName];
            $driver = $channelConfig['driver'] ?? 'daily';

            $channels[] = match ($driver) {
                'single' => $this->createFileChannel($channelConfig),
                'daily' => $this->createDailyChannel($channelConfig),
                'syslog' => $this->createSyslogChannel($channelConfig),
                'stderr' => $this->createStderrChannel($channelConfig),
                default => throw new \InvalidArgumentException("Cannot stack channel type: {$driver}"),
            };
        }

        return new StackChannel($channels);
    }

    /**
     * Create syslog channel.
     *
     * @param array $config
     * @return ChannelInterface
     */
    private function createSyslogChannel(array $config): ChannelInterface
    {
        return new SyslogChannel(
            $config['ident'] ?? 'php',
            $config['facility'] ?? LOG_USER
        );
    }

    /**
     * Create stderr channel.
     *
     * @param array $config
     * @return ChannelInterface
     */
    private function createStderrChannel(array $config): ChannelInterface
    {
        return new StderrChannel(
            $config['date_format'] ?? 'Y-m-d H:i:s'
        );
    }

    /**
     * Proxy PSR-3 methods to default channel.
     */
    public function emergency(string $message, array $context = []): void
    {
        $this->channel()->emergency($message, $context);
    }

    public function alert(string $message, array $context = []): void
    {
        $this->channel()->alert($message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->channel()->critical($message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->channel()->error($message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->channel()->warning($message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->channel()->notice($message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->channel()->info($message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->channel()->debug($message, $context);
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $this->channel()->log($level, $message, $context);
    }
}
