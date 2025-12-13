<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules;

use Toporia\Framework\Validation\Contracts\RuleInterface;

/**
 * Class Image
 *
 * Validates that the uploaded file is an image (jpeg, png, gif, bmp, svg, webp).
 *
 * Performance: O(1) - MIME type check
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Validation\Rules
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class Image implements RuleInterface
{
    /**
     * Valid image MIME types.
     */
    private const IMAGE_MIMES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/bmp',
        'image/svg+xml',
        'image/webp',
        'image/avif',
        'image/heic',
        'image/heif',
    ];

    /**
     * Determine if the validation rule passes.
     *
     * @param string $attribute The attribute name being validated
     * @param mixed $value The value being validated
     * @return bool
     */
    public function passes(string $attribute, mixed $value): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        $mimeType = $this->getMimeType($value);

        if ($mimeType === null) {
            return false;
        }

        return in_array($mimeType, self::IMAGE_MIMES, true);
    }

    /**
     * Get MIME type from file.
     *
     * @param mixed $file File value
     * @return string|null
     */
    private function getMimeType(mixed $file): ?string
    {
        // Array upload
        if (is_array($file)) {
            if (!isset($file['tmp_name']) || !is_file($file['tmp_name'])) {
                return null;
            }

            // Use finfo for reliable detection (don't trust user-provided type)
            return $this->detectMimeType($file['tmp_name']);
        }

        // File object
        if (is_object($file)) {
            // PSR-7 UploadedFileInterface
            if (method_exists($file, 'getClientMediaType')) {
                $tmpPath = $this->getFilePath($file);
                if ($tmpPath && is_file($tmpPath)) {
                    return $this->detectMimeType($tmpPath);
                }
                // Fallback to client-provided type (less secure)
                return $file->getClientMediaType();
            }

            // Symfony-style
            if (method_exists($file, 'getMimeType')) {
                return $file->getMimeType();
            }

            // SplFileInfo
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                return $this->detectMimeType($file->getPathname());
            }
        }

        // String path
        if (is_string($file) && is_file($file)) {
            return $this->detectMimeType($file);
        }

        return null;
    }

    /**
     * Detect MIME type using finfo.
     *
     * @param string $path File path
     * @return string|null
     */
    private function detectMimeType(string $path): ?string
    {
        if (!function_exists('finfo_open')) {
            // Fallback to deprecated function
            return mime_content_type($path) ?: null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }

        $mimeType = finfo_file($finfo, $path);
        finfo_close($finfo);

        return $mimeType ?: null;
    }

    /**
     * Get file path from object.
     *
     * @param object $file File object
     * @return string|null
     */
    private function getFilePath(object $file): ?string
    {
        // PSR-7 UploadedFileInterface stream
        if (method_exists($file, 'getStream')) {
            $stream = $file->getStream();
            if (method_exists($stream, 'getMetadata')) {
                $uri = $stream->getMetadata('uri');
                if (is_string($uri)) {
                    return $uri;
                }
            }
        }

        // Symfony-style
        if (method_exists($file, 'getPathname')) {
            return $file->getPathname();
        }

        if (method_exists($file, 'getRealPath')) {
            return $file->getRealPath() ?: null;
        }

        return null;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message(): string
    {
        return "The :attribute must be an image.";
    }
}
