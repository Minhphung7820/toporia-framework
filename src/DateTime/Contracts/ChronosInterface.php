<?php

declare(strict_types=1);

namespace Toporia\Framework\DateTime\Contracts;

use DateTimeInterface;
use DateTimeZone;


/**
 * Interface ChronosInterface
 *
 * Contract defining the interface for ChronosInterface implementations in
 * the DateTime layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  DateTime\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface ChronosInterface extends DateTimeInterface
{
    /**
     * Create a new instance from current time.
     *
     * @param DateTimeZone|string|null $timezone
     * @return static
     */
    public static function now(DateTimeZone|string|null $timezone = null): static;

    /**
     * Create a new instance from specific date/time.
     *
     * @param int|null $year
     * @param int|null $month
     * @param int|null $day
     * @param int|null $hour
     * @param int|null $minute
     * @param int|null $second
     * @param DateTimeZone|string|null $timezone
     * @return static
     */
    public static function create(
        ?int $year = null,
        ?int $month = null,
        ?int $day = null,
        ?int $hour = null,
        ?int $minute = null,
        ?int $second = null,
        DateTimeZone|string|null $timezone = null
    ): static;

    /**
     * Parse a string into a Chronos instance.
     *
     * @param string $time
     * @param DateTimeZone|string|null $timezone
     * @return static
     */
    public static function parse(string $time, DateTimeZone|string|null $timezone = null): static;

    /**
     * Create from timestamp.
     *
     * @param int $timestamp
     * @param DateTimeZone|string|null $timezone
     * @return static
     */
    public static function createFromTimestamp(int $timestamp, DateTimeZone|string|null $timezone = null): static;

    /**
     * Create from format.
     *
     * @param string $format
     * @param string $time
     * @param DateTimeZone|string|null $timezone
     * @return static
     */
    public static function createFromFormat(string $format, string $time, DateTimeZone|string|null $timezone = null): static;

    /**
     * Add duration.
     *
     * @param int $value
     * @param string $unit (years, months, weeks, days, hours, minutes, seconds)
     * @return static
     */
    public function addUnit(int $value, string $unit): static;

    /**
     * Subtract duration.
     *
     * @param int $value
     * @param string $unit
     * @return static
     */
    public function subUnit(int $value, string $unit): static;

    /**
     * Add years.
     *
     * @param int $value
     * @return static
     */
    public function addYears(int $value = 1): static;

    /**
     * Add months.
     *
     * @param int $value
     * @return static
     */
    public function addMonths(int $value = 1): static;

    /**
     * Add weeks.
     *
     * @param int $value
     * @return static
     */
    public function addWeeks(int $value = 1): static;

    /**
     * Add days.
     *
     * @param int $value
     * @return static
     */
    public function addDays(int $value = 1): static;

    /**
     * Add hours.
     *
     * @param int $value
     * @return static
     */
    public function addHours(int $value = 1): static;

    /**
     * Add minutes.
     *
     * @param int $value
     * @return static
     */
    public function addMinutes(int $value = 1): static;

    /**
     * Add seconds.
     *
     * @param int $value
     * @return static
     */
    public function addSeconds(int $value = 1): static;

    /**
     * Subtract years.
     *
     * @param int $value
     * @return static
     */
    public function subYears(int $value = 1): static;

    /**
     * Subtract months.
     *
     * @param int $value
     * @return static
     */
    public function subMonths(int $value = 1): static;

    /**
     * Subtract weeks.
     *
     * @param int $value
     * @return static
     */
    public function subWeeks(int $value = 1): static;

    /**
     * Subtract days.
     *
     * @param int $value
     * @return static
     */
    public function subDays(int $value = 1): static;

    /**
     * Subtract hours.
     *
     * @param int $value
     * @return static
     */
    public function subHours(int $value = 1): static;

    /**
     * Subtract minutes.
     *
     * @param int $value
     * @return static
     */
    public function subMinutes(int $value = 1): static;

    /**
     * Subtract seconds.
     *
     * @param int $value
     * @return static
     */
    public function subSeconds(int $value = 1): static;

    /**
     * Get start of day (00:00:00).
     *
     * @return static
     */
    public function startOfDay(): static;

    /**
     * Get end of day (23:59:59).
     *
     * @return static
     */
    public function endOfDay(): static;

    /**
     * Get start of month.
     *
     * @return static
     */
    public function startOfMonth(): static;

    /**
     * Get end of month.
     *
     * @return static
     */
    public function endOfMonth(): static;

    /**
     * Get start of year.
     *
     * @return static
     */
    public function startOfYear(): static;

    /**
     * Get end of year.
     *
     * @return static
     */
    public function endOfYear(): static;

    /**
     * Check if date is before another.
     *
     * @param ChronosInterface|DateTimeInterface|string $date
     * @return bool
     */
    public function isBefore(ChronosInterface|DateTimeInterface|string $date): bool;

    /**
     * Check if date is after another.
     *
     * @param ChronosInterface|DateTimeInterface|string $date
     * @return bool
     */
    public function isAfter(ChronosInterface|DateTimeInterface|string $date): bool;

    /**
     * Check if date is between two dates.
     *
     * @param ChronosInterface|DateTimeInterface|string $start
     * @param ChronosInterface|DateTimeInterface|string $end
     * @param bool $equal Include boundaries
     * @return bool
     */
    public function isBetween(
        ChronosInterface|DateTimeInterface|string $start,
        ChronosInterface|DateTimeInterface|string $end,
        bool $equal = true
    ): bool;

    /**
     * Check if date is today.
     *
     * @return bool
     */
    public function isToday(): bool;

    /**
     * Check if date is yesterday.
     *
     * @return bool
     */
    public function isYesterday(): bool;

    /**
     * Check if date is tomorrow.
     *
     * @return bool
     */
    public function isTomorrow(): bool;

    /**
     * Check if date is in the past.
     *
     * @return bool
     */
    public function isPast(): bool;

    /**
     * Check if date is in the future.
     *
     * @return bool
     */
    public function isFuture(): bool;

    /**
     * Check if date is a weekend (Saturday or Sunday).
     *
     * @return bool
     */
    public function isWeekend(): bool;

    /**
     * Check if date is a weekday (Monday to Friday).
     *
     * @return bool
     */
    public function isWeekday(): bool;

    /**
     * Get difference in years.
     *
     * @param ChronosInterface|DateTimeInterface|string|null $date
     * @param bool $absolute Return absolute value
     * @return int
     */
    public function diffInYears(ChronosInterface|DateTimeInterface|string|null $date = null, bool $absolute = true): int;

    /**
     * Get difference in months.
     *
     * @param ChronosInterface|DateTimeInterface|string|null $date
     * @param bool $absolute
     * @return int
     */
    public function diffInMonths(ChronosInterface|DateTimeInterface|string|null $date = null, bool $absolute = true): int;

    /**
     * Get difference in weeks.
     *
     * @param ChronosInterface|DateTimeInterface|string|null $date
     * @param bool $absolute
     * @return int
     */
    public function diffInWeeks(ChronosInterface|DateTimeInterface|string|null $date = null, bool $absolute = true): int;

    /**
     * Get difference in days.
     *
     * @param ChronosInterface|DateTimeInterface|string|null $date
     * @param bool $absolute
     * @return int
     */
    public function diffInDays(ChronosInterface|DateTimeInterface|string|null $date = null, bool $absolute = true): int;

    /**
     * Get difference in hours.
     *
     * @param ChronosInterface|DateTimeInterface|string|null $date
     * @param bool $absolute
     * @return int
     */
    public function diffInHours(ChronosInterface|DateTimeInterface|string|null $date = null, bool $absolute = true): int;

    /**
     * Get difference in minutes.
     *
     * @param ChronosInterface|DateTimeInterface|string|null $date
     * @param bool $absolute
     * @return int
     */
    public function diffInMinutes(ChronosInterface|DateTimeInterface|string|null $date = null, bool $absolute = true): int;

    /**
     * Get difference in seconds.
     *
     * @param ChronosInterface|DateTimeInterface|string|null $date
     * @param bool $absolute
     * @return int
     */
    public function diffInSeconds(ChronosInterface|DateTimeInterface|string|null $date = null, bool $absolute = true): int;

    /**
     * Get human-readable difference (e.g., "2 hours ago", "in 3 days").
     *
     * @param ChronosInterface|DateTimeInterface|string|null $date
     * @param bool $short Use short format (e.g., "2h ago" vs "2 hours ago")
     * @return string
     */
    public function diffForHumans(ChronosInterface|DateTimeInterface|string|null $date = null, bool $short = false): string;

    /**
     * Set timezone.
     *
     * @param DateTimeZone|string $timezone
     * @return static
     */
    public function setTimezone(DateTimeZone|string $timezone): static;

    /**
     * Get timezone.
     *
     * @return DateTimeZone|false
     */
    public function getTimezone(): DateTimeZone|false;

    /**
     * Convert to UTC timezone.
     *
     * @return static
     */
    public function toUtc(): static;

    /**
     * Get timestamp.
     *
     * @return int
     */
    public function getTimestamp(): int;

    /**
     * Get year.
     *
     * @return int
     */
    public function getYear(): int;

    /**
     * Get month (1-12).
     *
     * @return int
     */
    public function getMonth(): int;

    /**
     * Get day of month (1-31).
     *
     * @return int
     */
    public function getDay(): int;

    /**
     * Get hour (0-23).
     *
     * @return int
     */
    public function getHour(): int;

    /**
     * Get minute (0-59).
     *
     * @return int
     */
    public function getMinute(): int;

    /**
     * Get second (0-59).
     *
     * @return int
     */
    public function getSecond(): int;

    /**
     * Get day of week (0=Sunday, 6=Saturday).
     *
     * @return int
     */
    public function getDayOfWeek(): int;

    /**
     * Get day of year (1-366).
     *
     * @return int
     */
    public function getDayOfYear(): int;

    /**
     * Get week of year (1-53).
     *
     * @return int
     */
    public function getWeekOfYear(): int;

    /**
     * Format date/time.
     *
     * @param string $format
     * @return string
     */
    public function format(string $format): string;

    /**
     * Format to ISO 8601 (Y-m-d\TH:i:sP).
     *
     * @return string
     */
    public function toIso8601String(): string;

    /**
     * Format to date string (Y-m-d).
     *
     * @return string
     */
    public function toDateString(): string;

    /**
     * Format to time string (H:i:s).
     *
     * @return string
     */
    public function toTimeString(): string;

    /**
     * Format to datetime string (Y-m-d H:i:s).
     *
     * @return string
     */
    public function toDateTimeString(): string;

    /**
     * Format to RFC 2822 (D, d M Y H:i:s O).
     *
     * @return string
     */
    public function toRfc2822String(): string;

    /**
     * Convert to array.
     *
     * @return array
     */
    public function toArray(): array;

    /**
     * Convert to JSON string.
     *
     * @return string
     */
    public function toJson(): string;

    /**
     * Convert to string (ISO 8601).
     *
     * @return string
     */
    public function __toString(): string;

    /**
     * Clone the instance.
     *
     * @return static
     */
    public function copy(): static;
}
