<?php

declare(strict_types=1);

namespace Toporia\Framework\Events\Contracts;


/**
 * Interface ShouldQueue
 *
 * Queue manager supporting multiple drivers (Database, Redis, Sync) for
 * asynchronous job processing with delayed execution, job retries, and
 * failure tracking.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Events\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @internal    This class is a core component and should not be extended
 *              directly unless you know what you're doing.
 */
interface ShouldQueue
{
    // Marker interface - no methods required
}

