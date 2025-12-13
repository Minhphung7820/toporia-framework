<?php

declare(strict_types=1);

namespace Toporia\Framework\Log\Contracts;


/**
 * Interface ChannelInterface
 *
 * Contract defining the interface for ChannelInterface implementations in
 * the Logging and error reporting layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Log\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface ChannelInterface
{
    /**
     * Write a log entry.
     *
     * @param string $level Log level
     * @param string $message Log message
     * @param array $context Additional context data
     * @return void
     */
    public function write(string $level, string $message, array $context = []): void;
}
