<?php

declare(strict_types=1);

namespace Toporia\Framework\Mail\Transport;

use Toporia\Framework\Mail\Contracts\MessageInterface;

/**
 * Interface TransportInterface
 *
 * Contract for mail transport implementations.
 * Follows Strategy pattern for transport selection.
 *
 * Performance:
 * - Transports are lazy-loaded
 * - Connection pooling where applicable
 * - Async support via queue integration
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Mail\Transport
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface TransportInterface
{
    /**
     * Send an email message.
     *
     * @param MessageInterface $message Email message to send.
     * @return TransportResult Result containing message ID and metadata.
     * @throws TransportException If sending fails.
     */
    public function send(MessageInterface $message): TransportResult;

    /**
     * Get transport name.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Check if transport is healthy/connected.
     *
     * @return bool
     */
    public function isHealthy(): bool;
}
