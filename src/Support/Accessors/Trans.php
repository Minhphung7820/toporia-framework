<?php

declare(strict_types=1);

namespace Toporia\Framework\Support\Accessors;

use Toporia\Framework\Foundation\ServiceAccessor;

/**
 * Class Trans
 *
 * Translation Facade - Provides static access to translation service.
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
 * - Trans::get('messages.welcome', [':name' => 'John'])
 * - Trans::trans('messages.welcome')
 * - Trans::choice('messages.apples', 5)
 * - Trans::getLocale()
 * - Trans::setLocale('vi')
 *
 * Performance:
 * - O(1) instance lookup (cached after first call)
 * - Direct method forwarding (no overhead)
 *
 * Clean Architecture:
 * - Presentation layer convenience (Facade pattern)
 * - Delegates to TranslatorInterface in Framework layer
 */
final class Trans extends ServiceAccessor
{
    /**
     * Get the service name for this accessor.
     *
     * This is the only method needed - all other methods are automatically
     * delegated to the underlying service via __callStatic().
     *
     * @return string Service name in container
     */
    protected static function getServiceName(): string
    {
        return 'translation';
    }
}
