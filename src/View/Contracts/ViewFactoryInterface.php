<?php

declare(strict_types=1);

namespace Toporia\Framework\View\Contracts;

use Toporia\Framework\View\View;

/**
 * Interface ViewFactoryInterface
 *
 * Contract for view factory implementations.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  View\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface ViewFactoryInterface
{
    /**
     * Create a new view instance.
     *
     * @param string $view
     * @param array<string, mixed> $data
     * @return View
     */
    public function make(string $view, array $data = []): View;

    /**
     * Render a view.
     *
     * @param View $view
     * @return string
     */
    public function render(View $view): string;

    /**
     * Register a view composer.
     *
     * @param string|array<string> $views
     * @param callable $callback
     * @return static
     */
    public function composer(string|array $views, callable $callback): static;

    /**
     * Share data with all views.
     *
     * @param string|array<string, mixed> $key
     * @param mixed $value
     * @return static
     */
    public function share(string|array $key, mixed $value = null): static;

    /**
     * Determine if a view exists.
     *
     * @param string $view
     * @return bool
     */
    public function exists(string $view): bool;
}
