<?php

declare(strict_types=1);

namespace Toporia\Framework\View;

use Stringable;
use Toporia\Framework\View\Contracts\ViewInterface;

/**
 * Class View
 *
 * Represents a renderable view with data binding.
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
class View implements ViewInterface, Stringable
{
    /**
     * The view path.
     *
     * @var string
     */
    protected string $path;

    /**
     * The view data.
     *
     * @var array<string, mixed>
     */
    protected array $data = [];

    /**
     * The view factory instance.
     *
     * @var ViewFactory|null
     */
    protected ?ViewFactory $factory = null;

    /**
     * Shared data across all views.
     *
     * @var array<string, mixed>
     */
    protected static array $shared = [];

    /**
     * Create a new view instance.
     *
     * @param string $path
     * @param array<string, mixed> $data
     * @param ViewFactory|null $factory
     */
    public function __construct(string $path, array $data = [], ?ViewFactory $factory = null)
    {
        $this->path = $path;
        $this->data = $data;
        $this->factory = $factory;
    }

    /**
     * Get the view path.
     *
     * @return string
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Get the view name.
     *
     * @return string
     */
    public function name(): string
    {
        return $this->path;
    }

    /**
     * Add data to the view.
     *
     * @param string|array<string, mixed> $key
     * @param mixed $value
     * @return static
     */
    public function with(string|array $key, mixed $value = null): static
    {
        if (is_array($key)) {
            $this->data = array_merge($this->data, $key);
        } else {
            $this->data[$key] = $value;
        }

        return $this;
    }

    /**
     * Get all data.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return array_merge(self::$shared, $this->data);
    }

    /**
     * Get a specific data value.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? self::$shared[$key] ?? $default;
    }

    /**
     * Share data across all views.
     *
     * @param string|array<string, mixed> $key
     * @param mixed $value
     * @return void
     */
    public static function share(string|array $key, mixed $value = null): void
    {
        if (is_array($key)) {
            self::$shared = array_merge(self::$shared, $key);
        } else {
            self::$shared[$key] = $value;
        }
    }

    /**
     * Get all shared data.
     *
     * @return array<string, mixed>
     */
    public static function getShared(): array
    {
        return self::$shared;
    }

    /**
     * Clear all shared data.
     *
     * @return void
     */
    public static function clearShared(): void
    {
        self::$shared = [];
    }

    /**
     * Render the view.
     *
     * @return string
     */
    public function render(): string
    {
        if ($this->factory !== null) {
            return $this->factory->render($this);
        }

        return $this->renderFromFile();
    }

    /**
     * Render directly from file.
     *
     * @return string
     */
    protected function renderFromFile(): string
    {
        $path = $this->resolvePath();

        if (!file_exists($path)) {
            throw new \RuntimeException("View [{$this->path}] not found at: {$path}");
        }

        $data = $this->data();

        // Extract data to local scope
        extract($data, EXTR_SKIP);

        // Start output buffering
        ob_start();

        try {
            include $path;
            return ob_get_clean() ?: '';
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }

    /**
     * Resolve the view path.
     *
     * @return string
     */
    protected function resolvePath(): string
    {
        // If already a full path
        if (str_ends_with($this->path, '.php')) {
            return $this->path;
        }

        // Convert dot notation to path
        $path = str_replace('.', DIRECTORY_SEPARATOR, $this->path);

        // Try common view directories
        $basePaths = [
            dirname(__DIR__, 4) . '/resources/views',
            dirname(__DIR__, 3) . '/resources/views',
            dirname(__DIR__, 2) . '/resources/views',
        ];

        foreach ($basePaths as $basePath) {
            $fullPath = $basePath . DIRECTORY_SEPARATOR . $path . '.php';
            if (file_exists($fullPath)) {
                return $fullPath;
            }
        }

        return $path . '.php';
    }

    /**
     * Get the string representation.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->render();
    }

    /**
     * Nest another view.
     *
     * @param string $key
     * @param string $view
     * @param array<string, mixed> $data
     * @return static
     */
    public function nest(string $key, string $view, array $data = []): static
    {
        return $this->with($key, new static($view, $data, $this->factory));
    }

    /**
     * Add a view composer.
     *
     * @param callable $callback
     * @return static
     */
    public function composer(callable $callback): static
    {
        $callback($this);

        return $this;
    }

    /**
     * Determine if the view exists.
     *
     * @return bool
     */
    public function exists(): bool
    {
        try {
            return file_exists($this->resolvePath());
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Get the factory instance.
     *
     * @return ViewFactory|null
     */
    public function factory(): ?ViewFactory
    {
        return $this->factory;
    }
}
