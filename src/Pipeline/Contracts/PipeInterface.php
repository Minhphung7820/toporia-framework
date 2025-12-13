<?php

declare(strict_types=1);

namespace Toporia\Framework\Pipeline\Contracts;

use Closure;


/**
 * Interface PipeInterface
 *
 * Contract defining the interface for PipeInterface implementations in the
 * Pipeline layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Pipeline\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface PipeInterface
{
    /**
     * Handle the data through the pipe.
     *
     * @param mixed $passable The data being passed through the pipeline
     * @param Closure $next The next pipe in the pipeline
     * @return mixed The processed data
     */
    public function handle(mixed $passable, Closure $next): mixed;
}
