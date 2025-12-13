<?php

declare(strict_types=1);

namespace Toporia\Framework\Macro;

use Toporia\Framework\Macro\Contracts\MacroRegistryInterface;

/**
 * Class SimpleMacroRegistry
 *
 * Default fallback implementation for MacroRegistryInterface.
 * Used when container is not available or MacroRegistryInterface is not registered.
 *
 * This is a Framework-level implementation that provides basic functionality
 * without requiring App-level dependencies.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Macro
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class SimpleMacroRegistry implements MacroRegistryInterface
{
    /**
     * @var array<string, array<string, callable>> Macros by target class
     * Format: ['ClassName' => ['macroName' => callable]]
     */
    private array $macros = [];

    /**
     * {@inheritdoc}
     */
    public function register(string $target, string $name, callable $callback): void
    {
        $target = $this->normalizeTarget($target);
        if (!isset($this->macros[$target])) {
            $this->macros[$target] = [];
        }
        $this->macros[$target][$name] = $callback;
    }

    /**
     * {@inheritdoc}
     */
    public function has(string $target, string $name): bool
    {
        $target = $this->normalizeTarget($target);
        return isset($this->macros[$target][$name]);
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $target, string $name): ?callable
    {
        $target = $this->normalizeTarget($target);
        return $this->macros[$target][$name] ?? null;
    }

    /**
     * {@inheritdoc}
     */
    public function getAll(string $target): array
    {
        $target = $this->normalizeTarget($target);
        return $this->macros[$target] ?? [];
    }

    /**
     * {@inheritdoc}
     */
    public function remove(string $target, string $name): void
    {
        $target = $this->normalizeTarget($target);
        unset($this->macros[$target][$name]);
    }

    /**
     * {@inheritdoc}
     */
    public function clear(string $target): void
    {
        $target = $this->normalizeTarget($target);
        unset($this->macros[$target]);
    }

    /**
     * {@inheritdoc}
     */
    public function clearAll(): void
    {
        $this->macros = [];
    }

    /**
     * Normalize target class name.
     *
     * @param string $target Target class name
     * @return string Normalized class name
     */
    private function normalizeTarget(string $target): string
    {
        return ltrim($target, '\\');
    }
}


