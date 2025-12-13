<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Contracts;


/**
 * Interface OutputInterface
 *
 * Contract defining the interface for OutputInterface implementations in
 * the CLI command framework layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Console\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface OutputInterface
{
    /**
     * Write a message to the output
     *
     * @param string $message
     * @return void
     */
    public function write(string $message): void;

    /**
     * Write a message to the output with newline
     *
     * @param string $message
     * @return void
     */
    public function writeln(string $message): void;

    /**
     * Write an info message
     *
     * @param string $message
     * @return void
     */
    public function info(string $message): void;

    /**
     * Write an error message
     *
     * @param string $message
     * @return void
     */
    public function error(string $message): void;

    /**
     * Write a success message
     *
     * @param string $message
     * @return void
     */
    public function success(string $message): void;

    /**
     * Write a warning message
     *
     * @param string $message
     * @return void
     */
    public function warning(string $message): void;

    /**
     * Write a line separator
     *
     * @param string $char
     * @param int $length
     * @return void
     */
    public function line(string $char = '-', int $length = 80): void;

    /**
     * Write a blank line
     *
     * @return void
     */
    public function newLine(int $count = 1): void;

    /**
     * Write a table
     *
     * @param array<string> $headers
     * @param array<array<string>> $rows
     * @return void
     */
    public function table(array $headers, array $rows): void;
}
