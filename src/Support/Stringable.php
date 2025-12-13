<?php

declare(strict_types=1);

namespace Toporia\Framework\Support;

use Toporia\Framework\Support\Collection\Collection;


/**
 * Class Stringable
 *
 * Core class for the Support layer providing essential functionality for
 * the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Support
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class Stringable implements \Stringable
{
    public function __construct(
        protected string $value = ''
    ) {}

    /**
     * Get the string value.
     */
    public function toString(): string
    {
        return $this->value;
    }

    /**
     * Convert to string.
     */
    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * Get value.
     */
    public function value(): string
    {
        return $this->value;
    }

    // Fluent methods - all return new instance for immutability

    public function append(string $value): static
    {
        return new static($this->value . $value);
    }

    public function prepend(string $value): static
    {
        return new static($value . $this->value);
    }

    public function lower(): static
    {
        return new static(Str::lower($this->value));
    }

    public function upper(): static
    {
        return new static(Str::upper($this->value));
    }

    public function title(): static
    {
        return new static(Str::title($this->value));
    }

    public function camel(): static
    {
        return new static(Str::camel($this->value));
    }

    public function studly(): static
    {
        return new static(Str::studly($this->value));
    }

    public function snake(string $delimiter = '_'): static
    {
        return new static(Str::snake($this->value, $delimiter));
    }

    public function kebab(): static
    {
        return new static(Str::kebab($this->value));
    }

    public function slug(string $separator = '-'): static
    {
        return new static(Str::slug($this->value, $separator));
    }

    public function limit(int $limit = 100, string $end = '...'): static
    {
        return new static(Str::limit($this->value, $limit, $end));
    }

    public function words(int $words = 100, string $end = '...'): static
    {
        return new static(Str::words($this->value, $words, $end));
    }

    public function trim(string $characters = " \t\n\r\0\x0B"): static
    {
        return new static(trim($this->value, $characters));
    }

    public function ltrim(string $characters = " \t\n\r\0\x0B"): static
    {
        return new static(ltrim($this->value, $characters));
    }

    public function rtrim(string $characters = " \t\n\r\0\x0B"): static
    {
        return new static(rtrim($this->value, $characters));
    }

    public function replace(string $search, string $replace): static
    {
        return new static(str_replace($search, $replace, $this->value));
    }

    public function replaceFirst(string $search, string $replace): static
    {
        return new static(Str::replaceFirst($search, $replace, $this->value));
    }

    public function replaceLast(string $search, string $replace): static
    {
        return new static(Str::replaceLast($search, $replace, $this->value));
    }

    public function remove(string|array $search): static
    {
        return new static(Str::remove($search, $this->value));
    }

    public function reverse(): static
    {
        return new static(Str::reverse($this->value));
    }

    public function after(string $search): static
    {
        return new static(Str::after($this->value, $search));
    }

    public function afterLast(string $search): static
    {
        return new static(Str::afterLast($this->value, $search));
    }

    public function before(string $search): static
    {
        return new static(Str::before($this->value, $search));
    }

    public function beforeLast(string $search): static
    {
        return new static(Str::beforeLast($this->value, $search));
    }

    public function between(string $from, string $to): static
    {
        return new static(Str::between($this->value, $from, $to));
    }

    public function mask(string $character, int $index = 0, ?int $length = null): static
    {
        return new static(Str::mask($this->value, $character, $index, $length));
    }

    public function wrap(string $before, ?string $after = null): static
    {
        return new static(Str::wrap($this->value, $before, $after));
    }

    public function unwrap(string $before, ?string $after = null): static
    {
        return new static(Str::unwrap($this->value, $before, $after));
    }

    public function padBoth(int $length, string $pad = ' '): static
    {
        return new static(Str::padBoth($this->value, $length, $pad));
    }

    public function padLeft(int $length, string $pad = ' '): static
    {
        return new static(Str::padLeft($this->value, $length, $pad));
    }

    public function padRight(int $length, string $pad = ' '): static
    {
        return new static(Str::padRight($this->value, $length, $pad));
    }

    public function repeat(int $times): static
    {
        return new static(Str::repeat($this->value, $times));
    }

    public function substr(int $start, ?int $length = null): static
    {
        return new static(Str::substr($this->value, $start, $length));
    }

    public function truncateMiddle(int $length, string $separator = '...'): static
    {
        return new static(Str::truncateMiddle($this->value, $length, $separator));
    }

    // Query methods (return non-string values)

    public function length(): int
    {
        return Str::length($this->value);
    }

    public function startsWith(string|array $needles): bool
    {
        return Str::startsWith($this->value, $needles);
    }

    public function endsWith(string|array $needles): bool
    {
        return Str::endsWith($this->value, $needles);
    }

    public function contains(string|array $needles, bool $ignoreCase = false): bool
    {
        return Str::contains($this->value, $needles, $ignoreCase);
    }

    public function containsAll(array $needles, bool $ignoreCase = false): bool
    {
        return Str::containsAll($this->value, $needles, $ignoreCase);
    }

    public function is(string|array $pattern): bool
    {
        return Str::is($pattern, $this->value);
    }

    public function isJson(): bool
    {
        return Str::isJson($this->value);
    }

    public function isUrl(): bool
    {
        return Str::isUrl($this->value);
    }

    public function isEmail(): bool
    {
        return Str::isEmail($this->value);
    }

    public function isUuid(): bool
    {
        return Str::isUuid($this->value);
    }

    public function isUlid(): bool
    {
        return Str::isUlid($this->value);
    }

    public function wordCount(): int
    {
        return Str::wordCount($this->value);
    }

    public function extractWords(): array
    {
        return Str::extractWords($this->value);
    }

    public function split(string $delimiter): Collection
    {
        return Collection::make(explode($delimiter, $this->value));
    }

    public function explode(string $delimiter): Collection
    {
        return $this->split($delimiter);
    }

    /**
     * Apply callback to value.
     */
    public function pipe(callable $callback): mixed
    {
        return $callback($this->value);
    }

    /**
     * Tap into the value.
     */
    public function tap(callable $callback): static
    {
        $callback($this->value);
        return $this;
    }

    /**
     * Apply callback when condition is true.
     */
    public function when(bool $condition, callable $callback, ?callable $default = null): static
    {
        if ($condition) {
            return $callback($this) ?? $this;
        }

        if ($default !== null) {
            return $default($this) ?? $this;
        }

        return $this;
    }

    /**
     * Apply callback unless condition is true.
     */
    public function unless(bool $condition, callable $callback, ?callable $default = null): static
    {
        return $this->when(!$condition, $callback, $default);
    }

    /**
     * Dump value and continue.
     */
    public function dump(): static
    {
        var_dump($this->value);
        return $this;
    }

    /**
     * Dump value and die.
     */
    public function dd(): void
    {
        var_dump($this->value);
        exit(1);
    }
}
