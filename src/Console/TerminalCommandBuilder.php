<?php

declare(strict_types=1);

namespace Toporia\Framework\Console;

/**
 * Class TerminalCommandBuilder
 *
 * Fluent builder for terminal closure commands.
 * Allows method chaining for command configuration.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Console
 * @since       2025-01-17
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class TerminalCommandBuilder
{
    /**
     * Constructor
     *
     * @param TerminalCommandRegistrar $registrar
     * @param string $commandName
     */
    public function __construct(
        private readonly TerminalCommandRegistrar $registrar,
        private readonly string $commandName
    ) {
    }

    /**
     * Set command description
     *
     * @param string $description
     * @return self
     */
    public function describe(string $description): self
    {
        $this->registrar->setDescription($this->commandName, $description);
        return $this;
    }

    /**
     * Set command description (alias for describe)
     *
     * @param string $description
     * @return self
     */
    public function purpose(string $description): self
    {
        return $this->describe($description);
    }
}
