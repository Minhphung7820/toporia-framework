<?php

declare(strict_types=1);

namespace Toporia\Framework\Support\Accessors;

use Closure;
use Toporia\Framework\Console\ClosureCommand;
use Toporia\Framework\Support\ServiceAccessor;

/**
 * Class Terminal
 *
 * Static accessor for defining closure-based console commands.
 * Provides Laravel-like Artisan::command() syntax for Toporia.
 *
 * Usage in routes/terminal.php:
 * ```php
 * Terminal::command('mail:send {user}', function (string $user) {
 *     $this->info("Sending email to: {$user}");
 * })->describe('Send marketing email');
 * ```
 *
 * @method static ClosureCommand command(string $signature, Closure $callback) Register a closure-based command
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Support\Accessors
 * @since       2025-01-17
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @see \Toporia\Framework\Console\TerminalCommandRegistrar
 */
final class Terminal extends ServiceAccessor
{
    /**
     * Get the accessor name
     *
     * @return string
     */
    protected static function getAccessor(): string
    {
        return 'terminal';
    }
}
