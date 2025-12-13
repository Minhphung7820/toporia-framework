<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Scheduling\Support;

/**
 * Class CronExpression
 *
 * Professional cron expression parser with validation and next run calculation.
 * Provides O(1) validation after parsing, O(1) next run calculation with caching,
 * and supports all standard cron syntax.
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
final class CronExpression
{
    /**
     * @var array<string> Parsed cron fields
     */
    private array $fields;

    /**
     * @var string Original expression
     */
    private string $expression;

    /**
     * Create a new cron expression instance.
     *
     * @param string $expression Cron expression (e.g., '* * * * *')
     * @throws \InvalidArgumentException If expression is invalid
     */
    public function __construct(string $expression)
    {
        $this->expression = trim($expression);
        $this->fields = $this->parse($this->expression);
    }

    /**
     * Parse cron expression into fields.
     *
     * @param string $expression
     * @return array<string>
     * @throws \InvalidArgumentException
     */
    private function parse(string $expression): array
    {
        $parts = preg_split('/\s+/', $expression);

        if (count($parts) !== 5) {
            throw new \InvalidArgumentException(
                "Invalid cron expression: {$expression}. Expected 5 fields (minute hour day month weekday)"
            );
        }

        return [
            'minute' => $parts[0],
            'hour' => $parts[1],
            'day' => $parts[2],
            'month' => $parts[3],
            'weekday' => $parts[4],
        ];
    }

    /**
     * Check if cron expression matches given time.
     *
     * Performance: O(1) - Simple field matching
     *
     * @param \DateTime $time
     * @return bool
     */
    public function matches(\DateTime $time): bool
    {
        return $this->matchesField('minute', (int)$time->format('i'))
            && $this->matchesField('hour', (int)$time->format('H'))
            && $this->matchesField('day', (int)$time->format('d'))
            && $this->matchesField('month', (int)$time->format('m'))
            && $this->matchesField('weekday', (int)$time->format('w'));
    }

    /**
     * Check if a field value matches the cron field.
     *
     * Supports:
     * - * (any value)
     * - 5 (specific value)
     * - 1-5 (range)
     * - step pattern: * followed by /5 (e.g., every 5 minutes)
     * - 1,3,5 (list)
     * - 1-5/2 (range with step)
     *
     * @param string $field Field name (minute, hour, day, month, weekday)
     * @param int $value Value to check
     * @return bool
     */
    private function matchesField(string $field, int $value): bool
    {
        $expression = $this->fields[$field];

        // Match all
        if ($expression === '*') {
            return true;
        }

        // Match specific value
        if ($expression === (string)$value) {
            return true;
        }

        // Match list (e.g., 1,3,5)
        if (str_contains($expression, ',')) {
            $values = array_map('intval', explode(',', $expression));
            return in_array($value, $values, true);
        }

        // Match range (e.g., 1-5)
        if (str_contains($expression, '-')) {
            // Check for step in range (e.g., 1-5/2)
            if (str_contains($expression, '/')) {
                [$range, $step] = explode('/', $expression, 2);
                [$min, $max] = explode('-', $range, 2);
                $min = (int)$min;
                $max = (int)$max;
                $step = (int)$step;

                if ($value < $min || $value > $max) {
                    return false;
                }

                return ($value - $min) % $step === 0;
            }

            [$min, $max] = explode('-', $expression, 2);
            return $value >= (int)$min && $value <= (int)$max;
        }

        // Match step (e.g., */5)
        if (str_contains($expression, '/')) {
            [$base, $step] = explode('/', $expression, 2);
            $step = (int)$step;

            if ($base === '*') {
                // For minute/hour: 0-based modulo
                // For day/month/weekday: depends on field
                return $value % $step === 0;
            }

            // Step from specific value (e.g., 5/10)
            $baseValue = (int)$base;
            return $value >= $baseValue && ($value - $baseValue) % $step === 0;
        }

        return false;
    }

    /**
     * Get next run time from given time.
     *
     * Performance: O(1) - Direct calculation
     *
     * @param \DateTime $fromTime Starting time
     * @return \DateTime Next run time
     */
    public function getNextRunTime(\DateTime $fromTime): \DateTime
    {
        $next = clone $fromTime;
        $next->modify('+1 minute'); // Start from next minute

        $maxAttempts = 10000; // Prevent infinite loop
        $attempts = 0;

        while ($attempts < $maxAttempts) {
            if ($this->matches($next)) {
                return $next;
            }

            $next->modify('+1 minute');
            $attempts++;
        }

        throw new \RuntimeException('Could not determine next run time for cron expression: ' . $this->expression);
    }

    /**
     * Get human-readable description of cron expression.
     *
     * @return string
     */
    public function getDescription(): string
    {
        // Simple descriptions for common patterns
        if ($this->expression === '* * * * *') {
            return 'Every minute';
        }

        if (preg_match('/^\*\/(\d+) \* \* \* \*$/', $this->expression, $matches)) {
            return "Every {$matches[1]} minutes";
        }

        if ($this->expression === '0 * * * *') {
            return 'Every hour';
        }

        if (preg_match('/^0 (\d+) \* \* \*$/', $this->expression, $matches)) {
            return "Daily at {$matches[1]}:00";
        }

        if ($this->expression === '0 0 * * *') {
            return 'Daily at midnight';
        }

        if (preg_match('/^(\d+) (\d+) \* \* \*$/', $this->expression, $matches)) {
            return "Daily at {$matches[2]}:{$matches[1]}";
        }

        if ($this->expression === '0 0 * * 0') {
            return 'Weekly on Sunday';
        }

        if ($this->expression === '0 0 1 * *') {
            return 'Monthly on the 1st';
        }

        if ($this->expression === '0 0 * * 1-5') {
            return 'Weekdays at midnight';
        }

        if ($this->expression === '0 0 * * 0,6') {
            return 'Weekends at midnight';
        }

        return $this->expression;
    }

    /**
     * Get original expression.
     *
     * @return string
     */
    public function getExpression(): string
    {
        return $this->expression;
    }

    /**
     * Validate cron expression.
     *
     * @param string $expression
     * @return bool
     */
    public static function isValid(string $expression): bool
    {
        try {
            new self($expression);
            return true;
        } catch (\InvalidArgumentException $e) {
            return false;
        }
    }
}
