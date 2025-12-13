<?php

declare(strict_types=1);

namespace Toporia\Framework\Support\Accessors;

use Toporia\Framework\Foundation\ServiceAccessor;
use Toporia\Framework\Security\XssService;

/**
 * Class Xss
 *
 * XSS Protection Accessor (Facade) - Static accessor for XSS protection service providing convenient API.
 * Enables static method calls for XSS protection operations.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Support\Accessors
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * Usage:
 * ```php
 * use Toporia\Framework\Support\Accessors\Xss;
 *
 * // Escape HTML (most common)
 * echo Xss::escape($user->name);
 *
 * // Clean (strip all HTML)
 * $clean = Xss::clean($userInput);
 *
 * // Sanitize (allow specific tags)
 * $html = Xss::sanitize($richText);
 *
 * // Purify for rich text editors
 * $purified = Xss::purify($editorContent);
 *
 * // Escape for JavaScript
 * $js = Xss::escapeJs($value);
 *
 * // Escape for URL
 * $url = Xss::escapeUrl($param);
 *
 * // Clean array recursively
 * $cleanArray = Xss::cleanArray($data);
 * ```
 *
 * Performance:
 * - O(1) instance resolution (cached after first call)
 * - Zero overhead method forwarding
 * - Direct delegation to static XssProtection methods
 * - Memory efficient (singleton service instance)
 *
 * Clean Architecture:
 * - ServiceAccessor pattern for dependency injection
 * - Wrapper service (XssService) for testability
 * - Static methods in XssProtection for maximum performance
 *
 * SOLID Principles:
 * - Single Responsibility: Only forwards calls to XssService
 * - Open/Closed: Extend via XssService, don't modify accessor
 * - Liskov Substitution: Behaves like other ServiceAccessors
 * - Interface Segregation: Provides specific XSS protection interface
 * - Dependency Inversion: Depends on container abstraction
 *
 * @method static string escape(?string $value, bool $doubleEncode = true)
 * @method static string clean(?string $value)
 * @method static string sanitize(?string $value, ?string $allowedTags = null, bool $basicFormatting = true)
 * @method static string purify(?string $value)
 * @method static string escapeJs(?string $value)
 * @method static string escapeUrl(?string $value)
 * @method static array cleanArray(array $data, bool $stripTags = true)
 *
 * @see \Toporia\Framework\Security\XssService
 * @see \Toporia\Framework\Security\XssProtection
 */
final class Xss extends ServiceAccessor
{
    /**
     * Get the service name in container.
     *
     * @return string
     */
    protected static function getServiceName(): string
    {
        return 'xss';
    }
}
