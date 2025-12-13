<?php

declare(strict_types=1);

namespace Toporia\Framework\Application\Contracts;


/**
 * Interface HandlerInterface
 *
 * Contract defining the interface for HandlerInterface implementations in
 * the Application layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Application\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface HandlerInterface
{
    /**
     * Execute the use case.
     *
     * @param object $message Command or Query object.
     * @return mixed Result of the operation.
     */
    public function __invoke(object $message): mixed;
}
