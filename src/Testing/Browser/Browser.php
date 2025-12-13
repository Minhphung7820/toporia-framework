<?php

declare(strict_types=1);

namespace Toporia\Framework\Testing\Browser;

use Toporia\Framework\Testing\Browser\Concerns\InteractsWithElements;
use Toporia\Framework\Testing\Browser\Concerns\InteractsWithMouse;
use Toporia\Framework\Testing\Browser\Concerns\InteractsWithKeyboard;
use Toporia\Framework\Testing\Browser\Concerns\MakesAssertions;
use Toporia\Framework\Testing\Browser\Concerns\WaitsForElements;

/**
 * Class Browser
 *
 * Browser Testing Class - Provides browser automation using WebDriver protocol.
 * Uses WebDriver protocol for browser control with connection pooling, lazy element resolution, and screenshot caching.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Testing\Browser
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class Browser
{
    use InteractsWithElements;
    use InteractsWithMouse;
    use InteractsWithKeyboard;
    use MakesAssertions;
    use WaitsForElements;

    /**
     * The WebDriver instance.
     *
     * @var WebDriver
     */
    protected WebDriver $driver;

    /**
     * The base URL for the application.
     *
     * @var string
     */
    protected string $baseUrl;

    /**
     * The current URL.
     *
     * @var string|null
     */
    protected ?string $currentUrl = null;

    /**
     * Screenshots directory.
     *
     * @var string
     */
    protected string $screenshotsPath = 'tests/Browser/screenshots';

    /**
     * Console logs directory.
     *
     * @var string
     */
    protected string $consolePath = 'tests/Browser/console';

    /**
     * Create a new browser instance.
     *
     * @param WebDriver $driver
     * @param string $baseUrl
     */
    public function __construct(WebDriver $driver, string $baseUrl = 'http://localhost')
    {
        $this->driver = $driver;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Visit the given URL.
     *
     * @param string $url
     * @return static
     */
    public function visit(string $url): static
    {
        $url = $this->resolveUrl($url);
        $this->driver->navigate($url);
        $this->currentUrl = $url;

        return $this;
    }

    /**
     * Resolve a URL relative to the base URL.
     *
     * @param string $url
     * @return string
     */
    protected function resolveUrl(string $url): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return $this->baseUrl . '/' . ltrim($url, '/');
    }

    /**
     * Get the current URL.
     *
     * @return string
     */
    public function getCurrentUrl(): string
    {
        return $this->driver->getCurrentUrl();
    }

    /**
     * Get the current path without the base URL.
     *
     * @return string
     */
    public function getCurrentPath(): string
    {
        $url = $this->getCurrentUrl();
        $parsed = parse_url($url);

        return $parsed['path'] ?? '/';
    }

    /**
     * Refresh the page.
     *
     * @return static
     */
    public function refresh(): static
    {
        $this->driver->refresh();

        return $this;
    }

    /**
     * Navigate back.
     *
     * @return static
     */
    public function back(): static
    {
        $this->driver->back();

        return $this;
    }

    /**
     * Navigate forward.
     *
     * @return static
     */
    public function forward(): static
    {
        $this->driver->forward();

        return $this;
    }

    /**
     * Take a screenshot.
     *
     * @param string $name
     * @return static
     */
    public function screenshot(string $name): static
    {
        $path = $this->screenshotsPath . '/' . $name . '.png';

        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $this->driver->takeScreenshot($path);

        return $this;
    }

    /**
     * Store console output.
     *
     * @param string $name
     * @return static
     */
    public function storeConsoleLog(string $name): static
    {
        $path = $this->consolePath . '/' . $name . '.log';

        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $logs = $this->driver->getConsoleLogs();
        file_put_contents($path, implode("\n", $logs));

        return $this;
    }

    /**
     * Get the page source.
     *
     * @return string
     */
    public function getPageSource(): string
    {
        return $this->driver->getPageSource();
    }

    /**
     * Get the page title.
     *
     * @return string
     */
    public function getTitle(): string
    {
        return $this->driver->getTitle();
    }

    /**
     * Execute JavaScript.
     *
     * @param string $script
     * @param array<mixed> $args
     * @return mixed
     */
    public function script(string $script, array $args = []): mixed
    {
        return $this->driver->executeScript($script, $args);
    }

    /**
     * Resize the browser window.
     *
     * @param int $width
     * @param int $height
     * @return static
     */
    public function resize(int $width, int $height): static
    {
        $this->driver->setWindowSize($width, $height);

        return $this;
    }

    /**
     * Maximize the browser window.
     *
     * @return static
     */
    public function maximize(): static
    {
        $this->driver->maximizeWindow();

        return $this;
    }

    /**
     * Set a cookie.
     *
     * @param string $name
     * @param string $value
     * @param array<string, mixed> $options
     * @return static
     */
    public function cookie(string $name, string $value, array $options = []): static
    {
        $this->driver->setCookie($name, $value, $options);

        return $this;
    }

    /**
     * Get a cookie value.
     *
     * @param string $name
     * @return string|null
     */
    public function getCookie(string $name): ?string
    {
        return $this->driver->getCookie($name);
    }

    /**
     * Delete a cookie.
     *
     * @param string $name
     * @return static
     */
    public function deleteCookie(string $name): static
    {
        $this->driver->deleteCookie($name);

        return $this;
    }

    /**
     * Clear all cookies.
     *
     * @return static
     */
    public function clearCookies(): static
    {
        $this->driver->deleteAllCookies();

        return $this;
    }

    /**
     * Switch to a frame.
     *
     * @param string|int $frame
     * @return static
     */
    public function withinFrame(string|int $frame, callable $callback): static
    {
        $this->driver->switchToFrame($frame);

        try {
            $callback($this);
        } finally {
            $this->driver->switchToDefaultContent();
        }

        return $this;
    }

    /**
     * Handle JavaScript dialog (alert, confirm, prompt).
     *
     * @param bool $accept
     * @param string|null $text
     * @return static
     */
    public function acceptDialog(bool $accept = true, ?string $text = null): static
    {
        if ($text !== null) {
            $this->driver->sendAlertText($text);
        }

        if ($accept) {
            $this->driver->acceptAlert();
        } else {
            $this->driver->dismissAlert();
        }

        return $this;
    }

    /**
     * Dismiss JavaScript dialog.
     *
     * @return static
     */
    public function dismissDialog(): static
    {
        return $this->acceptDialog(false);
    }

    /**
     * Get the WebDriver instance.
     *
     * @return WebDriver
     */
    public function getDriver(): WebDriver
    {
        return $this->driver;
    }

    /**
     * Set screenshots path.
     *
     * @param string $path
     * @return static
     */
    public function setScreenshotsPath(string $path): static
    {
        $this->screenshotsPath = $path;

        return $this;
    }

    /**
     * Set console logs path.
     *
     * @param string $path
     * @return static
     */
    public function setConsolePath(string $path): static
    {
        $this->consolePath = $path;

        return $this;
    }

    /**
     * Pause execution.
     *
     * @param int $milliseconds
     * @return static
     */
    public function pause(int $milliseconds): static
    {
        usleep($milliseconds * 1000);

        return $this;
    }

    /**
     * Dump the browser state for debugging.
     *
     * @return static
     */
    public function dump(): static
    {
        var_dump([
            'url' => $this->getCurrentUrl(),
            'title' => $this->getTitle(),
        ]);

        return $this;
    }

    /**
     * Dump and die.
     *
     * @return never
     */
    public function dd(): never
    {
        $this->dump();
        exit(1);
    }

    /**
     * Close the browser.
     *
     * @return void
     */
    public function quit(): void
    {
        $this->driver->quit();
    }
}
