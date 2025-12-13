<?php

declare(strict_types=1);

namespace Toporia\Framework\Testing\Concerns;


/**
 * Trait InteractsWithEvents
 *
 * Trait providing reusable functionality for InteractsWithEvents in the
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
trait InteractsWithEvents
{
    /**
     * Fired events.
     *
     * @var array<string, array>
     */
    protected array $firedEvents = [];

    /**
     * Fake events (disable real dispatching).
     */
    protected bool $fakeEvents = false;

    /**
     * Fake events.
     *
     * Performance: O(1)
     */
    protected function fakeEvents(): void
    {
        $this->fakeEvents = true;
        $this->firedEvents = [];
    }

    /**
     * Assert that an event was fired.
     *
     * Performance: O(N) where N = number of events
     */
    protected function assertEventFired(string $event, array $payload = null): void
    {
        $found = false;

        foreach ($this->firedEvents as $fired) {
            if ($fired['event'] === $event) {
                if ($payload === null || $fired['payload'] === $payload) {
                    $found = true;
                    break;
                }
            }
        }

        $this->assertTrue($found, "Event {$event} was not fired");
    }

    /**
     * Assert that an event was not fired.
     *
     * Performance: O(N) where N = number of events
     */
    protected function assertEventNotFired(string $event): void
    {
        $found = false;

        foreach ($this->firedEvents as $fired) {
            if ($fired['event'] === $event) {
                $found = true;
                break;
            }
        }

        $this->assertFalse($found, "Event {$event} was unexpectedly fired");
    }

    /**
     * Record a fired event.
     *
     * Performance: O(1)
     */
    protected function recordEvent(string $event, array $payload = []): void
    {
        $this->firedEvents[] = [
            'event' => $event,
            'payload' => $payload,
        ];
    }

    /**
     * Cleanup events after test.
     */
    protected function tearDownEvents(): void
    {
        $this->firedEvents = [];
        $this->fakeEvents = false;
    }
}
