<?php

declare(strict_types=1);

namespace Toporia\Framework\Support\Accessors;

use Toporia\Framework\Foundation\Application;
use Toporia\Framework\Socialite\{SocialiteManager, Contracts\ProviderInterface};

/**
 * Class Socialite
 *
 * Socialite Facade - Provides static access to socialite manager.
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
 * @method static ProviderInterface driver(string $provider)
 */
final class Socialite
{
    /**
     * Get socialite manager instance.
     *
     * @return SocialiteManager
     */
    public static function getInstance(): SocialiteManager
    {
        return Application::getInstance()->get('socialite');
    }

    /**
     * Get OAuth provider driver.
     *
     * @param string $provider Provider name
     * @return ProviderInterface
     */
    public static function driver(string $provider): ProviderInterface
    {
        return static::getInstance()->driver($provider);
    }
}

