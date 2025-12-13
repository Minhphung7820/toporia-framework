<?php

declare(strict_types=1);

namespace Toporia\Framework\Support;


/**
 * Class Str
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
class Str
{
    /**
     * Cache for compiled patterns.
     */
    private static array $cache = [];

    /**
     * Convert string to camelCase.
     */
    public static function camel(string $value): string
    {
        if (isset(self::$cache['camel'][$value])) {
            return self::$cache['camel'][$value];
        }

        return self::$cache['camel'][$value] = lcfirst(self::studly($value));
    }

    /**
     * Convert string to StudlyCase.
     */
    public static function studly(string $value): string
    {
        if (isset(self::$cache['studly'][$value])) {
            return self::$cache['studly'][$value];
        }

        $value = ucwords(str_replace(['-', '_'], ' ', $value));

        return self::$cache['studly'][$value] = str_replace(' ', '', $value);
    }

    /**
     * Convert string to snake_case.
     */
    public static function snake(string $value, string $delimiter = '_'): string
    {
        $key = $value;

        if (isset(self::$cache['snake'][$key][$delimiter])) {
            return self::$cache['snake'][$key][$delimiter];
        }

        if (!ctype_lower($value)) {
            $value = preg_replace('/\s+/u', '', ucwords($value));
            $value = preg_replace('/(.)(?=[A-Z])/u', '$1' . $delimiter, $value);
            $value = self::lower($value);
        }

        return self::$cache['snake'][$key][$delimiter] = $value;
    }

    /**
     * Convert string to kebab-case.
     */
    public static function kebab(string $value): string
    {
        return self::snake($value, '-');
    }

    /**
     * Convert string to Title Case.
     */
    public static function title(string $value): string
    {
        return function_exists('mb_convert_case')
            ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8')
            : ucwords(strtolower($value));
    }

    /**
     * Convert to lowercase.
     */
    public static function lower(string $value): string
    {
        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }

    /**
     * Convert to uppercase.
     */
    public static function upper(string $value): string
    {
        return function_exists('mb_strtoupper')
            ? mb_strtoupper($value, 'UTF-8')
            : strtoupper($value);
    }

    /**
     * Get string length (UTF-8 safe).
     */
    public static function length(string $value): int
    {
        return function_exists('mb_strlen')
            ? mb_strlen($value, 'UTF-8')
            : strlen($value);
    }

    /**
     * Limit string to given length.
     */
    public static function limit(string $value, int $limit = 100, string $end = '...'): string
    {
        if (self::length($value) <= $limit) {
            return $value;
        }

        return rtrim(self::substr($value, 0, $limit)) . $end;
    }

    /**
     * Limit words in string.
     */
    public static function words(string $value, int $words = 100, string $end = '...'): string
    {
        preg_match('/^\s*+(?:\S++\s*+){1,' . $words . '}/u', $value, $matches);

        if (!isset($matches[0]) || self::length($value) === self::length($matches[0])) {
            return $value;
        }

        return rtrim($matches[0]) . $end;
    }

    /**
     * Check if string starts with given substring.
     */
    public static function startsWith(string $haystack, string|array $needles): bool
    {
        foreach ((array) $needles as $needle) {
            if ($needle !== '' && str_starts_with($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if string ends with given substring.
     */
    public static function endsWith(string $haystack, string|array $needles): bool
    {
        foreach ((array) $needles as $needle) {
            if ($needle !== '' && str_ends_with($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if string contains substring.
     */
    public static function contains(string $haystack, string|array $needles, bool $ignoreCase = false): bool
    {
        if ($ignoreCase) {
            $haystack = self::lower($haystack);
        }

        foreach ((array) $needles as $needle) {
            if ($ignoreCase) {
                $needle = self::lower($needle);
            }

            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if string contains all given substrings.
     */
    public static function containsAll(string $haystack, array $needles, bool $ignoreCase = false): bool
    {
        foreach ($needles as $needle) {
            if (!self::contains($haystack, $needle, $ignoreCase)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Replace first occurrence.
     */
    public static function replaceFirst(string $search, string $replace, string $subject): string
    {
        $position = strpos($subject, $search);

        if ($position !== false) {
            return substr_replace($subject, $replace, $position, strlen($search));
        }

        return $subject;
    }

    /**
     * Replace last occurrence.
     */
    public static function replaceLast(string $search, string $replace, string $subject): string
    {
        $position = strrpos($subject, $search);

        if ($position !== false) {
            return substr_replace($subject, $replace, $position, strlen($search));
        }

        return $subject;
    }

    /**
     * Remove all occurrences of substring.
     */
    public static function remove(string|array $search, string $subject, bool $caseSensitive = true): string
    {
        return str_replace($search, '', $subject);
    }

    /**
     * Reverse string (UTF-8 safe).
     */
    public static function reverse(string $value): string
    {
        if (function_exists('mb_str_split')) {
            return implode('', array_reverse(mb_str_split($value, 1, 'UTF-8')));
        }

        return strrev($value);
    }

    /**
     * Generate random string.
     */
    public static function random(int $length = 16): string
    {
        $bytes = random_bytes($length);
        return substr(str_replace(['/', '+', '='], '', base64_encode($bytes)), 0, $length);
    }

    /**
     * Generate UUID v4.
     */
    public static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Generate ULID (Universally Unique Lexicographically Sortable Identifier).
     */
    public static function ulid(?int $timestamp = null): string
    {
        $timestamp = $timestamp ?? (int) (microtime(true) * 1000);

        $chars = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $time = '';
        $random = '';

        for ($i = 9; $i >= 0; $i--) {
            $mod = $timestamp % 32;
            $time = $chars[$mod] . $time;
            $timestamp = (int) ($timestamp / 32);
        }

        for ($i = 0; $i < 16; $i++) {
            $random .= $chars[random_int(0, 31)];
        }

        return $time . $random;
    }

    /**
     * Generate slug from string.
     */
    public static function slug(string $value, string $separator = '-'): string
    {
        $value = self::lower($value);

        // Transliterate Unicode to ASCII
        $value = self::ascii($value);

        // Remove unwanted characters
        $value = preg_replace('/[^a-z0-9\-_\s]+/', '', $value);

        // Replace spaces and multiple separators
        $value = preg_replace('/[\s\-_]+/', $separator, $value);

        return trim($value, $separator);
    }

    /**
     * Transliterate string to ASCII.
     */
    public static function ascii(string $value): string
    {
        $transliterations = [
            'ạ' => 'a', 'á' => 'a', 'à' => 'a', 'ả' => 'a', 'ã' => 'a',
            'â' => 'a', 'ậ' => 'a', 'ấ' => 'a', 'ầ' => 'a', 'ẩ' => 'a', 'ẫ' => 'a',
            'ă' => 'a', 'ặ' => 'a', 'ắ' => 'a', 'ằ' => 'a', 'ẳ' => 'a', 'ẵ' => 'a',
            'ẹ' => 'e', 'é' => 'e', 'è' => 'e', 'ẻ' => 'e', 'ẽ' => 'e',
            'ê' => 'e', 'ệ' => 'e', 'ế' => 'e', 'ề' => 'e', 'ể' => 'e', 'ễ' => 'e',
            'ị' => 'i', 'í' => 'i', 'ì' => 'i', 'ỉ' => 'i', 'ĩ' => 'i',
            'ọ' => 'o', 'ó' => 'o', 'ò' => 'o', 'ỏ' => 'o', 'õ' => 'o',
            'ô' => 'o', 'ộ' => 'o', 'ố' => 'o', 'ồ' => 'o', 'ổ' => 'o', 'ỗ' => 'o',
            'ơ' => 'o', 'ợ' => 'o', 'ớ' => 'o', 'ờ' => 'o', 'ở' => 'o', 'ỡ' => 'o',
            'ụ' => 'u', 'ú' => 'u', 'ù' => 'u', 'ủ' => 'u', 'ũ' => 'u',
            'ư' => 'u', 'ự' => 'u', 'ứ' => 'u', 'ừ' => 'u', 'ử' => 'u', 'ữ' => 'u',
            'ỵ' => 'y', 'ý' => 'y', 'ỳ' => 'y', 'ỷ' => 'y', 'ỹ' => 'y',
            'đ' => 'd',
        ];

        return strtr($value, $transliterations);
    }

    /**
     * Pad string to length.
     */
    public static function padBoth(string $value, int $length, string $pad = ' '): string
    {
        return str_pad($value, $length, $pad, STR_PAD_BOTH);
    }

    /**
     * Pad left.
     */
    public static function padLeft(string $value, int $length, string $pad = ' '): string
    {
        return str_pad($value, $length, $pad, STR_PAD_LEFT);
    }

    /**
     * Pad right.
     */
    public static function padRight(string $value, int $length, string $pad = ' '): string
    {
        return str_pad($value, $length, $pad, STR_PAD_RIGHT);
    }

    /**
     * Repeat string.
     */
    public static function repeat(string $value, int $times): string
    {
        return str_repeat($value, $times);
    }

    /**
     * After - Get substring after first occurrence.
     */
    public static function after(string $subject, string $search): string
    {
        return $search === '' ? $subject : (array_reverse(explode($search, $subject, 2))[0] ?? '');
    }

    /**
     * After last - Get substring after last occurrence.
     */
    public static function afterLast(string $subject, string $search): string
    {
        if ($search === '') {
            return $subject;
        }

        $position = strrpos($subject, $search);

        return $position === false ? $subject : substr($subject, $position + strlen($search));
    }

    /**
     * Before - Get substring before first occurrence.
     */
    public static function before(string $subject, string $search): string
    {
        return $search === '' ? $subject : explode($search, $subject)[0];
    }

    /**
     * Before last - Get substring before last occurrence.
     */
    public static function beforeLast(string $subject, string $search): string
    {
        if ($search === '') {
            return $subject;
        }

        $pos = function_exists('mb_strrpos')
            ? mb_strrpos($subject, $search)
            : strrpos($subject, $search);

        return $pos === false ? $subject : self::substr($subject, 0, $pos);
    }

    /**
     * Between - Get substring between two strings.
     */
    public static function between(string $subject, string $from, string $to): string
    {
        if ($from === '' || $to === '') {
            return $subject;
        }

        return self::beforeLast(self::after($subject, $from), $to);
    }

    /**
     * Mask string with character.
     */
    public static function mask(string $value, string $character, int $index = 0, ?int $length = null): string
    {
        if ($character === '') {
            return $value;
        }

        $segment = self::substr($value, $index, $length);

        if ($segment === '') {
            return $value;
        }

        $strlen = self::length($value);
        $startIndex = $index;

        if ($index < 0) {
            $startIndex = $index < -$strlen ? 0 : $strlen + $index;
        }

        $start = self::substr($value, 0, $startIndex);
        $segmentLen = self::length($segment);
        $end = self::substr($value, $startIndex + $segmentLen);

        return $start . str_repeat(self::substr($character, 0, 1), $segmentLen) . $end;
    }

    /**
     * Swap keywords in string.
     */
    public static function swap(array $map, string $subject): string
    {
        return strtr($subject, $map);
    }

    /**
     * Wrap string with given strings.
     */
    public static function wrap(string $value, string $before, ?string $after = null): string
    {
        return $before . $value . ($after ?? $before);
    }

    /**
     * Unwrap string.
     */
    public static function unwrap(string $value, string $before, ?string $after = null): string
    {
        if (self::startsWith($value, $before)) {
            $value = self::substr($value, self::length($before));
        }

        $after = $after ?? $before;

        if (self::endsWith($value, $after)) {
            $value = self::substr($value, 0, -self::length($after));
        }

        return $value;
    }

    /**
     * Is - Check if string matches pattern.
     */
    public static function is(string|array $pattern, string $value): bool
    {
        $patterns = (array) $pattern;

        foreach ($patterns as $pattern) {
            $pattern = preg_quote($pattern, '#');
            $pattern = str_replace('\*', '.*', $pattern);

            if (preg_match('#^' . $pattern . '\z#u', $value) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is JSON string.
     */
    public static function isJson(string $value): bool
    {
        json_decode($value);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Is URL.
     */
    public static function isUrl(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Is email.
     */
    public static function isEmail(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Is UUID.
     */
    public static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }

    /**
     * Is ULID.
     */
    public static function isUlid(string $value): bool
    {
        return preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) === 1;
    }

    // ========================================
    // ADVANCED FEATURES
    // ========================================

    /**
     * Levenshtein distance (for fuzzy matching).
     */
    public static function distance(string $str1, string $str2): int
    {
        return levenshtein($str1, $str2);
    }

    /**
     * Similarity percentage (0-100).
     */
    public static function similarity(string $str1, string $str2): float
    {
        similar_text($str1, $str2, $percent);
        return $percent;
    }

    /**
     * Soundex for phonetic matching.
     */
    public static function soundsLike(string $str1, string $str2): bool
    {
        return soundex($str1) === soundex($str2);
    }

    /**
     * Word count.
     */
    public static function wordCount(string $value): int
    {
        return str_word_count($value);
    }

    /**
     * Extract words.
     */
    public static function extractWords(string $value): array
    {
        return str_word_count($value, 1);
    }

    /**
     * Sentence count.
     */
    public static function sentenceCount(string $value): int
    {
        return preg_match_all('/[.!?]+/', $value);
    }

    /**
     * Paragraph count.
     */
    public static function paragraphCount(string $value): int
    {
        return count(array_filter(preg_split('/\n\s*\n/', $value)));
    }

    /**
     * Character frequency analysis.
     */
    public static function charFrequency(string $value): array
    {
        return count_chars($value, 1);
    }

    /**
     * Most common character.
     */
    public static function mostCommonChar(string $value): string
    {
        $freq = self::charFrequency($value);
        arsort($freq);
        return chr(array_key_first($freq));
    }

    /**
     * Compress string (gzip).
     */
    public static function compress(string $value): string
    {
        return gzcompress($value);
    }

    /**
     * Decompress string (gzip).
     */
    public static function decompress(string $value): string
    {
        return gzuncompress($value);
    }

    /**
     * Base64 encode (URL safe).
     */
    public static function base64Encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * Base64 decode (URL safe).
     */
    public static function base64Decode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/'));
    }

    /**
     * ROT13 cipher.
     */
    public static function rot13(string $value): string
    {
        return str_rot13($value);
    }

    /**
     * Get excerpt around keyword (for search results).
     */
    public static function excerpt(string $text, string $keyword, int $radius = 100): string
    {
        $pos = stripos($text, $keyword);

        if ($pos === false) {
            return self::limit($text, $radius * 2);
        }

        $start = max(0, $pos - $radius);
        $length = strlen($keyword) + ($radius * 2);

        $excerpt = self::substr($text, $start, $length);

        if ($start > 0) {
            $excerpt = '...' . ltrim($excerpt);
        }

        if ($start + $length < strlen($text)) {
            $excerpt = rtrim($excerpt) . '...';
        }

        return $excerpt;
    }

    /**
     * Highlight keywords in text.
     */
    public static function highlight(string $text, string|array $keywords, string $class = 'highlight'): string
    {
        $keywords = (array) $keywords;

        foreach ($keywords as $keyword) {
            $text = preg_replace(
                '/(' . preg_quote($keyword, '/') . ')/i',
                '<span class="' . $class . '">$1</span>',
                $text
            );
        }

        return $text;
    }

    /**
     * Truncate in the middle.
     */
    public static function truncateMiddle(string $value, int $length, string $separator = '...'): string
    {
        if (self::length($value) <= $length) {
            return $value;
        }

        $separatorLength = self::length($separator);
        $leftLength = (int) ceil(($length - $separatorLength) / 2);
        $rightLength = (int) floor(($length - $separatorLength) / 2);

        return self::substr($value, 0, $leftLength) . $separator . self::substr($value, -$rightLength);
    }

    /**
     * Safe substring (UTF-8).
     */
    public static function substr(string $string, int $start, ?int $length = null): string
    {
        return function_exists('mb_substr')
            ? mb_substr($string, $start, $length, 'UTF-8')
            : substr($string, $start, $length ?? PHP_INT_MAX);
    }

    /**
     * Parse template with variables.
     */
    public static function template(string $template, array $vars): string
    {
        return preg_replace_callback('/\{(\w+)\}/', function ($matches) use ($vars) {
            return $vars[$matches[1]] ?? $matches[0];
        }, $template);
    }

    /**
     * Create fluent string builder.
     */
    public static function of(string $value): Stringable
    {
        return new Stringable($value);
    }

    /**
     * Clear cache.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
