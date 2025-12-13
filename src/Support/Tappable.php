<?php

declare(strict_types=1);

namespace Toporia\Framework\Support;

/**
 * Trait Tappable
 *
 * Provides simple tap functionality for side effects.
 * Use this when you only need tap() without full Conditionable.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Support
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * Performance:
 * - O(1) - single callback invocation
 * - No overhead when not used
 *
 * Example:
 * ```php
 * $user->fill($data)
 *     ->tap(fn($user) => Log::info("Updating user {$user->id}"))
 *     ->save();
 * ```
 */
trait Tappable
{
    /**
     * Call the given callback with this instance then return the instance.
     *
     * @param callable(static): void $callback
     * @return static
     */
    public function tap(callable $callback): static
    {
        $callback($this);

        return $this;
    }
}
