<?php

declare(strict_types=1);

namespace Toporia\Framework\Translation;

use Toporia\Framework\Translation\Contracts\{LoaderInterface, TranslatorInterface};

/**
 * Class Translator
 *
 * Core translation service with support for multiple locales,
 * nested keys, placeholder replacement, and pluralization.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Translation
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class Translator implements TranslatorInterface
{
    /**
     * @var array<string, array<string, mixed>> Loaded translations cache
     * Format: ['locale.namespace' => ['key' => 'value']]
     */
    private array $loaded = [];

    /**
     * @param LoaderInterface $loader Translation file loader
     * @param string $locale Default locale
     * @param string $fallback Fallback locale
     */
    public function __construct(
        private LoaderInterface $loader,
        private string $locale,
        private string $fallback = 'en'
    ) {}

    /**
     * {@inheritdoc}
     */
    public function get(string $key, array $replace = [], ?string $locale = null): string
    {
        $locale = $locale ?? $this->locale;

        // Try to get translation
        $line = $this->getLine($key, $locale, $replace);

        // If not found and locale is not fallback, try fallback
        if ($line === $key && $locale !== $this->fallback) {
            $line = $this->getLine($key, $this->fallback, $replace);
        }

        return $line;
    }

    /**
     * {@inheritdoc}
     */
    public function trans(string $key, array $replace = [], ?string $locale = null): string
    {
        return $this->get($key, $replace, $locale);
    }

    /**
     * {@inheritdoc}
     */
    public function choice(string $key, int|array $number, array $replace = [], ?string $locale = null): string
    {
        $locale = $locale ?? $this->locale;

        // If number is array, extract number and merge with replace
        if (is_array($number)) {
            $replace = array_merge($number, $replace);
            $number = $number['count'] ?? $number['number'] ?? 1;
        }

        $line = $this->get($key, $replace, $locale);

        // If line doesn't contain pluralization markers, return as-is
        if (!str_contains($line, '|')) {
            return $this->makeReplacements($line, $replace);
        }

        // Parse pluralization
        $segments = explode('|', $line);
        $segments = array_map('trim', $segments);

        // Find the correct segment based on number
        $segment = $this->extractChoice($segments, (int) $number);

        return $this->makeReplacements($segment, array_merge($replace, [':count' => $number]));
    }

    /**
     * Extract the correct choice segment based on number.
     *
     * Supports fluent pluralization:
     * - "one|many" - simple pluralization
     * - "{0} No apples|{1} One apple|[2,*] Many apples" - complex pluralization
     *
     * @param array<string> $segments Choice segments
     * @param int $number Number for pluralization
     * @return string Selected segment
     */
    private function extractChoice(array $segments, int $number): string
    {
        foreach ($segments as $segment) {
            // Check for explicit number match: {0}, {1}, etc.
            if (preg_match('/^\{(\d+)\}/', $segment, $matches)) {
                if ((int) $matches[1] === $number) {
                    return preg_replace('/^\{\d+\}\s*/', '', $segment);
                }
                continue;
            }

            // Check for range: [2,*], [2,10], etc.
            if (preg_match('/^\[(\d+),(\d+|\*)\]/', $segment, $matches)) {
                $min = (int) $matches[1];
                $max = $matches[2] === '*' ? PHP_INT_MAX : (int) $matches[2];
                if ($number >= $min && $number <= $max) {
                    return preg_replace('/^\[\d+,\d+\*?\]\s*/', '', $segment);
                }
                continue;
            }

            // Check for "one" keyword (typically for 1)
            if ($segment === 'one' || str_starts_with($segment, 'one ')) {
                if ($number === 1) {
                    return str_replace('one ', '', $segment);
                }
                continue;
            }

            // Default: use this segment (fallback)
            return $segment;
        }

        // If no match found, return last segment as fallback
        return end($segments) ?: '';
    }

    /**
     * Get translation line for a key.
     *
     * Supports dot notation for nested keys:
     * - 'messages.welcome' => ['messages' => ['welcome' => 'Hello']]
     * - 'messages.user.name' => ['messages' => ['user' => ['name' => 'John']]]
     *
     * @param string $key Translation key (dot notation)
     * @param string $locale Locale code
     * @param array<string, mixed> $replace Replacements
     * @return string Translation or key if not found
     */
    private function getLine(string $key, string $locale, array $replace = []): string
    {
        // Parse namespace and key
        [$namespace, $item] = $this->parseKey($key);

        // Load translations for this locale and namespace
        $translations = $this->load($locale, $namespace);

        // Get value using dot notation
        $line = $this->getNestedValue($translations, $item);

        // If not found, return key
        if ($line === null) {
            return $key;
        }

        // Make replacements
        return $this->makeReplacements($line, $replace);
    }

    /**
     * Parse key into namespace and item.
     *
     * Supports two formats:
     * 1. Namespace prefix: 'namespace::key' or 'namespace::nested.key'
     * 2. Dot notation: 'messages.welcome' (first part is namespace, rest is key)
     *
     * @param string $key Translation key
     * @return array{0: string, 1: string} [namespace, item]
     */
    private function parseKey(string $key): array
    {
        // Check for namespace prefix (e.g., 'namespace::key')
        if (str_contains($key, '::')) {
            [$namespace, $item] = explode('::', $key, 2);
            return [trim($namespace), trim($item)];
        }

        // Check for dot notation (e.g., 'messages.welcome')
        // First part is treated as namespace, rest as nested key
        if (str_contains($key, '.')) {
            $parts = explode('.', $key, 2);
            $namespace = $parts[0];
            $item = $parts[1] ?? '';
            return [$namespace, $item];
        }

        // No namespace, default namespace (empty string)
        // Key itself is the item
        return ['', $key];
    }

    /**
     * Get nested value from array using dot notation.
     *
     * @param array<string, mixed> $array Array to search
     * @param string $key Dot notation key (e.g., 'user.name')
     * @return mixed Value or null if not found
     */
    private function getNestedValue(array $array, string $key): mixed
    {
        if (!str_contains($key, '.')) {
            return $array[$key] ?? null;
        }

        $keys = explode('.', $key);
        $value = $array;

        foreach ($keys as $segment) {
            if (!is_array($value) || !isset($value[$segment])) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Make placeholder replacements in translation string.
     *
     * Supports multiple formats:
     * - :name - Simple placeholder
     * - :name! - Required placeholder (throws if missing)
     * - {name} - Alternative syntax
     *
     * @param string $line Translation line
     * @param array<string, mixed> $replace Replacements
     * @return string Line with replacements
     */
    private function makeReplacements(string $line, array $replace): string
    {
        if (empty($replace)) {
            return $line;
        }

        // Sort replacements by key length (longest first) to avoid partial replacements
        uksort($replace, fn($a, $b) => strlen($b) <=> strlen($a));

        foreach ($replace as $key => $value) {
            // Support :key and {key} formats
            $line = str_replace(
                [":{$key}", "{{$key}}", ":{$key}!", "{{$key}}!"],
                [(string) $value, (string) $value, (string) $value, (string) $value],
                $line
            );
        }

        return $line;
    }

    /**
     * {@inheritdoc}
     */
    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * {@inheritdoc}
     */
    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    /**
     * {@inheritdoc}
     */
    public function getFallback(): string
    {
        return $this->fallback;
    }

    /**
     * {@inheritdoc}
     */
    public function setFallback(string $locale): void
    {
        $this->fallback = $locale;
    }

    /**
     * {@inheritdoc}
     */
    public function has(string $key, ?string $locale = null): bool
    {
        $locale = $locale ?? $this->locale;
        [$namespace, $item] = $this->parseKey($key);
        $translations = $this->load($locale, $namespace);
        $value = $this->getNestedValue($translations, $item);

        // If not found in current locale, check fallback
        if ($value === null && $locale !== $this->fallback) {
            $translations = $this->load($this->fallback, $namespace);
            $value = $this->getNestedValue($translations, $item);
        }

        return $value !== null;
    }

    /**
     * {@inheritdoc}
     */
    public function load(string $locale, string $namespace): array
    {
        $cacheKey = "{$locale}.{$namespace}";

        // Check memory cache first
        if (isset($this->loaded[$cacheKey])) {
            return $this->loaded[$cacheKey];
        }

        // Load from loader
        $translations = $this->loader->load($locale, $namespace);

        // Cache in memory
        $this->loaded[$cacheKey] = $translations;

        return $translations;
    }

    /**
     * Clear loaded translations cache.
     *
     * Useful for testing or when translations are updated.
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->loaded = [];
    }
}

