<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Brokers\CircuitBreaker;

/**
 * Class CircuitBreaker
 *
 * Circuit breaker pattern implementation for broker fault tolerance.
 * Prevents cascade failures and enables automatic recovery.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Realtime\Brokers\CircuitBreaker
 * @since       2025-12-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class CircuitBreaker
{
    private CircuitBreakerState $state = CircuitBreakerState::CLOSED;
    private int $failureCount = 0;
    private int $successCount = 0;
    private int $lastFailureTime = 0;
    private int $lastStateChangeTime;

    /**
     * @param string $name Circuit breaker name
     * @param int $failureThreshold Number of failures before opening circuit
     * @param int $successThreshold Number of successes before closing circuit
     * @param int $timeout Seconds before attempting recovery
     * @param int $halfOpenMaxAttempts Max attempts in half-open state
     */
    public function __construct(
        private readonly string $name,
        private readonly int $failureThreshold = 5,
        private readonly int $successThreshold = 2,
        private readonly int $timeout = 60,
        private readonly int $halfOpenMaxAttempts = 10
    ) {
        $this->lastStateChangeTime = time();
    }

    /**
     * Execute action with circuit breaker protection.
     *
     * @param callable $action Action to execute
     * @return mixed Action result
     * @throws \RuntimeException If circuit is open
     * @throws \Throwable If action fails
     */
    public function call(callable $action): mixed
    {
        $this->updateState();

        if ($this->state === CircuitBreakerState::OPEN) {
            throw new \RuntimeException(
                "Circuit breaker '{$this->name}' is OPEN. Service is unavailable."
            );
        }

        try {
            $result = $action();
            $this->recordSuccess();
            return $result;
        } catch (\Throwable $e) {
            $this->recordFailure();
            throw $e;
        }
    }

    /**
     * Update circuit breaker state based on timeout and attempts.
     *
     * @return void
     */
    private function updateState(): void
    {
        $now = time();

        match ($this->state) {
            CircuitBreakerState::OPEN => $this->handleOpenState($now),
            CircuitBreakerState::HALF_OPEN => $this->handleHalfOpenState($now),
            CircuitBreakerState::CLOSED => null,
        };
    }

    /**
     * Handle OPEN state logic.
     *
     * @param int $now Current timestamp
     * @return void
     */
    private function handleOpenState(int $now): void
    {
        // Check if timeout expired
        if (($now - $this->lastStateChangeTime) >= $this->timeout) {
            $this->transitionTo(CircuitBreakerState::HALF_OPEN, $now);
            $this->successCount = 0;
            $this->failureCount = 0;

            error_log("Circuit breaker '{$this->name}': OPEN → HALF_OPEN (testing recovery)");
        }
    }

    /**
     * Handle HALF_OPEN state logic.
     *
     * @param int $now Current timestamp
     * @return void
     */
    private function handleHalfOpenState(int $now): void
    {
        // Limit attempts in half-open state
        $totalAttempts = $this->successCount + $this->failureCount;
        if ($totalAttempts >= $this->halfOpenMaxAttempts) {
            // Too many attempts, go back to OPEN
            $this->transitionTo(CircuitBreakerState::OPEN, $now);
            $this->successCount = 0;
            $this->failureCount = 0;

            error_log("Circuit breaker '{$this->name}': HALF_OPEN → OPEN (max attempts exceeded)");
        }
    }

    /**
     * Record successful action execution.
     *
     * @return void
     */
    private function recordSuccess(): void
    {
        $this->successCount++;

        if ($this->state === CircuitBreakerState::HALF_OPEN) {
            if ($this->successCount >= $this->successThreshold) {
                // Service recovered
                $this->transitionTo(CircuitBreakerState::CLOSED, time());
                $this->successCount = 0;
                $this->failureCount = 0;

                error_log("Circuit breaker '{$this->name}': HALF_OPEN → CLOSED (recovered)");
            }
        }

        // Reset failure count on success in CLOSED state
        if ($this->state === CircuitBreakerState::CLOSED) {
            $this->failureCount = 0;
        }
    }

    /**
     * Record failed action execution.
     *
     * @return void
     */
    private function recordFailure(): void
    {
        $this->failureCount++;
        $this->lastFailureTime = time();

        if ($this->state === CircuitBreakerState::CLOSED) {
            if ($this->failureCount >= $this->failureThreshold) {
                // Too many failures, open circuit
                $this->transitionTo(CircuitBreakerState::OPEN, time());

                error_log("Circuit breaker '{$this->name}': CLOSED → OPEN (threshold exceeded)");
            }
        } elseif ($this->state === CircuitBreakerState::HALF_OPEN) {
            // Any failure in half-open goes back to OPEN
            $this->transitionTo(CircuitBreakerState::OPEN, time());
            $this->successCount = 0;
            $this->failureCount = 0;

            error_log("Circuit breaker '{$this->name}': HALF_OPEN → OPEN (failure during recovery test)");
        }
    }

    /**
     * Transition to new state.
     *
     * @param CircuitBreakerState $newState
     * @param int $now Current timestamp
     * @return void
     */
    private function transitionTo(CircuitBreakerState $newState, int $now): void
    {
        $this->state = $newState;
        $this->lastStateChangeTime = $now;
    }

    /**
     * Get current circuit breaker state.
     *
     * @return CircuitBreakerState
     */
    public function getState(): CircuitBreakerState
    {
        return $this->state;
    }

    /**
     * Get circuit breaker statistics.
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        return [
            'name' => $this->name,
            'state' => $this->state->value,
            'failure_count' => $this->failureCount,
            'success_count' => $this->successCount,
            'last_failure_time' => $this->lastFailureTime,
            'last_state_change' => $this->lastStateChangeTime,
            'uptime_seconds' => time() - $this->lastStateChangeTime,
        ];
    }

    /**
     * Reset circuit breaker to initial state.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->state = CircuitBreakerState::CLOSED;
        $this->failureCount = 0;
        $this->successCount = 0;
        $this->lastFailureTime = 0;
        $this->lastStateChangeTime = time();

        error_log("Circuit breaker '{$this->name}' manually reset");
    }

    /**
     * Check if circuit is open.
     *
     * @return bool
     */
    public function isOpen(): bool
    {
        $this->updateState();
        return $this->state === CircuitBreakerState::OPEN;
    }

    /**
     * Check if circuit is closed.
     *
     * @return bool
     */
    public function isClosed(): bool
    {
        $this->updateState();
        return $this->state === CircuitBreakerState::CLOSED;
    }
}
