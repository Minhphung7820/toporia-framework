<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Scheduling\Support;

/**
 * Class MaintenanceMode
 *
 * Utility class for checking maintenance mode status with O(1) performance.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Console\Scheduling
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class MaintenanceMode
{
    /**
     * Check if application is in maintenance mode.
     *
     * Performance: O(1) - Single file check
     *
     * @param string|null $basePath Application base path
     * @return bool
     */
    public static function isDown(?string $basePath = null): bool
    {
        $downFile = self::getDownFile($basePath);
        return file_exists($downFile);
    }

    /**
     * Get maintenance mode file path.
     *
     * @param string|null $basePath
     * @return string
     */
    private static function getDownFile(?string $basePath): string
    {
        if ($basePath === null) {
            // Try to get from constant or fallback
            $basePath = defined('APP_BASE_PATH')
                ? constant('APP_BASE_PATH')
                : (getcwd() ?: dirname(__DIR__, 5));
        }

        $storagePath = $basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework';

        if (!is_dir($storagePath)) {
            return $storagePath . DIRECTORY_SEPARATOR . 'down';
        }

        return $storagePath . DIRECTORY_SEPARATOR . 'down';
    }
}
