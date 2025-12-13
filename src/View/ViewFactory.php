<?php

declare(strict_types=1);

namespace Toporia\Framework\View;

use Toporia\Framework\View\Contracts\ViewFactoryInterface;

/**
 * Class ViewFactory
 *
 * Factory for creating and managing views.
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
class ViewFactory implements ViewFactoryInterface
{
    /**
     * The base path for views.
     *
     * @var array<string>
     */
    protected array $paths = [];

    /**
     * The registered view composers.
     *
     * @var array<string, array<callable>>
     */
    protected array $composers = [];

    /**
     * The registered view creators.
     *
     * @var array<string, array<callable>>
     */
    protected array $creators = [];

    /**
     * Registered components.
     *
     * @var array<string, class-string<Component>>
     */
    protected array $components = [];

    /**
     * Component namespaces.
     *
     * @var array<string, string>
     */
    protected array $componentNamespaces = [];

    /**
     * The file extension for views.
     *
     * @var string
     */
    protected string $extension = '.php';

    /**
     * Shared data for all views.
     *
     * @var array<string, mixed>
     */
    protected array $shared = [];

    /**
     * Create a new view factory instance.
     *
     * @param string|array<string> $paths
     */
    public function __construct(string|array $paths = [])
    {
        $this->paths = is_array($paths) ? $paths : [$paths];
    }

    /**
     * Create a new view instance.
     *
     * @param string $view
     * @param array<string, mixed> $data
     * @return View
     */
    public function make(string $view, array $data = []): View
    {
        $path = $this->findView($view);

        $viewInstance = new View($path, array_merge($this->shared, $data), $this);

        // Call creators
        $this->callCreators($viewInstance, $view);

        return $viewInstance;
    }

    /**
     * Find the view file path.
     *
     * @param string $view
     * @return string
     * @throws \RuntimeException
     */
    protected function findView(string $view): string
    {
        $name = str_replace('.', DIRECTORY_SEPARATOR, $view);

        foreach ($this->paths as $path) {
            $fullPath = $path . DIRECTORY_SEPARATOR . $name . $this->extension;

            if (file_exists($fullPath)) {
                return $fullPath;
            }
        }

        throw new \RuntimeException("View [{$view}] not found.");
    }

    /**
     * Render a view.
     *
     * @param View $view
     * @return string
     */
    public function render(View $view): string
    {
        // Call composers before rendering
        $this->callComposers($view);

        return $this->renderView($view);
    }

    /**
     * Render the view contents.
     *
     * @param View $view
     * @return string
     */
    protected function renderView(View $view): string
    {
        $path = $view->path();
        $data = $view->data();

        extract($data, EXTR_SKIP);

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
     * Add a view path.
     *
     * @param string $path
     * @param bool $prepend
     * @return static
     */
    public function addPath(string $path, bool $prepend = false): static
    {
        if ($prepend) {
            array_unshift($this->paths, $path);
        } else {
            $this->paths[] = $path;
        }

        return $this;
    }

    /**
     * Get view paths.
     *
     * @return array<string>
     */
    public function getPaths(): array
    {
        return $this->paths;
    }

    /**
     * Register a view composer.
     *
     * @param string|array<string> $views
     * @param callable $callback
     * @return static
     */
    public function composer(string|array $views, callable $callback): static
    {
        foreach ((array) $views as $view) {
            $this->composers[$view][] = $callback;
        }

        return $this;
    }

    /**
     * Register a view creator.
     *
     * @param string|array<string> $views
     * @param callable $callback
     * @return static
     */
    public function creator(string|array $views, callable $callback): static
    {
        foreach ((array) $views as $view) {
            $this->creators[$view][] = $callback;
        }

        return $this;
    }

    /**
     * Call the composers for the view.
     *
     * @param View $view
     * @return void
     */
    protected function callComposers(View $view): void
    {
        $name = $view->name();

        // Call exact match composers
        foreach ($this->composers[$name] ?? [] as $callback) {
            $callback($view);
        }

        // Call wildcard composers
        foreach ($this->composers as $pattern => $callbacks) {
            if ($this->matchesPattern($name, $pattern)) {
                foreach ($callbacks as $callback) {
                    $callback($view);
                }
            }
        }
    }

    /**
     * Call the creators for the view.
     *
     * @param View $view
     * @param string $name
     * @return void
     */
    protected function callCreators(View $view, string $name): void
    {
        foreach ($this->creators[$name] ?? [] as $callback) {
            $callback($view);
        }
    }

    /**
     * Check if view name matches a pattern.
     *
     * @param string $name
     * @param string $pattern
     * @return bool
     */
    protected function matchesPattern(string $name, string $pattern): bool
    {
        if ($pattern === '*') {
            return true;
        }

        if (!str_contains($pattern, '*')) {
            return $name === $pattern;
        }

        $regex = str_replace('\*', '.*', preg_quote($pattern, '#'));

        return (bool) preg_match('#^' . $regex . '\z#u', $name);
    }

    /**
     * Share data with all views.
     *
     * @param string|array<string, mixed> $key
     * @param mixed $value
     * @return static
     */
    public function share(string|array $key, mixed $value = null): static
    {
        if (is_array($key)) {
            $this->shared = array_merge($this->shared, $key);
        } else {
            $this->shared[$key] = $value;
        }

        return $this;
    }

    /**
     * Get shared data.
     *
     * @param string|null $key
     * @param mixed $default
     * @return mixed
     */
    public function shared(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->shared;
        }

        return $this->shared[$key] ?? $default;
    }

    /**
     * Register a component.
     *
     * @param class-string<Component> $class
     * @param string|null $alias
     * @return static
     */
    public function component(string $class, ?string $alias = null): static
    {
        $alias = $alias ?? $class::componentAlias();
        $this->components[$alias] = $class;

        return $this;
    }

    /**
     * Register a component namespace.
     *
     * @param string $prefix
     * @param string $namespace
     * @return static
     */
    public function componentNamespace(string $prefix, string $namespace): static
    {
        $this->componentNamespaces[$prefix] = $namespace;

        return $this;
    }

    /**
     * Render a component.
     *
     * @param string $name
     * @param array<string, mixed> $data
     * @param string $slot
     * @param array<string, string> $slots
     * @return string
     */
    public function renderComponent(
        string $name,
        array $data = [],
        string $slot = '',
        array $slots = []
    ): string {
        $component = $this->resolveComponent($name, $data);

        if (!$component->shouldRender()) {
            return '';
        }

        $component->withSlot($slot);

        foreach ($slots as $slotName => $content) {
            $component->withNamedSlot($slotName, $content);
        }

        $result = $component->render();

        if ($result instanceof View) {
            return $result->with($component->data())->render();
        }

        if (is_callable($result)) {
            return (string) $result($component->data());
        }

        return $this->interpolate($result, $component->data());
    }

    /**
     * Resolve a component instance.
     *
     * @param string $name
     * @param array<string, mixed> $data
     * @return Component
     */
    protected function resolveComponent(string $name, array $data): Component
    {
        $class = $this->components[$name] ?? null;

        if ($class === null) {
            $class = $this->resolveComponentFromNamespace($name);
        }

        if ($class === null) {
            throw new \RuntimeException("Component [{$name}] not found.");
        }

        return $class::resolve($data);
    }

    /**
     * Resolve a component from registered namespaces.
     *
     * @param string $name
     * @return class-string<Component>|null
     */
    protected function resolveComponentFromNamespace(string $name): ?string
    {
        foreach ($this->componentNamespaces as $prefix => $namespace) {
            if (str_starts_with($name, $prefix . '::')) {
                $componentName = substr($name, strlen($prefix) + 2);
                $class = $namespace . '\\' . str_replace('.', '\\', ucwords($componentName, '.'));

                if (class_exists($class)) {
                    return $class;
                }
            }
        }

        return null;
    }

    /**
     * Simple string interpolation for component templates.
     *
     * @param string $template
     * @param array<string, mixed> $data
     * @return string
     */
    protected function interpolate(string $template, array $data): string
    {
        return preg_replace_callback(
            '/\{\{\s*\$([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/',
            function ($matches) use ($data) {
                $key = $matches[1];
                $value = $data[$key] ?? '';

                return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
            },
            $template
        ) ?? $template;
    }

    /**
     * Determine if a view exists.
     *
     * @param string $view
     * @return bool
     */
    public function exists(string $view): bool
    {
        try {
            $this->findView($view);
            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    /**
     * Set the file extension.
     *
     * @param string $extension
     * @return static
     */
    public function setExtension(string $extension): static
    {
        $this->extension = $extension;

        return $this;
    }
}
