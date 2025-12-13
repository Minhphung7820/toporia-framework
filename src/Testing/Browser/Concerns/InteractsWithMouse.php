<?php

declare(strict_types=1);

namespace Toporia\Framework\Testing\Browser\Concerns;

/**
 * Trait InteractsWithMouse
 *
 * Provides mouse interaction methods including clicking, hovering, dragging, and drag-and-drop operations.
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
trait InteractsWithMouse
{
    /**
     * Double-click an element.
     *
     * @param string $selector
     * @return static
     */
    public function doubleClick(string $selector): static
    {
        $elementId = $this->findElement($selector);

        $this->driver->executeScript(
            'var event = new MouseEvent("dblclick", {bubbles: true, cancelable: true}); ' .
            'arguments[0].dispatchEvent(event);',
            [['element-6066-11e4-a52e-4f735466cecf' => $elementId]]
        );

        return $this;
    }

    /**
     * Right-click an element.
     *
     * @param string $selector
     * @return static
     */
    public function rightClick(string $selector): static
    {
        $elementId = $this->findElement($selector);

        $this->driver->executeScript(
            'var event = new MouseEvent("contextmenu", {bubbles: true, cancelable: true}); ' .
            'arguments[0].dispatchEvent(event);',
            [['element-6066-11e4-a52e-4f735466cecf' => $elementId]]
        );

        return $this;
    }

    /**
     * Hover over an element.
     *
     * @param string $selector
     * @return static
     */
    public function hover(string $selector): static
    {
        $elementId = $this->findElement($selector);

        $this->driver->executeScript(
            'var event = new MouseEvent("mouseover", {bubbles: true, cancelable: true}); ' .
            'arguments[0].dispatchEvent(event);',
            [['element-6066-11e4-a52e-4f735466cecf' => $elementId]]
        );

        return $this;
    }

    /**
     * Mouse enter an element.
     *
     * @param string $selector
     * @return static
     */
    public function mouseEnter(string $selector): static
    {
        $elementId = $this->findElement($selector);

        $this->driver->executeScript(
            'var event = new MouseEvent("mouseenter", {bubbles: true, cancelable: true}); ' .
            'arguments[0].dispatchEvent(event);',
            [['element-6066-11e4-a52e-4f735466cecf' => $elementId]]
        );

        return $this;
    }

    /**
     * Mouse leave an element.
     *
     * @param string $selector
     * @return static
     */
    public function mouseLeave(string $selector): static
    {
        $elementId = $this->findElement($selector);

        $this->driver->executeScript(
            'var event = new MouseEvent("mouseleave", {bubbles: true, cancelable: true}); ' .
            'arguments[0].dispatchEvent(event);',
            [['element-6066-11e4-a52e-4f735466cecf' => $elementId]]
        );

        return $this;
    }

    /**
     * Drag element to another element.
     *
     * @param string $from Source selector
     * @param string $to Target selector
     * @return static
     */
    public function drag(string $from, string $to): static
    {
        $fromId = $this->findElement($from);
        $toId = $this->findElement($to);

        $this->driver->executeScript(
            'var source = arguments[0]; var target = arguments[1]; ' .
            'var dataTransfer = new DataTransfer(); ' .
            'source.dispatchEvent(new DragEvent("dragstart", {dataTransfer: dataTransfer, bubbles: true})); ' .
            'target.dispatchEvent(new DragEvent("dragover", {dataTransfer: dataTransfer, bubbles: true})); ' .
            'target.dispatchEvent(new DragEvent("drop", {dataTransfer: dataTransfer, bubbles: true})); ' .
            'source.dispatchEvent(new DragEvent("dragend", {dataTransfer: dataTransfer, bubbles: true}));',
            [
                ['element-6066-11e4-a52e-4f735466cecf' => $fromId],
                ['element-6066-11e4-a52e-4f735466cecf' => $toId],
            ]
        );

        return $this;
    }

    /**
     * Drag element by offset.
     *
     * @param string $selector
     * @param int $xOffset
     * @param int $yOffset
     * @return static
     */
    public function dragBy(string $selector, int $xOffset, int $yOffset): static
    {
        $elementId = $this->findElement($selector);

        $this->driver->executeScript(
            'var el = arguments[0]; var rect = el.getBoundingClientRect(); ' .
            'var startX = rect.left + rect.width / 2; ' .
            'var startY = rect.top + rect.height / 2; ' .
            'var endX = startX + arguments[1]; ' .
            'var endY = startY + arguments[2]; ' .
            'el.dispatchEvent(new MouseEvent("mousedown", {clientX: startX, clientY: startY, bubbles: true})); ' .
            'document.dispatchEvent(new MouseEvent("mousemove", {clientX: endX, clientY: endY, bubbles: true})); ' .
            'document.dispatchEvent(new MouseEvent("mouseup", {clientX: endX, clientY: endY, bubbles: true}));',
            [['element-6066-11e4-a52e-4f735466cecf' => $elementId], $xOffset, $yOffset]
        );

        return $this;
    }
}
