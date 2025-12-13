<?php

declare(strict_types=1);

namespace Toporia\Framework\Testing\Browser\Concerns;

/**
 * Trait WaitsForElements
 *
 * Provides waiting methods for browser testing including element visibility, text appearance, and custom conditions.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Testing\Browser\Concerns
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
trait WaitsForElements
{
    /**
     * Default wait timeout in seconds.
     *
     * @var int
     */
    protected int $defaultWaitTimeout = 5;

    /**
     * Wait for an element to be present.
     *
     * @param string $selector
     * @param int|null $seconds
     * @return static
     */
    public function waitFor(string $selector, ?int $seconds = null): static
    {
        $seconds = $seconds ?? $this->defaultWaitTimeout;
        $end = now()->getTimestamp() + $seconds;

        while (now()->getTimestamp() < $end) {
            if ($this->elementExists($selector)) {
                return $this;
            }

            usleep(100000); // 100ms
        }

        throw new \RuntimeException(
            "Timed out waiting for element [{$selector}] after {$seconds} seconds"
        );
    }

    /**
     * Wait for an element to be visible.
     *
     * @param string $selector
     * @param int|null $seconds
     * @return static
     */
    public function waitForVisible(string $selector, ?int $seconds = null): static
    {
        $seconds = $seconds ?? $this->defaultWaitTimeout;
        $end = now()->getTimestamp() + $seconds;

        while (now()->getTimestamp() < $end) {
            if ($this->isVisible($selector)) {
                return $this;
            }

            usleep(100000);
        }

        throw new \RuntimeException(
            "Timed out waiting for element [{$selector}] to be visible after {$seconds} seconds"
        );
    }

    /**
     * Wait until an element is not visible.
     *
     * @param string $selector
     * @param int|null $seconds
     * @return static
     */
    public function waitUntilMissing(string $selector, ?int $seconds = null): static
    {
        $seconds = $seconds ?? $this->defaultWaitTimeout;
        $end = now()->getTimestamp() + $seconds;

        while (now()->getTimestamp() < $end) {
            if (!$this->isVisible($selector)) {
                return $this;
            }

            usleep(100000);
        }

        throw new \RuntimeException(
            "Timed out waiting for element [{$selector}] to disappear after {$seconds} seconds"
        );
    }

    /**
     * Wait for text to appear on page.
     *
     * @param string $text
     * @param int|null $seconds
     * @return static
     */
    public function waitForText(string $text, ?int $seconds = null): static
    {
        $seconds = $seconds ?? $this->defaultWaitTimeout;
        $end = now()->getTimestamp() + $seconds;

        while (now()->getTimestamp() < $end) {
            if (str_contains($this->getPageSource(), $text)) {
                return $this;
            }

            usleep(100000);
        }

        throw new \RuntimeException(
            "Timed out waiting for text [{$text}] after {$seconds} seconds"
        );
    }

    /**
     * Wait for text to appear in element.
     *
     * @param string $selector
     * @param string $text
     * @param int|null $seconds
     * @return static
     */
    public function waitForTextIn(string $selector, string $text, ?int $seconds = null): static
    {
        $seconds = $seconds ?? $this->defaultWaitTimeout;
        $end = now()->getTimestamp() + $seconds;

        while (now()->getTimestamp() < $end) {
            try {
                if (str_contains($this->text($selector), $text)) {
                    return $this;
                }
            } catch (\Throwable) {
                // Element might not exist yet
            }

            usleep(100000);
        }

        throw new \RuntimeException(
            "Timed out waiting for text [{$text}] in [{$selector}] after {$seconds} seconds"
        );
    }

    /**
     * Wait for a URL change.
     *
     * @param string $url
     * @param int|null $seconds
     * @return static
     */
    public function waitForUrl(string $url, ?int $seconds = null): static
    {
        $seconds = $seconds ?? $this->defaultWaitTimeout;
        $end = now()->getTimestamp() + $seconds;

        while (now()->getTimestamp() < $end) {
            if ($this->getCurrentUrl() === $url) {
                return $this;
            }

            usleep(100000);
        }

        throw new \RuntimeException(
            "Timed out waiting for URL [{$url}] after {$seconds} seconds"
        );
    }

    /**
     * Wait for a path change.
     *
     * @param string $path
     * @param int|null $seconds
     * @return static
     */
    public function waitForPath(string $path, ?int $seconds = null): static
    {
        $seconds = $seconds ?? $this->defaultWaitTimeout;
        $end = now()->getTimestamp() + $seconds;

        while (now()->getTimestamp() < $end) {
            if ($this->getCurrentPath() === $path) {
                return $this;
            }

            usleep(100000);
        }

        throw new \RuntimeException(
            "Timed out waiting for path [{$path}] after {$seconds} seconds"
        );
    }

    /**
     * Wait for JavaScript condition.
     *
     * @param string $script JavaScript expression that returns boolean
     * @param int|null $seconds
     * @return static
     */
    public function waitForCondition(string $script, ?int $seconds = null): static
    {
        $seconds = $seconds ?? $this->defaultWaitTimeout;
        $end = now()->getTimestamp() + $seconds;

        while (now()->getTimestamp() < $end) {
            $result = $this->script("return {$script}");

            if ($result === true) {
                return $this;
            }

            usleep(100000);
        }

        throw new \RuntimeException(
            "Timed out waiting for condition [{$script}] after {$seconds} seconds"
        );
    }

    /**
     * Wait for AJAX requests to complete.
     *
     * @param int|null $seconds
     * @return static
     */
    public function waitForAjax(?int $seconds = null): static
    {
        return $this->waitForCondition(
            'typeof jQuery === "undefined" || jQuery.active === 0',
            $seconds
        );
    }

    /**
     * Wait for Vue.js updates to complete.
     *
     * @param int|null $seconds
     * @return static
     */
    public function waitForVue(?int $seconds = null): static
    {
        return $this->waitForCondition(
            'typeof Vue === "undefined" || Vue.nextTick !== undefined',
            $seconds
        );
    }

    /**
     * Wait for element to be enabled.
     *
     * @param string $selector
     * @param int|null $seconds
     * @return static
     */
    public function waitForEnabled(string $selector, ?int $seconds = null): static
    {
        $seconds = $seconds ?? $this->defaultWaitTimeout;
        $end = now()->getTimestamp() + $seconds;

        while (now()->getTimestamp() < $end) {
            if ($this->isEnabled($selector)) {
                return $this;
            }

            usleep(100000);
        }

        throw new \RuntimeException(
            "Timed out waiting for [{$selector}] to be enabled after {$seconds} seconds"
        );
    }

    /**
     * Wait using custom callback.
     *
     * @param callable $callback
     * @param int|null $seconds
     * @return static
     */
    public function waitUsing(?int $seconds, callable $callback): static
    {
        $seconds = $seconds ?? $this->defaultWaitTimeout;
        $end = now()->getTimestamp() + $seconds;

        while (now()->getTimestamp() < $end) {
            if ($callback($this) === true) {
                return $this;
            }

            usleep(100000);
        }

        throw new \RuntimeException(
            "Timed out after {$seconds} seconds"
        );
    }

    /**
     * Wait for page to fully load.
     *
     * @param int|null $seconds
     * @return static
     */
    public function waitForPageLoad(?int $seconds = null): static
    {
        return $this->waitForCondition('document.readyState === "complete"', $seconds);
    }

    /**
     * Set default wait timeout.
     *
     * @param int $seconds
     * @return static
     */
    public function setDefaultWaitTimeout(int $seconds): static
    {
        $this->defaultWaitTimeout = $seconds;

        return $this;
    }
}
