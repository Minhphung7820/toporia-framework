<?php

declare(strict_types=1);

namespace Toporia\Framework\View;

use Toporia\Framework\Support\Str;

/**
 * Class Component
 *
 * Base class for creating reusable view components.
 * Similar to other frameworks's View Components but optimized for performance.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  View
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
abstract class Component
{
    /**
     * The component attributes bag.
     *
     * @var ComponentAttributeBag
     */
    public ComponentAttributeBag $attributes;

    /**
     * The component slot content.
     *
     * @var string
     */
    protected string $slot = '';

    /**
     * Named slots.
     *
     * @var array<string, string>
     */
    protected array $slots = [];

    /**
     * The cache of public property names.
     *
     * @var array<class-string, array<string>>
     */
    private static array $propertyCache = [];

    /**
     * The cache of public method names.
     *
     * @var array<class-string, array<string>>
     */
    private static array $methodCache = [];

    /**
     * Component alias for registration.
     *
     * @var string|null
     */
    protected static ?string $componentAlias = null;

    /**
     * Get the view/contents that represent the component.
     *
     * @return string|View|callable
     */
    abstract public function render(): string|View|callable;

    /**
     * Resolve the component instance with the given data.
     *
     * @param array<string, mixed> $data
     * @return static
     */
    public static function resolve(array $data = []): static
    {
        $instance = new static(...$data);
        $instance->attributes = new ComponentAttributeBag($data['attributes'] ?? []);

        return $instance;
    }

    /**
     * Get the data that should be supplied to the view.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $data = [];

        foreach ($this->extractPublicProperties() as $key) {
            $data[$key] = $this->{$key};
        }

        foreach ($this->extractPublicMethods() as $key) {
            $data[$key] = fn(...$args) => $this->{$key}(...$args);
        }

        return $data;
    }

    /**
     * Set the component slot content.
     *
     * @param string $slot
     * @return static
     */
    public function withSlot(string $slot): static
    {
        $this->slot = $slot;

        return $this;
    }

    /**
     * Get the component slot content.
     *
     * @return string
     */
    public function slot(): string
    {
        return $this->slot;
    }

    /**
     * Set a named slot.
     *
     * @param string $name
     * @param string $content
     * @return static
     */
    public function withNamedSlot(string $name, string $content): static
    {
        $this->slots[$name] = $content;

        return $this;
    }

    /**
     * Get a named slot.
     *
     * @param string $name
     * @param string $default
     * @return string
     */
    public function namedSlot(string $name, string $default = ''): string
    {
        return $this->slots[$name] ?? $default;
    }

    /**
     * Determine if a named slot exists.
     *
     * @param string $name
     * @return bool
     */
    public function hasSlot(string $name): bool
    {
        return isset($this->slots[$name]) && trim($this->slots[$name]) !== '';
    }

    /**
     * Set the extra attributes that the component should make available.
     *
     * @param array<string, mixed> $attributes
     * @return static
     */
    public function withAttributes(array $attributes): static
    {
        $this->attributes = $this->attributes->merge($attributes);

        return $this;
    }

    /**
     * Get the component alias.
     *
     * @return string
     */
    public static function componentAlias(): string
    {
        if (static::$componentAlias !== null) {
            return static::$componentAlias;
        }

        $class = static::class;
        $name = static::classBasename($class);

        return Str::kebab($name);
    }

    /**
     * Determine if the component should be rendered.
     *
     * @return bool
     */
    public function shouldRender(): bool
    {
        return true;
    }

    /**
     * Extract the public properties for the component.
     *
     * @return array<string>
     */
    protected function extractPublicProperties(): array
    {
        $class = static::class;

        if (isset(self::$propertyCache[$class])) {
            return self::$propertyCache[$class];
        }

        $properties = [];
        $reflection = new \ReflectionClass($this);

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getDeclaringClass()->getName() === self::class) {
                continue;
            }

            $properties[] = $property->getName();
        }

        return self::$propertyCache[$class] = $properties;
    }

    /**
     * Extract the public methods for the component.
     *
     * @return array<string>
     */
    protected function extractPublicMethods(): array
    {
        $class = static::class;

        if (isset(self::$methodCache[$class])) {
            return self::$methodCache[$class];
        }

        $methods = [];
        $reflection = new \ReflectionClass($this);

        $ignoreMethods = [
            'render',
            'resolve',
            'data',
            'withSlot',
            'slot',
            'withNamedSlot',
            'namedSlot',
            'hasSlot',
            'withAttributes',
            'componentAlias',
            'shouldRender',
            'extractPublicProperties',
            'extractPublicMethods',
            '__construct',
        ];

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() === self::class) {
                continue;
            }

            $name = $method->getName();

            if (in_array($name, $ignoreMethods, true) || str_starts_with($name, '__')) {
                continue;
            }

            $methods[] = $name;
        }

        return self::$methodCache[$class] = $methods;
    }

    /**
     * Get class basename helper.
     *
     * @param string $class
     * @return string
     */
    protected static function classBasename(string $class): string
    {
        $parts = explode('\\', $class);

        return end($parts);
    }
}
