<?php

declare(strict_types=1);

namespace Toporia\Framework\Testing\Browser\Concerns;

/**
 * Trait InteractsWithKeyboard
 *
 * Provides keyboard interaction methods including key presses, key combinations, and special key handling.
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
trait InteractsWithKeyboard
{
    /**
     * Press a key.
     *
     * @param string $selector
     * @param string $key
     * @return static
     */
    public function keys(string $selector, string ...$keys): static
    {
        $elementId = $this->findElement($selector);

        foreach ($keys as $key) {
            $this->driver->type($elementId, $this->resolveKey($key));
        }

        return $this;
    }

    /**
     * Press Enter key.
     *
     * @param string $selector
     * @return static
     */
    public function pressEnter(string $selector): static
    {
        return $this->keys($selector, '{enter}');
    }

    /**
     * Press Tab key.
     *
     * @param string $selector
     * @return static
     */
    public function pressTab(string $selector): static
    {
        return $this->keys($selector, '{tab}');
    }

    /**
     * Press Escape key.
     *
     * @param string $selector
     * @return static
     */
    public function pressEscape(string $selector): static
    {
        return $this->keys($selector, '{escape}');
    }

    /**
     * Press key combination (e.g., Ctrl+A).
     *
     * @param string $selector
     * @param string $modifier
     * @param string $key
     * @return static
     */
    public function pressKeyCombo(string $selector, string $modifier, string $key): static
    {
        $elementId = $this->findElement($selector);

        $modifierCode = match (strtolower($modifier)) {
            'ctrl', 'control' => 'ctrlKey',
            'alt' => 'altKey',
            'shift' => 'shiftKey',
            'meta', 'cmd', 'command' => 'metaKey',
            default => 'ctrlKey',
        };

        $this->driver->executeScript(
            "var event = new KeyboardEvent('keydown', {key: arguments[1], {$modifierCode}: true, bubbles: true}); " .
            'arguments[0].dispatchEvent(event);',
            [['element-6066-11e4-a52e-4f735466cecf' => $elementId], $key]
        );

        return $this;
    }

    /**
     * Select all text (Ctrl+A).
     *
     * @param string $selector
     * @return static
     */
    public function selectAll(string $selector): static
    {
        $elementId = $this->findElement($selector);

        $this->driver->executeScript(
            'arguments[0].select();',
            [['element-6066-11e4-a52e-4f735466cecf' => $elementId]]
        );

        return $this;
    }

    /**
     * Resolve special key codes.
     *
     * @param string $key
     * @return string
     */
    protected function resolveKey(string $key): string
    {
        $specialKeys = [
            '{enter}' => "\uE007",
            '{tab}' => "\uE004",
            '{escape}' => "\uE00C",
            '{esc}' => "\uE00C",
            '{backspace}' => "\uE003",
            '{delete}' => "\uE017",
            '{up}' => "\uE013",
            '{down}' => "\uE015",
            '{left}' => "\uE012",
            '{right}' => "\uE014",
            '{home}' => "\uE011",
            '{end}' => "\uE010",
            '{pageup}' => "\uE00E",
            '{pagedown}' => "\uE00F",
            '{f1}' => "\uE031",
            '{f2}' => "\uE032",
            '{f3}' => "\uE033",
            '{f4}' => "\uE034",
            '{f5}' => "\uE035",
            '{f6}' => "\uE036",
            '{f7}' => "\uE037",
            '{f8}' => "\uE038",
            '{f9}' => "\uE039",
            '{f10}' => "\uE03A",
            '{f11}' => "\uE03B",
            '{f12}' => "\uE03C",
            '{space}' => "\uE00D",
            '{ctrl}' => "\uE009",
            '{alt}' => "\uE00A",
            '{shift}' => "\uE008",
            '{meta}' => "\uE03D",
        ];

        return $specialKeys[strtolower($key)] ?? $key;
    }
}
