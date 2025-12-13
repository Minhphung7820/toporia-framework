<?php

declare(strict_types=1);

namespace Toporia\Framework\Support;

use Toporia\Framework\Macro\Contracts\MacroRegistryInterface;
use Toporia\Framework\Macro\SimpleMacroRegistry;


/**
 * Trait Macroable
 *
 * Trait providing reusable functionality for Macroable in the Support
 * layer of the Toporia Framework.
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
 */
trait Macroable
{
    /**
     * Register a macro for this class.
     *
     * @param string $name Macro name (method name)
     * @param callable $callback Macro implementation
     * @return void
     */
    public static function macro(string $name, callable $callback): void
    {
        $registry = self::getMacroRegistry();
        $target = static::class;
        $registry->register($target, $name, $callback);
    }

    /**
     * Check if macro exists.
     *
     * @param string $name Macro name
     * @return bool True if macro exists
     */
    public static function hasMacro(string $name): bool
    {
        $registry = self::getMacroRegistry();
        $target = static::class;
        return $registry->has($target, $name);
    }

    /**
     * Get macro callback.
     *
     * @param string $name Macro name
     * @return callable|null Macro callback or null if not found
     */
    public static function getMacro(string $name): ?callable
    {
        $registry = self::getMacroRegistry();
        $target = static::class;
        return $registry->get($target, $name);
    }

    /**
     * Handle dynamic method calls.
     *
     * @param string $method Method name
     * @param array<mixed> $parameters Method parameters
     * @return mixed Method result
     * @throws \BadMethodCallException If method not found
     */
    public function __call(string $method, array $parameters): mixed
    {
        // Check for macro
        $macro = static::getMacro($method);
        if ($macro !== null) {
            // Bind $this to macro callback only if it's a Closure
            if ($macro instanceof \Closure) {
                $macro = $macro->bindTo($this, static::class);
            }
            return $macro(...$parameters);
        }

        throw new \BadMethodCallException(
            sprintf(
                'Method %s::%s does not exist and no macro registered.',
                static::class,
                $method
            )
        );
    }

    /**
     * Handle static method calls.
     *
     * @param string $method Method name
     * @param array<mixed> $parameters Method parameters
     * @return mixed Method result
     * @throws \BadMethodCallException If method not found
     */
    public static function __callStatic(string $method, array $parameters): mixed
    {
        // Check for macro
        $macro = static::getMacro($method);
        if ($macro !== null) {
            return $macro(...$parameters);
        }

        throw new \BadMethodCallException(
            sprintf(
                'Static method %s::%s does not exist and no macro registered.',
                static::class,
                $method
            )
        );
    }

    /**
     * Get macro registry instance.
     *
     * Uses a simple in-memory registry shared across all Macroable classes.
     *
     * @return MacroRegistryInterface Macro registry
     */
    private static function getMacroRegistry(): MacroRegistryInterface
    {
        static $registry = null;

        if ($registry === null) {
            $registry = new SimpleMacroRegistry();
        }

        return $registry;
    }
}
