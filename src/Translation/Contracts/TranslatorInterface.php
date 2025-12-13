<?php

declare(strict_types=1);

namespace Toporia\Framework\Translation\Contracts;


/**
 * Interface TranslatorInterface
 *
 * Contract defining the interface for TranslatorInterface implementations
 * in the Multi-language support layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Translation\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface TranslatorInterface
{
    /**
     * Translate the given message.
     *
     * @param string $key Translation key (e.g., 'messages.welcome' or 'messages.welcome :name')
     * @param array<string, mixed> $replace Replacements for placeholders (e.g., [':name' => 'John'])
     * @param string|null $locale Target locale (null = use current locale)
     * @return string Translated message or key if not found
     */
    public function get(string $key, array $replace = [], ?string $locale = null): string;

    /**
     * Translate the given message (alias for get).
     *
     * @param string $key Translation key
     * @param array<string, mixed> $replace Replacements
     * @param string|null $locale Target locale
     * @return string Translated message
     */
    public function trans(string $key, array $replace = [], ?string $locale = null): string;

    /**
     * Translate the given message with pluralization.
     *
     * @param string $key Translation key
     * @param int|array<string, mixed> $number Number for pluralization or replacements array
     * @param array<string, mixed> $replace Replacements
     * @param string|null $locale Target locale
     * @return string Translated message
     *
     * @example
     * trans_choice('messages.apples', 5, [':name' => 'John'])
     * // Returns: "John has 5 apples" (if number >= 2)
     */
    public function choice(string $key, int|array $number, array $replace = [], ?string $locale = null): string;

    /**
     * Get the current locale.
     *
     * @return string Current locale code (e.g., 'en', 'vi')
     */
    public function getLocale(): string;

    /**
     * Set the current locale.
     *
     * @param string $locale Locale code
     * @return void
     */
    public function setLocale(string $locale): void;

    /**
     * Get the fallback locale.
     *
     * @return string Fallback locale code
     */
    public function getFallback(): string;

    /**
     * Set the fallback locale.
     *
     * @param string $locale Fallback locale code
     * @return void
     */
    public function setFallback(string $locale): void;

    /**
     * Check if a translation exists for the given key.
     *
     * @param string $key Translation key
     * @param string|null $locale Target locale
     * @return bool True if translation exists
     */
    public function has(string $key, ?string $locale = null): bool;

    /**
     * Load translations for a given locale and namespace.
     *
     * @param string $locale Locale code
     * @param string $namespace Namespace (e.g., 'messages', 'validation')
     * @return array<string, mixed> Translation array
     */
    public function load(string $locale, string $namespace): array;
}
