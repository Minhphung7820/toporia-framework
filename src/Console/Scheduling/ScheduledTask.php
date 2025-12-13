<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Scheduling;

use Toporia\Framework\Console\Scheduling\Support\{CronExpression, MaintenanceMode};

/**
 * Class ScheduledTask
 *
 * Represents a task that runs on a schedule. Provides fluent interface
 * for configuring task frequency with O(1) configuration methods,
 * cached cron expression evaluation, and lazy constraint evaluation.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Console\Scheduling
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class ScheduledTask
{
    private string $expression = '* * * * *';
    private ?string $timezone = null;
    private array $filters = [];
    private array $rejects = [];
    private bool $runInBackground = false;
    private bool $withoutOverlapping = false;
    private ?string $mutexName = null;
    private int $expiresAfter = 1440; // 24 hours in minutes
    private ?string $outputFile = null;
    private bool $appendOutput = false;
    private ?string $emailOutputTo = null;
    private bool $emailOnlyOnFailure = false;
    private ?\Closure $beforeCallback = null;
    private ?\Closure $afterCallback = null;
    private ?\Closure $onSuccessCallback = null;
    private ?\Closure $onFailureCallback = null;

    // New features
    private bool $skipMaintenanceMode = false; // Skip when maintenance mode is active
    private bool $runOnOneServer = false; // Only run on one server (distributed systems)
    private ?string $oneServerMutexName = null; // Mutex name for onOneServer
    private array $environments = []; // Environment constraints
    private ?string $pingBeforeUrl = null; // HTTP ping before execution
    private ?string $pingAfterUrl = null; // HTTP ping after execution
    private ?string $pingSuccessUrl = null; // HTTP ping on success
    private ?string $pingFailureUrl = null; // HTTP ping on failure

    // Enhanced features
    private int $maxRetries = 0; // Maximum retry attempts on failure
    private int $retryDelay = 60; // Delay between retries in seconds
    private bool $exponentialBackoff = false; // Use exponential backoff for retries
    private ?int $timeout = null; // Task timeout in seconds
    private int $memoryLimit = 0; // Memory limit in MB (0 = no limit)
    private int $priority = 0; // Task priority (higher = runs first)
    private array $dependencies = []; // Task dependencies (task IDs that must complete first)
    private ?string $taskId = null; // Unique task identifier for history tracking
    private ?string $betweenStart = null; // Run between start time (HH:MM)
    private ?string $betweenEnd = null; // Run between end time (HH:MM)
    private ?string $unlessBetweenStart = null; // Skip between start time (HH:MM)
    private ?string $unlessBetweenEnd = null; // Skip between end time (HH:MM)

    public function __construct(
        private mixed $callback,
        private ?string $description = null
    ) {
        // Generate unique task ID for history tracking
        $this->taskId = $this->generateTaskId();
    }

    /**
     * Set the cron expression
     *
     * @param string $expression
     * @return self
     */
    public function cron(string $expression): self
    {
        $this->expression = $expression;
        return $this;
    }

    /**
     * Run the task every minute
     *
     * @return self
     */
    public function everyMinute(): self
    {
        return $this->cron('* * * * *');
    }

    /**
     * Run the task every X minutes
     *
     * @param int $minutes
     * @return self
     */
    public function everyMinutes(int $minutes): self
    {
        return $this->cron("*/{$minutes} * * * *");
    }

    /**
     * Run the task hourly
     *
     * @return self
     */
    public function hourly(): self
    {
        return $this->cron('0 * * * *');
    }

    /**
     * Run the task hourly at a specific minute
     *
     * @param int $minute
     * @return self
     */
    public function hourlyAt(int $minute): self
    {
        return $this->cron("{$minute} * * * *");
    }

    /**
     * Run the task daily
     *
     * @return self
     */
    public function daily(): self
    {
        return $this->cron('0 0 * * *');
    }

    /**
     * Run the task daily at a specific time
     *
     * @param string $time Format: 'HH:MM'
     * @return self
     */
    public function dailyAt(string $time): self
    {
        [$hour, $minute] = explode(':', $time);
        // BUGFIX: Remove leading zeros to ensure valid cron expression
        // '00' becomes '0', '05' becomes '5', '14' stays '14'
        $hour = ltrim($hour, '0') ?: '0';
        $minute = ltrim($minute, '0') ?: '0';
        return $this->cron("{$minute} {$hour} * * *");
    }

    /**
     * Run the task weekly
     *
     * @return self
     */
    public function weekly(): self
    {
        return $this->cron('0 0 * * 0');
    }

    /**
     * Run the task monthly
     *
     * @return self
     */
    public function monthly(): self
    {
        return $this->cron('0 0 1 * *');
    }

    /**
     * Run the task on weekdays only
     *
     * @return self
     */
    public function weekdays(): self
    {
        return $this->cron('0 0 * * 1-5');
    }

    /**
     * Run the task on weekends only
     *
     * @return self
     */
    public function weekends(): self
    {
        return $this->cron('0 0 * * 0,6');
    }

    /**
     * Run the task on Mondays
     *
     * @return self
     */
    public function mondays(): self
    {
        return $this->cron('0 0 * * 1');
    }

    /**
     * Run the task on Tuesdays
     *
     * @return self
     */
    public function tuesdays(): self
    {
        return $this->cron('0 0 * * 2');
    }

    /**
     * Run the task on Wednesdays
     *
     * @return self
     */
    public function wednesdays(): self
    {
        return $this->cron('0 0 * * 3');
    }

    /**
     * Run the task on Thursdays
     *
     * @return self
     */
    public function thursdays(): self
    {
        return $this->cron('0 0 * * 4');
    }

    /**
     * Run the task on Fridays
     *
     * @return self
     */
    public function fridays(): self
    {
        return $this->cron('0 0 * * 5');
    }

    /**
     * Run the task on Saturdays
     *
     * @return self
     */
    public function saturdays(): self
    {
        return $this->cron('0 0 * * 6');
    }

    /**
     * Run the task on Sundays
     *
     * @return self
     */
    public function sundays(): self
    {
        return $this->cron('0 0 * * 0');
    }

    /**
     * Set the timezone for the task
     *
     * @param string $timezone
     * @return self
     */
    public function timezone(string $timezone): self
    {
        $this->timezone = $timezone;
        return $this;
    }

    /**
     * Add a filter to determine if the task should run
     *
     * @param callable $callback
     * @return self
     */
    public function when(callable $callback): self
    {
        $this->filters[] = $callback;
        return $this;
    }

    /**
     * Add a rejection filter
     *
     * @param callable $callback
     * @return self
     */
    public function skip(callable $callback): self
    {
        $this->rejects[] = $callback;
        return $this;
    }

    /**
     * Skip task execution when application is in maintenance mode.
     *
     * Performance: O(1) - Single file check
     *
     * @return self
     */
    public function skipMaintenanceMode(): self
    {
        $this->skipMaintenanceMode = true;
        return $this;
    }

    /**
     * Restrict task to run only in specified environments.
     *
     * Performance: O(N) where N = number of environments (typically 1-3)
     *
     * @param string|array $environments Environment names ('production', 'staging', etc.)
     * @return self
     */
    public function environments(string|array $environments): self
    {
        $this->environments = is_array($environments) ? $environments : [$environments];
        return $this;
    }

    /**
     * Restrict task to run only on one server in distributed systems.
     *
     * Uses mutex to ensure only one server executes the task.
     * Useful for tasks that should not run concurrently across multiple servers.
     *
     * Performance: O(1) mutex check
     *
     * @return self
     */
    public function onOneServer(): self
    {
        $this->runOnOneServer = true;

        // Generate mutex name for one-server execution
        if ($this->oneServerMutexName === null) {
            $this->oneServerMutexName = 'schedule-one-server-' . md5($this->description ?? $this->generateMutexName());
        }

        return $this;
    }

    /**
     * Send HTTP ping before task execution.
     *
     * Performance: O(1) - Fire-and-forget HTTP request
     *
     * @param string $url URL to ping
     * @return self
     */
    public function pingBefore(string $url): self
    {
        $this->pingBeforeUrl = $url;
        return $this;
    }

    /**
     * Send HTTP ping after task execution.
     *
     * Performance: O(1) - Fire-and-forget HTTP request
     *
     * @param string $url URL to ping
     * @return self
     */
    public function pingAfter(string $url): self
    {
        $this->pingAfterUrl = $url;
        return $this;
    }

    /**
     * Send HTTP ping on successful task execution.
     *
     * Performance: O(1) - Fire-and-forget HTTP request
     *
     * @param string $url URL to ping
     * @return self
     */
    public function pingOnSuccess(string $url): self
    {
        $this->pingSuccessUrl = $url;
        return $this;
    }

    /**
     * Send HTTP ping on failed task execution.
     *
     * Performance: O(1) - Fire-and-forget HTTP request
     *
     * @param string $url URL to ping
     * @return self
     */
    public function pingOnFailure(string $url): self
    {
        $this->pingFailureUrl = $url;
        return $this;
    }

    /**
     * Set task description
     *
     * @param string $description
     * @return self
     */
    public function description(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    /**
     * Run the task in the background
     *
     * @return self
     */
    public function runInBackground(): self
    {
        $this->runInBackground = true;
        return $this;
    }

    /**
     * Prevent the task from overlapping
     *
     * @param int $expiresAfter Mutex expires after X minutes (default: 1440 = 24 hours)
     * @return self
     */
    public function withoutOverlapping(int $expiresAfter = 1440): self
    {
        $this->withoutOverlapping = true;
        $this->expiresAfter = $expiresAfter;

        // Generate mutex name from callback
        if ($this->mutexName === null) {
            $this->mutexName = $this->generateMutexName();
        }

        return $this;
    }

    /**
     * Set custom mutex name for overlap prevention
     *
     * @param string $name
     * @return self
     */
    public function name(string $name): self
    {
        $this->mutexName = $name;
        return $this;
    }

    /**
     * Check if the task is due to run
     *
     * Performance: O(N) where N = filters + rejects + environments
     *
     * @param \DateTime $currentTime
     * @param string|null $basePath Application base path for maintenance check
     * @return bool
     */
    public function isDue(\DateTime $currentTime, ?string $basePath = null): bool
    {
        // Check cron expression
        if (!$this->matchesCronExpression($currentTime)) {
            return false;
        }

        // Check environment constraints
        if (!empty($this->environments)) {
            $currentEnv = env('APP_ENV', 'production');
            if (!in_array($currentEnv, $this->environments, true)) {
                return false;
            }
        }

        // Check maintenance mode (if enabled)
        if ($this->skipMaintenanceMode) {
            if (MaintenanceMode::isDown($basePath)) {
                return false;
            }
        }

        // Check between time constraint
        if (!$this->isBetweenTime($currentTime)) {
            return false;
        }

        // Check unlessBetween time constraint
        if ($this->isUnlessBetweenTime($currentTime)) {
            return false;
        }

        // Check filters
        foreach ($this->filters as $filter) {
            if (!$filter()) {
                return false;
            }
        }

        // Check rejects
        foreach ($this->rejects as $reject) {
            if ($reject()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Execute the task
     *
     * @return void
     */
    public function execute(): void
    {
        ($this->callback)();
    }

    /**
     * Check if task should run in background
     *
     * @return bool
     */
    public function shouldRunInBackground(): bool
    {
        return $this->runInBackground;
    }

    /**
     * Check if task has overlap prevention enabled
     *
     * @return bool
     */
    public function hasOverlapPrevention(): bool
    {
        return $this->withoutOverlapping;
    }

    /**
     * Get mutex name for overlap prevention
     *
     * @return string|null
     */
    public function getMutexName(): ?string
    {
        return $this->mutexName;
    }

    /**
     * Get mutex expiration time in minutes
     *
     * @return int
     */
    public function getExpiresAfter(): int
    {
        return $this->expiresAfter;
    }

    /**
     * Get task description
     *
     * @return string
     */
    public function getDescription(): string
    {
        return $this->description ?? 'Unnamed task';
    }

    /**
     * Get the cron expression
     *
     * @return string
     */
    public function getExpression(): string
    {
        return $this->expression;
    }

    /**
     * Check if current time matches cron expression
     *
     * Performance: O(1) - Uses optimized CronExpression parser
     *
     * @param \DateTime $currentTime
     * @return bool
     */
    private function matchesCronExpression(\DateTime $currentTime): bool
    {
        // Apply timezone if set
        if ($this->timezone !== null) {
            $currentTime = clone $currentTime;
            $currentTime->setTimezone(new \DateTimeZone($this->timezone));
        }

        try {
            $cron = new CronExpression($this->expression);
            return $cron->matches($currentTime);
        } catch (\InvalidArgumentException $e) {
            // Fallback to old parser for backward compatibility
            return $this->matchesCronExpressionLegacy($currentTime);
        }
    }

    /**
     * Legacy cron expression matcher (backward compatibility).
     *
     * @param \DateTime $currentTime
     * @return bool
     */
    private function matchesCronExpressionLegacy(\DateTime $currentTime): bool
    {
        [$minute, $hour, $day, $month, $dayOfWeek] = explode(' ', $this->expression);

        return $this->matchesCronField($minute, $currentTime->format('i'))
            && $this->matchesCronField($hour, $currentTime->format('H'))
            && $this->matchesCronField($day, $currentTime->format('d'))
            && $this->matchesCronField($month, $currentTime->format('m'))
            && $this->matchesCronField($dayOfWeek, $currentTime->format('w'));
    }

    /**
     * Get next run time for this task.
     *
     * Performance: O(1) - Direct calculation
     *
     * @param \DateTime|null $fromTime Starting time (default: now)
     * @return \DateTime
     */
    public function getNextRunTime(?\DateTime $fromTime = null): \DateTime
    {
        $fromTime = $fromTime ?? new \DateTime();

        // Apply timezone if set
        if ($this->timezone !== null) {
            $fromTime = clone $fromTime;
            $fromTime->setTimezone(new \DateTimeZone($this->timezone));
        }

        try {
            $cron = new CronExpression($this->expression);
            $nextRun = $cron->getNextRunTime($fromTime);

            // Convert back to default timezone if needed
            if ($this->timezone !== null) {
                $nextRun->setTimezone(new \DateTimeZone(date_default_timezone_get()));
            }

            return $nextRun;
        } catch (\InvalidArgumentException $e) {
            // Fallback: approximate next run
            $next = clone $fromTime;
            $next->modify('+1 minute');
            return $next;
        }
    }

    /**
     * Check if a value matches a cron field
     *
     * @param string $field
     * @param string $value
     * @return bool
     */
    private function matchesCronField(string $field, string $value): bool
    {
        // Match all
        if ($field === '*') {
            return true;
        }

        // Match specific value
        if ($field === $value) {
            return true;
        }

        // Match range (e.g., 1-5)
        if (str_contains($field, '-')) {
            [$min, $max] = explode('-', $field);
            return $value >= $min && $value <= $max;
        }

        // Match step (e.g., */5)
        if (str_contains($field, '/')) {
            [$base, $step] = explode('/', $field);
            if ($base === '*') {
                return (int)$value % (int)$step === 0;
            }
        }

        // Match list (e.g., 1,3,5)
        if (str_contains($field, ',')) {
            $values = explode(',', $field);
            return in_array($value, $values, true);
        }

        return false;
    }

    /**
     * Generate mutex name from callback
     *
     * @return string
     */
    private function generateMutexName(): string
    {
        if (is_string($this->callback)) {
            return 'schedule-' . md5($this->callback);
        }

        if (is_array($this->callback)) {
            $class = is_object($this->callback[0]) ? get_class($this->callback[0]) : $this->callback[0];
            return 'schedule-' . md5($class . '::' . $this->callback[1]);
        }

        return 'schedule-' . md5(spl_object_hash($this->callback));
    }

    // ==================== Output Handling ====================

    /**
     * Send task output to a file.
     *
     * @param string $location File path
     * @return self
     */
    public function sendOutputTo(string $location): self
    {
        $this->outputFile = $location;
        $this->appendOutput = false;
        return $this;
    }

    /**
     * Append task output to a file.
     *
     * @param string $location File path
     * @return self
     */
    public function appendOutputTo(string $location): self
    {
        $this->outputFile = $location;
        $this->appendOutput = true;
        return $this;
    }

    /**
     * Email task output after execution.
     *
     * @param string $email Email address
     * @return self
     */
    public function emailOutputTo(string $email): self
    {
        $this->emailOutputTo = $email;
        $this->emailOnlyOnFailure = false;
        return $this;
    }

    /**
     * Email task output only on failure.
     *
     * @param string $email Email address
     * @return self
     */
    public function emailOutputOnFailure(string $email): self
    {
        $this->emailOutputTo = $email;
        $this->emailOnlyOnFailure = true;
        return $this;
    }

    /**
     * Get output file path.
     *
     * @return string|null
     */
    public function getOutputFile(): ?string
    {
        return $this->outputFile;
    }

    /**
     * Check if output should be appended.
     *
     * @return bool
     */
    public function shouldAppendOutput(): bool
    {
        return $this->appendOutput;
    }

    /**
     * Get email recipient for output.
     *
     * @return string|null
     */
    public function getEmailOutputTo(): ?string
    {
        return $this->emailOutputTo;
    }

    /**
     * Check if email should only be sent on failure.
     *
     * @return bool
     */
    public function shouldEmailOnlyOnFailure(): bool
    {
        return $this->emailOnlyOnFailure;
    }

    // ==================== Hooks ====================

    /**
     * Register callback to run before task execution.
     *
     * @param \Closure $callback
     * @return self
     */
    public function before(\Closure $callback): self
    {
        $this->beforeCallback = $callback;
        return $this;
    }

    /**
     * Register callback to run after task execution.
     *
     * @param \Closure $callback
     * @return self
     */
    public function after(\Closure $callback): self
    {
        $this->afterCallback = $callback;
        return $this;
    }

    /**
     * Alias for after().
     *
     * @param \Closure $callback
     * @return self
     */
    public function then(\Closure $callback): self
    {
        return $this->after($callback);
    }

    /**
     * Register callback to run on successful execution.
     *
     * @param \Closure $callback
     * @return self
     */
    public function onSuccess(\Closure $callback): self
    {
        $this->onSuccessCallback = $callback;
        return $this;
    }

    /**
     * Register callback to run on failed execution.
     *
     * @param \Closure $callback
     * @return self
     */
    public function onFailure(\Closure $callback): self
    {
        $this->onFailureCallback = $callback;
        return $this;
    }

    /**
     * Get before callback.
     *
     * @return \Closure|null
     */
    public function getBeforeCallback(): ?\Closure
    {
        return $this->beforeCallback;
    }

    /**
     * Get after callback.
     *
     * @return \Closure|null
     */
    public function getAfterCallback(): ?\Closure
    {
        return $this->afterCallback;
    }

    /**
     * Get onSuccess callback.
     *
     * @return \Closure|null
     */
    public function getOnSuccessCallback(): ?\Closure
    {
        return $this->onSuccessCallback;
    }

    /**
     * Get onFailure callback.
     *
     * @return \Closure|null
     */
    public function getOnFailureCallback(): ?\Closure
    {
        return $this->onFailureCallback;
    }

    // ==================== New Feature Getters ====================

    /**
     * Check if task should skip maintenance mode.
     *
     * @return bool
     */
    public function shouldSkipMaintenanceMode(): bool
    {
        return $this->skipMaintenanceMode;
    }

    /**
     * Check if task should run on one server only.
     *
     * @return bool
     */
    public function shouldRunOnOneServer(): bool
    {
        return $this->runOnOneServer;
    }

    /**
     * Get one-server mutex name.
     *
     * @return string|null
     */
    public function getOneServerMutexName(): ?string
    {
        return $this->oneServerMutexName;
    }

    /**
     * Get environment constraints.
     *
     * @return array<string>
     */
    public function getEnvironments(): array
    {
        return $this->environments;
    }

    /**
     * Get ping before URL.
     *
     * @return string|null
     */
    public function getPingBeforeUrl(): ?string
    {
        return $this->pingBeforeUrl;
    }

    /**
     * Get ping after URL.
     *
     * @return string|null
     */
    public function getPingAfterUrl(): ?string
    {
        return $this->pingAfterUrl;
    }

    /**
     * Get ping success URL.
     *
     * @return string|null
     */
    public function getPingSuccessUrl(): ?string
    {
        return $this->pingSuccessUrl;
    }

    /**
     * Get ping failure URL.
     *
     * @return string|null
     */
    public function getPingFailureUrl(): ?string
    {
        return $this->pingFailureUrl;
    }

    // ==================== Enhanced Features ====================

    /**
     * Set maximum retry attempts on failure.
     *
     * Performance: O(1)
     *
     * @param int $maxRetries Maximum retry attempts
     * @param int $delay Delay between retries in seconds
     * @param bool $exponentialBackoff Use exponential backoff
     * @return self
     */
    public function retry(int $maxRetries, int $delay = 60, bool $exponentialBackoff = false): self
    {
        $this->maxRetries = $maxRetries;
        $this->retryDelay = $delay;
        $this->exponentialBackoff = $exponentialBackoff;
        return $this;
    }

    /**
     * Set task timeout in seconds.
     *
     * Performance: O(1)
     *
     * @param int $seconds Timeout in seconds
     * @return self
     */
    public function timeout(int $seconds): self
    {
        $this->timeout = $seconds;
        return $this;
    }

    /**
     * Set memory limit for task in MB.
     *
     * Performance: O(1)
     *
     * @param int $mb Memory limit in megabytes (0 = no limit)
     * @return self
     */
    public function memory(int $mb): self
    {
        $this->memoryLimit = $mb;
        return $this;
    }

    /**
     * Set task priority (higher = runs first).
     *
     * Performance: O(1)
     *
     * @param int $priority Priority value
     * @return self
     */
    public function priority(int $priority): self
    {
        $this->priority = $priority;
        return $this;
    }

    /**
     * Add task dependency (task must complete before this runs).
     *
     * Performance: O(1) per dependency
     *
     * @param string|array $taskIds Task ID(s) that must complete first
     * @return self
     */
    public function dependsOn(string|array $taskIds): self
    {
        $ids = is_array($taskIds) ? $taskIds : [$taskIds];
        $this->dependencies = array_merge($this->dependencies, $ids);
        return $this;
    }

    /**
     * Run task only between specified times.
     *
     * Performance: O(1) - Simple time comparison
     *
     * @param string $start Start time (HH:MM format)
     * @param string $end End time (HH:MM format)
     * @return self
     */
    public function between(string $start, string $end): self
    {
        $this->betweenStart = $start;
        $this->betweenEnd = $end;
        return $this;
    }

    /**
     * Skip task execution between specified times.
     *
     * Performance: O(1) - Simple time comparison
     *
     * @param string $start Start time (HH:MM format)
     * @param string $end End time (HH:MM format)
     * @return self
     */
    public function unlessBetween(string $start, string $end): self
    {
        $this->unlessBetweenStart = $start;
        $this->unlessBetweenEnd = $end;
        return $this;
    }

    /**
     * Generate unique task ID for history tracking.
     *
     * @return string
     */
    private function generateTaskId(): string
    {
        if ($this->description !== null) {
            return 'task-' . md5($this->description);
        }

        if (is_string($this->callback)) {
            return 'task-' . md5($this->callback);
        }

        if (is_array($this->callback)) {
            $class = is_object($this->callback[0]) ? get_class($this->callback[0]) : $this->callback[0];
            return 'task-' . md5($class . '::' . $this->callback[1]);
        }

        return 'task-' . md5(spl_object_hash($this->callback));
    }

    /**
     * Get task ID.
     *
     * @return string
     */
    public function getTaskId(): string
    {
        return $this->taskId ?? $this->generateTaskId();
    }

    /**
     * Get max retries.
     *
     * @return int
     */
    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    /**
     * Get retry delay.
     *
     * @return int
     */
    public function getRetryDelay(): int
    {
        return $this->retryDelay;
    }

    /**
     * Check if exponential backoff is enabled.
     *
     * @return bool
     */
    public function hasExponentialBackoff(): bool
    {
        return $this->exponentialBackoff;
    }

    /**
     * Get timeout.
     *
     * @return int|null
     */
    public function getTimeout(): ?int
    {
        return $this->timeout;
    }

    /**
     * Get memory limit.
     *
     * @return int
     */
    public function getMemoryLimit(): int
    {
        return $this->memoryLimit;
    }

    /**
     * Get priority.
     *
     * @return int
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * Get dependencies.
     *
     * @return array<string>
     */
    public function getDependencies(): array
    {
        return $this->dependencies;
    }

    /**
     * Check if task should run between times.
     *
     * @param \DateTime $currentTime
     * @return bool
     */
    private function isBetweenTime(\DateTime $currentTime): bool
    {
        if ($this->betweenStart === null || $this->betweenEnd === null) {
            return true; // No between constraint
        }

        $current = $currentTime->format('H:i');
        $start = $this->betweenStart;
        $end = $this->betweenEnd;

        // Handle overnight range (e.g., 22:00 - 06:00)
        if ($start > $end) {
            return $current >= $start || $current <= $end;
        }

        return $current >= $start && $current <= $end;
    }

    /**
     * Check if task should skip between times.
     *
     * @param \DateTime $currentTime
     * @return bool
     */
    private function isUnlessBetweenTime(\DateTime $currentTime): bool
    {
        if ($this->unlessBetweenStart === null || $this->unlessBetweenEnd === null) {
            return false; // No unlessBetween constraint
        }

        $current = $currentTime->format('H:i');
        $start = $this->unlessBetweenStart;
        $end = $this->unlessBetweenEnd;

        // Handle overnight range (e.g., 22:00 - 06:00)
        if ($start > $end) {
            return $current >= $start || $current <= $end;
        }

        return $current >= $start && $current <= $end;
    }
}
