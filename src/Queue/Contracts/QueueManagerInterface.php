<?php

declare(strict_types=1);

namespace Toporia\Framework\Queue\Contracts;


/**
 * Interface QueueManagerInterface
 *
 * Contract defining the interface for QueueManagerInterface
 * implementations in the Asynchronous job processing layer of the Toporia
 * Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Queue\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface QueueManagerInterface extends QueueInterface
{
    /**
     * Get a queue driver instance
     *
     * @param string|null $driver Driver name (null = default)
     * @return QueueInterface
     */
    public function driver(?string $driver = null): QueueInterface;

    /**
     * Get default driver name
     *
     * @return string
     */
    public function getDefaultDriver(): string;
}
