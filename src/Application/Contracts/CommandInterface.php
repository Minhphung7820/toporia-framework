<?php

declare(strict_types=1);

namespace Toporia\Framework\Application\Contracts;


/**
 * Interface CommandInterface
 *
 * Contract defining the interface for CommandInterface implementations in
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
interface CommandInterface
{
    /**
     * Validate the command data.
     *
     * @return void
     * @throws \InvalidArgumentException If validation fails.
     */
    public function validate(): void;
}
