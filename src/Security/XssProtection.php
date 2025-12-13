<?php

declare(strict_types=1);

namespace Toporia\Framework\Security;

/**
 * Class XssProtection
 *
 * Provides methods to sanitize user input and prevent Cross-Site Scripting attacks.
 * Uses HTML Purifier-like approach with configurable allowed tags.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Security
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class XssProtection
{
    /**
     * Default allowed HTML tags for basic formatting
     */
    private const ALLOWED_TAGS_BASIC = '<p><br><strong><em><u><a><ul><ol><li>';

    /**
     * Allowed tags for rich text editing
     */
    private const ALLOWED_TAGS_RICH = '<p><br><strong><em><u><a><ul><ol><li><h1><h2><h3><h4><h5><h6><blockquote><code><pre><img><table><thead><tbody><tr><th><td>';

    /**
     * Escape HTML special characters
     *
     * @param string|null $value The value to escape
     * @param bool $doubleEncode Whether to encode existing HTML entities
     * @return string
     */
    public static function escape(?string $value, bool $doubleEncode = true): string
    {
        if ($value === null) {
            return '';
        }

        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8', $doubleEncode);
    }

    /**
     * Clean user input by stripping all HTML tags
     *
     * @param string|null $value The value to clean
     * @return string
     */
    public static function clean(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return strip_tags($value);
    }

    /**
     * Sanitize HTML while allowing specific tags
     *
     * @param string|null $value The HTML to sanitize
     * @param string|null $allowedTags Allowed tags (e.g., '<p><a><strong>')
     * @param bool $basicFormatting Use basic formatting tags if $allowedTags is null
     * @return string
     */
    public static function sanitize(?string $value, ?string $allowedTags = null, bool $basicFormatting = true): string
    {
        if ($value === null) {
            return '';
        }

        $allowedTags = $allowedTags ?? ($basicFormatting ? self::ALLOWED_TAGS_BASIC : self::ALLOWED_TAGS_RICH);

        // Strip unwanted tags
        $cleaned = strip_tags($value, $allowedTags);

        // Remove dangerous attributes (on*, style with expressions, etc.)
        $cleaned = self::removeDangerousAttributes($cleaned);

        return $cleaned;
    }

    /**
     * Purify HTML for rich text editors
     *
     * More permissive than sanitize() but still removes dangerous elements.
     *
     * @param string|null $value The HTML to purify
     * @return string
     */
    public static function purify(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        // Use rich text allowed tags
        $cleaned = strip_tags($value, self::ALLOWED_TAGS_RICH);

        // Remove dangerous attributes
        $cleaned = self::removeDangerousAttributes($cleaned);

        return $cleaned;
    }

    /**
     * Escape for use in JavaScript strings
     *
     * @param string|null $value The value to escape
     * @return string
     */
    public static function escapeJs(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        // Escape for JavaScript context
        return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    /**
     * Escape for use in URL parameters
     *
     * @param string|null $value The value to escape
     * @return string
     */
    public static function escapeUrl(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return rawurlencode($value);
    }

    /**
     * Remove dangerous HTML attributes
     *
     * Removes:
     * - Event handlers (onclick, onload, etc.)
     * - JavaScript in href/src
     * - Dangerous CSS (expressions, imports, etc.)
     *
     * @param string $html
     * @return string
     */
    private static function removeDangerousAttributes(string $html): string
    {
        // Remove event handlers (on*)
        $html = preg_replace('/\s*on\w+\s*=\s*["\']?[^"\']*["\']?/i', '', $html);

        // Remove javascript: protocol
        $html = preg_replace('/href\s*=\s*["\']?\s*javascript:/i', 'href="', $html);
        $html = preg_replace('/src\s*=\s*["\']?\s*javascript:/i', 'src="', $html);

        // Remove data: protocol (can be used for XSS)
        $html = preg_replace('/href\s*=\s*["\']?\s*data:/i', 'href="', $html);
        $html = preg_replace('/src\s*=\s*["\']?\s*data:/i', 'src="', $html);

        // Remove vbscript: protocol
        $html = preg_replace('/href\s*=\s*["\']?\s*vbscript:/i', 'href="', $html);

        // Remove style attributes with expressions or imports
        $html = preg_replace('/style\s*=\s*["\'][^"\']*expression[^"\']*["\']?/i', '', $html);
        $html = preg_replace('/style\s*=\s*["\'][^"\']*import[^"\']*["\']?/i', '', $html);

        return $html;
    }

    /**
     * Clean array of values recursively
     *
     * @param array $data
     * @param bool $stripTags Whether to strip all tags
     * @return array
     */
    public static function cleanArray(array $data, bool $stripTags = true): array
    {
        $cleaned = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $cleaned[$key] = self::cleanArray($value, $stripTags);
            } elseif (is_string($value)) {
                $cleaned[$key] = $stripTags ? self::clean($value) : self::escape($value);
            } else {
                $cleaned[$key] = $value;
            }
        }

        return $cleaned;
    }
}
