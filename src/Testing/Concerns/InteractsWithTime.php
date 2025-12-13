<?php

declare(strict_types=1);

namespace Toporia\Framework\Testing\Concerns;


/**
 * Trait InteractsWithTime
 *
 * Trait providing reusable functionality for InteractsWithTime in the
 * Concerns layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Concerns
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
trait InteractsWithTime
{
    /**
     * Current fake time.
     */
    protected ?int $fakeTime = null;

    /**
     * Set fake time.
     *
     * Performance: O(1)
     */
    protected function setFakeTime(int $timestamp): void
    {
        $this->fakeTime = $timestamp;
    }

    /**
     * Travel to a specific time.
     *
     * Performance: O(1)
     */
    protected function travelTo(int $timestamp): void
    {
        $this->setFakeTime($timestamp);
    }

    /**
     * Travel forward in time.
     *
     * Performance: O(1)
     */
    protected function travel(int $seconds): void
    {
        $current = $this->fakeTime ?? \now()->getTimestamp();
        $this->setFakeTime($current + $seconds);
    }

    /**
     * Get current time (fake or real).
     *
     * Performance: O(1)
     */
    protected function now(): int
    {
        return $this->fakeTime ?? \now()->getTimestamp();
    }

    /**
     * Reset time to real time.
     *
     * Performance: O(1)
     */
    protected function resetTime(): void
    {
        $this->fakeTime = null;
    }

    /**
     * Cleanup time after test.
     */
    protected function tearDownTime(): void
    {
        $this->resetTime();
    }
}

