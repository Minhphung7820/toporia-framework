<?php

declare(strict_types=1);

namespace Toporia\Framework\Testing\Browser\Concerns;

/**
 * Trait InteractsWithElements
 *
 * Provides methods for interacting with page elements including typing, clicking, selecting, and more.
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
trait InteractsWithElements
{
    /**
     * Type into an element.
     *
     * @param string $selector
     * @param string $text
     * @return static
     */
    public function type(string $selector, string $text): static
    {
        $elementId = $this->findElement($selector);

        $this->driver->clear($elementId);
        $this->driver->type($elementId, $text);

        return $this;
    }

    /**
     * Append text to an element.
     *
     * @param string $selector
     * @param string $text
     * @return static
     */
    public function append(string $selector, string $text): static
    {
        $elementId = $this->findElement($selector);
        $this->driver->type($elementId, $text);

        return $this;
    }

    /**
     * Clear an input.
     *
     * @param string $selector
     * @return static
     */
    public function clear(string $selector): static
    {
        $elementId = $this->findElement($selector);
        $this->driver->clear($elementId);

        return $this;
    }

    /**
     * Click an element.
     *
     * @param string $selector
     * @return static
     */
    public function click(string $selector): static
    {
        $elementId = $this->findElement($selector);
        $this->driver->click($elementId);

        return $this;
    }

    /**
     * Press a button by text.
     *
     * @param string $text
     * @return static
     */
    public function press(string $text): static
    {
        // Try button with text
        $buttons = $this->driver->findElements('xpath', "//button[contains(., '{$text}')]");

        if (!empty($buttons)) {
            $this->driver->click($buttons[0]);
            return $this;
        }

        // Try input with value
        $inputs = $this->driver->findElements(
            'xpath',
            "//input[@type='submit' and @value='{$text}']"
        );

        if (!empty($inputs)) {
            $this->driver->click($inputs[0]);
            return $this;
        }

        throw new \RuntimeException("Button with text '{$text}' not found");
    }

    /**
     * Click a link by text.
     *
     * @param string $text
     * @return static
     */
    public function clickLink(string $text): static
    {
        $links = $this->driver->findElements('xpath', "//a[contains(., '{$text}')]");

        if (empty($links)) {
            throw new \RuntimeException("Link with text '{$text}' not found");
        }

        $this->driver->click($links[0]);

        return $this;
    }

    /**
     * Check a checkbox.
     *
     * @param string $selector
     * @return static
     */
    public function check(string $selector): static
    {
        $elementId = $this->findElement($selector);

        if (!$this->driver->isSelected($elementId)) {
            $this->driver->click($elementId);
        }

        return $this;
    }

    /**
     * Uncheck a checkbox.
     *
     * @param string $selector
     * @return static
     */
    public function uncheck(string $selector): static
    {
        $elementId = $this->findElement($selector);

        if ($this->driver->isSelected($elementId)) {
            $this->driver->click($elementId);
        }

        return $this;
    }

    /**
     * Select an option by value.
     *
     * @param string $selector
     * @param string $value
     * @return static
     */
    public function select(string $selector, string $value): static
    {
        $elementId = $this->findElement($selector);

        $options = $this->driver->findElements(
            'xpath',
            "//*[@id='{$elementId}']//option[@value='{$value}']"
        );

        if (!empty($options)) {
            $this->driver->click($options[0]);
        }

        return $this;
    }

    /**
     * Select by visible text.
     *
     * @param string $selector
     * @param string $text
     * @return static
     */
    public function selectByText(string $selector, string $text): static
    {
        $elementId = $this->findElement($selector);

        $this->driver->executeScript(
            "var select = arguments[0]; " .
            "for (var i = 0; i < select.options.length; i++) { " .
            "  if (select.options[i].text === arguments[1]) { " .
            "    select.selectedIndex = i; break; " .
            "  } " .
            "}",
            [$elementId, $text]
        );

        return $this;
    }

    /**
     * Attach a file to a file input.
     *
     * @param string $selector
     * @param string $path
     * @return static
     */
    public function attach(string $selector, string $path): static
    {
        $elementId = $this->findElement($selector);
        $this->driver->type($elementId, realpath($path) ?: $path);

        return $this;
    }

    /**
     * Focus an element.
     *
     * @param string $selector
     * @return static
     */
    public function focus(string $selector): static
    {
        $elementId = $this->findElement($selector);

        $this->driver->executeScript(
            'arguments[0].focus()',
            [['element-6066-11e4-a52e-4f735466cecf' => $elementId]]
        );

        return $this;
    }

    /**
     * Scroll to an element.
     *
     * @param string $selector
     * @return static
     */
    public function scrollTo(string $selector): static
    {
        $elementId = $this->findElement($selector);

        $this->driver->executeScript(
            'arguments[0].scrollIntoView({behavior: "smooth", block: "center"})',
            [['element-6066-11e4-a52e-4f735466cecf' => $elementId]]
        );

        return $this;
    }

    /**
     * Get element value.
     *
     * @param string $selector
     * @return string|null
     */
    public function value(string $selector): ?string
    {
        $elementId = $this->findElement($selector);

        return $this->driver->getProperty($elementId, 'value');
    }

    /**
     * Get element text.
     *
     * @param string $selector
     * @return string
     */
    public function text(string $selector): string
    {
        $elementId = $this->findElement($selector);

        return $this->driver->getText($elementId);
    }

    /**
     * Get element attribute.
     *
     * @param string $selector
     * @param string $attribute
     * @return string|null
     */
    public function attribute(string $selector, string $attribute): ?string
    {
        $elementId = $this->findElement($selector);

        return $this->driver->getAttribute($elementId, $attribute);
    }

    /**
     * Find an element.
     *
     * @param string $selector
     * @return string Element ID
     */
    protected function findElement(string $selector): string
    {
        // Determine selector strategy
        $strategy = 'css selector';

        if (str_starts_with($selector, '//') || str_starts_with($selector, '(//')) {
            $strategy = 'xpath';
        } elseif (str_starts_with($selector, '@')) {
            // Name attribute: @name
            $strategy = 'name';
            $selector = substr($selector, 1);
        } elseif (str_starts_with($selector, '#') && !str_contains($selector, ' ')) {
            // Simple ID selector
            $strategy = 'css selector';
        }

        return $this->driver->findElement($strategy, $selector);
    }

    /**
     * Check if element exists.
     *
     * @param string $selector
     * @return bool
     */
    public function elementExists(string $selector): bool
    {
        try {
            $this->findElement($selector);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Check if element is visible.
     *
     * @param string $selector
     * @return bool
     */
    public function isVisible(string $selector): bool
    {
        try {
            $elementId = $this->findElement($selector);
            return $this->driver->isDisplayed($elementId);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Check if element is enabled.
     *
     * @param string $selector
     * @return bool
     */
    public function isEnabled(string $selector): bool
    {
        try {
            $elementId = $this->findElement($selector);
            return $this->driver->isEnabled($elementId);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Check if checkbox/radio is selected.
     *
     * @param string $selector
     * @return bool
     */
    public function isChecked(string $selector): bool
    {
        try {
            $elementId = $this->findElement($selector);
            return $this->driver->isSelected($elementId);
        } catch (\Throwable) {
            return false;
        }
    }
}
