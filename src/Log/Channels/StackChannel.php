<?php

declare(strict_types=1);

namespace Toporia\Framework\Log\Channels;

use Toporia\Framework\Log\Contracts\ChannelInterface;

/**
 * Class StackChannel
 *
 * Stack Channel - Multiple channels aggregator
 *
 * Writes logs to multiple channels simultaneously.
 * Useful for logging to both file and syslog, or daily + single file.
 *
 * Example:
 * ```php
 * $stack = new StackChannel([
 *     new DailyFileChannel('/var/log/app'),
 *     new SyslogChannel('myapp'),
 * ]);
 * ```
 *
 * Performance: O(N) where N = number of channels
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Log\Channels
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class StackChannel implements ChannelInterface
{
    /** @var array<ChannelInterface> */
    private array $channels;

    /**
     * @param array<ChannelInterface> $channels
     */
    public function __construct(array $channels)
    {
        $this->channels = $channels;
    }

    public function write(string $level, string $message, array $context = []): void
    {
        foreach ($this->channels as $channel) {
            $channel->write($level, $message, $context);
        }
    }

    /**
     * Add a channel to the stack.
     *
     * @param ChannelInterface $channel
     * @return void
     */
    public function push(ChannelInterface $channel): void
    {
        $this->channels[] = $channel;
    }
}
