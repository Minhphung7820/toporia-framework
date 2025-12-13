<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Contracts;

/**
 * Interface HealthCheckableInterface
 *
 * Contract for components that support health checks.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Realtime\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface HealthCheckableInterface
{
    /**
     * Perform a health check.
     *
     * @return HealthCheckResult
     */
    public function healthCheck(): HealthCheckResult;

    /**
     * Get the component name for health reporting.
     *
     * @return string
     */
    public function getHealthCheckName(): string;
}
