<?php

declare(strict_types=1);

namespace Toporia\Framework\DateTime;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Toporia\Framework\DateTime\Contracts\ChronosInterface;

/**
 * Class Chronos
 *
 * Immutable date/time manipulation following value object pattern.
 * Similar to Carbon but with Clean Architecture principles.
 *
 * Features:
 * - Immutable operations (returns new instances)
 * - Fluent API for date manipulation
 * - Human-readable formatting
 * - Timezone handling
 * - Comparison methods
 * - Performance optimized with caching
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  DateTime
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class Chronos extends DateTimeImmutable implements ChronosInterface
{
    /**
     * Days of week constants.
     */
    public const SUNDAY = 0;
    public const MONDAY = 1;
    public const TUESDAY = 2;
    public const WEDNESDAY = 3;
    public const THURSDAY = 4;
    public const FRIDAY = 5;
    public const SATURDAY = 6;

    /**
     * Common date formats.
     */
    public const ISO8601 = 'Y-m-d\TH:i:sP';
    public const RFC2822 = 'D, d M Y H:i:s O';
    public const DATE_FORMAT = 'Y-m-d';
    public const TIME_FORMAT = 'H:i:s';
    public const DATETIME_FORMAT = 'Y-m-d H:i:s';

    /**
     * Create instance from now.
     *
     * @param DateTimeZone|string|null $timezone
     * @return static
     */
    public static function now(DateTimeZone|string|null $timezone = null): static
    {
        return new static('now', self::parseTimezone($timezone ?? self::getDefaultTimezone()));
    }

    /**
     * Create instance from specific date/time.
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
    ): static {
        $now = new DateTime('now', self::parseTimezone($timezone));

        $year = $year ?? (int) $now->format('Y');
        $month = $month ?? (int) $now->format('m');
        $day = $day ?? (int) $now->format('d');
        $hour = $hour ?? 0;
        $minute = $minute ?? 0;
        $second = $second ?? 0;

        return new static(
            sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second),
            self::parseTimezone($timezone)
        );
    }

    /**
     * Parse string into Chronos instance.
     *
     * @param string $time
     * @param DateTimeZone|string|null $timezone
     * @return static
     */
    public static function parse(string $time, DateTimeZone|string|null $timezone = null): static
    {
        return new static($time, self::parseTimezone($timezone));
    }

    /**
     * Create from timestamp.
     *
     * @param int $timestamp
     * @param DateTimeZone|string|null $timezone
     * @return static
     */
    public static function createFromTimestamp(int $timestamp, DateTimeZone|string|null $timezone = null): static
    {
        return static::parse('@' . $timestamp)->setTimezone(self::parseTimezone($timezone) ?? new DateTimeZone(date_default_timezone_get()));
    }

    /**
     * Create from format.
     *
     * @param string $format
     * @param string $time
     * @param DateTimeZone|string|null $timezone
     * @return static
     */
    public static function createFromFormat(string $format, string $time, DateTimeZone|string|null $timezone = null): static
    {
        $dt = parent::createFromFormat($format, $time, self::parseTimezone($timezone));

        if ($dt === false) {
            throw new \InvalidArgumentException("Failed to parse time string: {$time}");
        }

        return static::parse($dt->format('Y-m-d H:i:s'), $dt->getTimezone());
    }

    /**
     * Add duration.
     *
     * @param int $value
     * @param string $unit
     * @return static
     */
    public function addUnit(int $value, string $unit): static
    {
        return match ($unit) {
            'years', 'year' => $this->addYears($value),
            'months', 'month' => $this->addMonths($value),
            'weeks', 'week' => $this->addWeeks($value),
            'days', 'day' => $this->addDays($value),
            'hours', 'hour' => $this->addHours($value),
            'minutes', 'minute' => $this->addMinutes($value),
            'seconds', 'second' => $this->addSeconds($value),
            default => throw new \InvalidArgumentException("Unknown unit: {$unit}"),
        };
    }

    /**
     * Subtract duration.
     *
     * @param int $value
     * @param string $unit
     * @return static
     */
    public function subUnit(int $value, string $unit): static
    {
        return $this->addUnit(-$value, $unit);
    }

    /**
     * Add years.
     *
     * @param int $value
     * @return static
     */
    public function addYears(int $value = 1): static
    {
        return $this->modify(sprintf('%+d years', $value));
    }

    /**
     * Add months.
     *
     * @param int $value
     * @return static
     */
    public function addMonths(int $value = 1): static
    {
        return $this->modify(sprintf('%+d months', $value));
    }

    /**
     * Add weeks.
     *
     * @param int $value
     * @return static
     */
    public function addWeeks(int $value = 1): static
    {
        return $this->modify(sprintf('%+d weeks', $value));
    }

    /**
     * Add days.
     *
     * @param int $value
     * @return static
     */
    public function addDays(int $value = 1): static
    {
        return $this->modify(sprintf('%+d days', $value));
    }

    /**
     * Add hours.
     *
     * @param int $value
     * @return static
     */
    public function addHours(int $value = 1): static
    {
        return $this->modify(sprintf('%+d hours', $value));
    }

    /**
     * Add minutes.
     *
     * @param int $value
     * @return static
     */
    public function addMinutes(int $value = 1): static
    {
        return $this->modify(sprintf('%+d minutes', $value));
    }

    /**
     * Add seconds.
     *
     * @param int $value
     * @return static
     */
    public function addSeconds(int $value = 1): static
    {
        return $this->modify(sprintf('%+d seconds', $value));
    }

    /**
     * Subtract years.
     *
     * @param int $value
     * @return static
     */
    public function subYears(int $value = 1): static
    {
        return $this->addYears(-$value);
    }

    /**
     * Subtract months.
     *
     * @param int $value
     * @return static
     */
    public function subMonths(int $value = 1): static
    {
        return $this->addMonths(-$value);
    }

    /**
     * Subtract weeks.
     *
     * @param int $value
     * @return static
     */
    public function subWeeks(int $value = 1): static
    {
        return $this->addWeeks(-$value);
    }

    /**
     * Subtract days.
     *
     * @param int $value
     * @return static
     */
    public function subDays(int $value = 1): static
    {
        return $this->addDays(-$value);
    }

    /**
     * Subtract hours.
     *
     * @param int $value
     * @return static
     */
    public function subHours(int $value = 1): static
    {
        return $this->addHours(-$value);
    }

    /**
     * Subtract minutes.
     *
     * @param int $value
     * @return static
     */
    public function subMinutes(int $value = 1): static
    {
        return $this->addMinutes(-$value);
    }

    /**
     * Subtract seconds.
     *
     * @param int $value
     * @return static
     */
    public function subSeconds(int $value = 1): static
    {
        return $this->addSeconds(-$value);
    }

    /**
     * Get start of day (00:00:00).
     *
     * @return static
     */
    public function startOfDay(): static
    {
        return $this->setTime(0, 0, 0);
    }

    /**
     * Get end of day (23:59:59).
     *
     * @return static
     */
    public function endOfDay(): static
    {
        return $this->setTime(23, 59, 59);
    }

    /**
     * Get start of month.
     *
     * @return static
     */
    public function startOfMonth(): static
    {
        return $this->modify('first day of this month')->startOfDay();
    }

    /**
     * Get end of month.
     *
     * @return static
     */
    public function endOfMonth(): static
    {
        return $this->modify('last day of this month')->endOfDay();
    }

    /**
     * Get start of year.
     *
     * @return static
     */
    public function startOfYear(): static
    {
        return $this->modify('first day of January ' . $this->format('Y'))->startOfDay();
    }

    /**
     * Get end of year.
     *
     * @return static
     */
    public function endOfYear(): static
    {
        return $this->modify('last day of December ' . $this->format('Y'))->endOfDay();
    }

    /**
     * Check if date is before another.
     *
     * @param ChronosInterface|DateTimeInterface|string $date
     * @return bool
     */
    public function isBefore(ChronosInterface|DateTimeInterface|string $date): bool
    {
        return $this < self::resolveDate($date);
    }

    /**
     * Check if date is after another.
     *
     * @param ChronosInterface|DateTimeInterface|string $date
     * @return bool
     */
    public function isAfter(ChronosInterface|DateTimeInterface|string $date): bool
    {
        return $this > self::resolveDate($date);
    }

    /**
     * Check if date is between two dates.
     *
     * @param ChronosInterface|DateTimeInterface|string $start
     * @param ChronosInterface|DateTimeInterface|string $end
     * @param bool $equal
     * @return bool
     */
    public function isBetween(
        ChronosInterface|DateTimeInterface|string $start,
        ChronosInterface|DateTimeInterface|string $end,
        bool $equal = true
    ): bool {
        $startDate = self::resolveDate($start);
        $endDate = self::resolveDate($end);

        if ($equal) {
            return $this >= $startDate && $this <= $endDate;
        }

        return $this > $startDate && $this < $endDate;
    }

    /**
     * Check if date is today.
     *
     * @return bool
     */
    public function isToday(): bool
    {
        return $this->toDateString() === static::now($this->getTimezone())->toDateString();
    }

    /**
     * Check if date is yesterday.
     *
     * @return bool
     */
    public function isYesterday(): bool
    {
        return $this->toDateString() === static::now($this->getTimezone())->subDays(1)->toDateString();
    }

    /**
     * Check if date is tomorrow.
     *
     * @return bool
     */
    public function isTomorrow(): bool
    {
        return $this->toDateString() === static::now($this->getTimezone())->addDays(1)->toDateString();
    }

    /**
     * Check if date is in the past.
     *
     * @return bool
     */
    public function isPast(): bool
    {
        return $this < static::now($this->getTimezone());
    }

    /**
     * Check if date is in the future.
     *
     * @return bool
     */
    public function isFuture(): bool
    {
        return $this > static::now($this->getTimezone());
    }

    /**
     * Check if date is a weekend.
     *
     * @return bool
     */
    public function isWeekend(): bool
    {
        $day = (int) $this->format('w');
        return $day === self::SATURDAY || $day === self::SUNDAY;
    }

    /**
     * Check if date is a weekday.
     *
     * @return bool
     */
    public function isWeekday(): bool
    {
        return !$this->isWeekend();
    }

    /**
     * Get difference in years.
     *
     * @param ChronosInterface|DateTimeInterface|string|null $date
     * @param bool $absolute
     * @return int
     */
    public function diffInYears(ChronosInterface|DateTimeInterface|string|null $date = null, bool $absolute = true): int
    {
        $date = $date ? self::resolveDate($date) : static::now($this->getTimezone());
        $diff = $this->diff($date, $absolute);
        return (int) $diff->format('%y');
    }

    /**
     * Get difference in months.
     *
     * @param ChronosInterface|DateTimeInterface|string|null $date
     * @param bool $absolute
     * @return int
     */
    public function diffInMonths(ChronosInterface|DateTimeInterface|string|null $date = null, bool $absolute = true): int
    {
        $date = $date ? self::resolveDate($date) : static::now($this->getTimezone());
        $diff = $this->diff($date, $absolute);
        return (int) ($diff->format('%y') * 12 + $diff->format('%m'));
    }

    /**
     * Get difference in weeks.
     *
     * @param ChronosInterface|DateTimeInterface|string|null $date
     * @param bool $absolute
     * @return int
     */
    public function diffInWeeks(ChronosInterface|DateTimeInterface|string|null $date = null, bool $absolute = true): int
    {
        return (int) floor($this->diffInDays($date, $absolute) / 7);
    }

    /**
     * Get difference in days.
     *
     * @param ChronosInterface|DateTimeInterface|string|null $date
     * @param bool $absolute
     * @return int
     */
    public function diffInDays(ChronosInterface|DateTimeInterface|string|null $date = null, bool $absolute = true): int
    {
        $date = $date ? self::resolveDate($date) : static::now($this->getTimezone());
        $diff = $this->diff($date, $absolute);
        return (int) $diff->format('%a');
    }

    /**
     * Get difference in hours.
     *
     * @param ChronosInterface|DateTimeInterface|string|null $date
     * @param bool $absolute
     * @return int
     */
    public function diffInHours(ChronosInterface|DateTimeInterface|string|null $date = null, bool $absolute = true): int
    {
        return (int) floor($this->diffInMinutes($date, $absolute) / 60);
    }

    /**
     * Get difference in minutes.
     *
     * @param ChronosInterface|DateTimeInterface|string|null $date
     * @param bool $absolute
     * @return int
     */
    public function diffInMinutes(ChronosInterface|DateTimeInterface|string|null $date = null, bool $absolute = true): int
    {
        return (int) floor($this->diffInSeconds($date, $absolute) / 60);
    }

    /**
     * Get difference in seconds.
     *
     * @param ChronosInterface|DateTimeInterface|string|null $date
     * @param bool $absolute
     * @return int
     */
    public function diffInSeconds(ChronosInterface|DateTimeInterface|string|null $date = null, bool $absolute = true): int
    {
        $date = $date ? self::resolveDate($date) : static::now($this->getTimezone());
        $diff = $date->getTimestamp() - $this->getTimestamp();
        return $absolute ? abs($diff) : $diff;
    }

    /**
     * Get human-readable difference.
     *
     * @param ChronosInterface|DateTimeInterface|string|null $date
     * @param bool $short
     * @return string
     */
    public function diffForHumans(ChronosInterface|DateTimeInterface|string|null $date = null, bool $short = false): string
    {
        $now = $date ? self::resolveDate($date) : static::now($this->getTimezone());
        $isPast = $this < $now;

        $seconds = abs($this->diffInSeconds($now, false));
        $minutes = abs($this->diffInMinutes($now, false));
        $hours = abs($this->diffInHours($now, false));
        $days = abs($this->diffInDays($now, false));
        $weeks = abs($this->diffInWeeks($now, false));
        $months = abs($this->diffInMonths($now, false));
        $years = abs($this->diffInYears($now, false));

        [$value, $unit] = match (true) {
            $seconds < 60 => [$seconds, $short ? 's' : ($seconds === 1 ? 'second' : 'seconds')],
            $minutes < 60 => [$minutes, $short ? 'm' : ($minutes === 1 ? 'minute' : 'minutes')],
            $hours < 24 => [$hours, $short ? 'h' : ($hours === 1 ? 'hour' : 'hours')],
            $days < 7 => [$days, $short ? 'd' : ($days === 1 ? 'day' : 'days')],
            $weeks < 4 => [$weeks, $short ? 'w' : ($weeks === 1 ? 'week' : 'weeks')],
            $months < 12 => [$months, $short ? 'mo' : ($months === 1 ? 'month' : 'months')],
            default => [$years, $short ? 'y' : ($years === 1 ? 'year' : 'years')],
        };

        if ($seconds < 10) {
            return 'just now';
        }

        $formatted = $short ? "{$value}{$unit}" : "{$value} {$unit}";

        return $isPast ? "{$formatted} ago" : "in {$formatted}";
    }

    /**
     * Set timezone.
     *
     * @param DateTimeZone|string $timezone
     * @return static
     */
    public function setTimezone(DateTimeZone|string $timezone): static
    {
        return parent::setTimezone(self::parseTimezone($timezone) ?? new DateTimeZone(date_default_timezone_get()));
    }

    /**
     * Convert to UTC timezone.
     *
     * @return static
     */
    public function toUtc(): static
    {
        return $this->setTimezone('UTC');
    }

    /**
     * Get year.
     *
     * @return int
     */
    public function getYear(): int
    {
        return (int) $this->format('Y');
    }

    /**
     * Get month.
     *
     * @return int
     */
    public function getMonth(): int
    {
        return (int) $this->format('m');
    }

    /**
     * Get day.
     *
     * @return int
     */
    public function getDay(): int
    {
        return (int) $this->format('d');
    }

    /**
     * Get hour.
     *
     * @return int
     */
    public function getHour(): int
    {
        return (int) $this->format('H');
    }

    /**
     * Get minute.
     *
     * @return int
     */
    public function getMinute(): int
    {
        return (int) $this->format('i');
    }

    /**
     * Get second.
     *
     * @return int
     */
    public function getSecond(): int
    {
        return (int) $this->format('s');
    }

    /**
     * Get day of week.
     *
     * @return int
     */
    public function getDayOfWeek(): int
    {
        return (int) $this->format('w');
    }

    /**
     * Get day of year.
     *
     * @return int
     */
    public function getDayOfYear(): int
    {
        return (int) $this->format('z') + 1;
    }

    /**
     * Get week of year.
     *
     * @return int
     */
    public function getWeekOfYear(): int
    {
        return (int) $this->format('W');
    }

    /**
     * Format to ISO 8601.
     *
     * @return string
     */
    public function toIso8601String(): string
    {
        return $this->format(self::ISO8601);
    }

    /**
     * Format to date string.
     *
     * @return string
     */
    public function toDateString(): string
    {
        return $this->format(self::DATE_FORMAT);
    }

    /**
     * Format to time string.
     *
     * @return string
     */
    public function toTimeString(): string
    {
        return $this->format(self::TIME_FORMAT);
    }

    /**
     * Format to datetime string.
     *
     * @return string
     */
    public function toDateTimeString(): string
    {
        return $this->format(self::DATETIME_FORMAT);
    }

    /**
     * Format to RFC 2822.
     *
     * @return string
     */
    public function toRfc2822String(): string
    {
        return $this->format(self::RFC2822);
    }

    /**
     * Convert to array.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'year' => $this->getYear(),
            'month' => $this->getMonth(),
            'day' => $this->getDay(),
            'hour' => $this->getHour(),
            'minute' => $this->getMinute(),
            'second' => $this->getSecond(),
            'dayOfWeek' => $this->getDayOfWeek(),
            'dayOfYear' => $this->getDayOfYear(),
            'weekOfYear' => $this->getWeekOfYear(),
            'timestamp' => $this->getTimestamp(),
            'timezone' => $this->getTimezone()->getName(),
            'formatted' => $this->toDateTimeString(),
        ];
    }

    /**
     * Convert to JSON.
     *
     * @return string
     */
    public function toJson(): string
    {
        return json_encode($this->toIso8601String());
    }

    /**
     * Convert to string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->toDateTimeString();
    }

    /**
     * Clone the instance.
     *
     * @return static
     */
    public function copy(): static
    {
        return clone $this;
    }

    /**
     * Parse timezone.
     *
     * @param DateTimeZone|string|null $timezone
     * @return DateTimeZone|null
     */
    private static function parseTimezone(DateTimeZone|string|null $timezone): ?DateTimeZone
    {
        if ($timezone === null) {
            return null;
        }

        if ($timezone instanceof DateTimeZone) {
            return $timezone;
        }

        return new DateTimeZone($timezone);
    }

    /**
     * Resolve date from various types.
     *
     * @param ChronosInterface|DateTimeInterface|string $date
     * @return DateTimeInterface
     */
    private static function resolveDate(ChronosInterface|DateTimeInterface|string $date): DateTimeInterface
    {
        if ($date instanceof DateTimeInterface) {
            return $date;
        }

        return static::parse($date);
    }

    /**
     * Get default timezone from config.
     *
     * @return string
     */
    private static function getDefaultTimezone(): string
    {
        // Try to get from app container
        if (function_exists('app')) {
            try {
                $config = app('config');
                $timezone = $config->get('app.timezone');
                if ($timezone) {
                    return $timezone;
                }
            } catch (\Throwable $e) {
                // Fallback if container not available
            }
        }

        // Fallback to PHP's default timezone
        return date_default_timezone_get();
    }
}
