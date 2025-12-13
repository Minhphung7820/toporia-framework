<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules;

use Toporia\Framework\Validation\Contracts\RuleInterface;

/**
 * Class Mimes
 *
 * Validates that the file has one of the allowed MIME types.
 *
 * Performance: O(n) where n = number of allowed extensions
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
final class Mimes implements RuleInterface
{
    /**
     * Extension to MIME type mapping.
     */
    private const EXTENSION_MIMES = [
        // Images
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'bmp' => ['image/bmp'],
        'svg' => ['image/svg+xml'],
        'webp' => ['image/webp'],
        'avif' => ['image/avif'],
        'ico' => ['image/x-icon', 'image/vnd.microsoft.icon'],

        // Documents
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xls' => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'ppt' => ['application/vnd.ms-powerpoint'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        'odt' => ['application/vnd.oasis.opendocument.text'],
        'ods' => ['application/vnd.oasis.opendocument.spreadsheet'],
        'odp' => ['application/vnd.oasis.opendocument.presentation'],

        // Text
        'txt' => ['text/plain'],
        'csv' => ['text/csv', 'text/plain'],
        'json' => ['application/json', 'text/json'],
        'xml' => ['application/xml', 'text/xml'],
        'html' => ['text/html'],
        'htm' => ['text/html'],
        'md' => ['text/markdown', 'text/plain'],

        // Archives
        'zip' => ['application/zip', 'application/x-zip-compressed'],
        'rar' => ['application/vnd.rar', 'application/x-rar-compressed'],
        '7z' => ['application/x-7z-compressed'],
        'tar' => ['application/x-tar'],
        'gz' => ['application/gzip'],

        // Audio
        'mp3' => ['audio/mpeg'],
        'wav' => ['audio/wav', 'audio/x-wav'],
        'ogg' => ['audio/ogg'],
        'flac' => ['audio/flac'],
        'aac' => ['audio/aac'],
        'm4a' => ['audio/mp4', 'audio/x-m4a'],

        // Video
        'mp4' => ['video/mp4'],
        'avi' => ['video/x-msvideo'],
        'mov' => ['video/quicktime'],
        'wmv' => ['video/x-ms-wmv'],
        'flv' => ['video/x-flv'],
        'webm' => ['video/webm'],
        'mkv' => ['video/x-matroska'],

        // Code
        'js' => ['application/javascript', 'text/javascript'],
        'css' => ['text/css'],
        'php' => ['text/x-php', 'application/x-php'],
        'sql' => ['application/sql', 'text/plain'],
    ];

    /**
     * @var array<string> Allowed extensions
     */
    private readonly array $allowedExtensions;

    /**
     * @param string|array<string> $extensions Allowed file extensions
     */
    public function __construct(string|array $extensions)
    {
        $this->allowedExtensions = is_string($extensions)
            ? array_map('trim', explode(',', $extensions))
            : $extensions;
    }

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

        // Check if MIME type matches any allowed extension
        foreach ($this->allowedExtensions as $extension) {
            $extension = strtolower(trim($extension));
            $allowedMimes = self::EXTENSION_MIMES[$extension] ?? [];

            if (in_array($mimeType, $allowedMimes, true)) {
                return true;
            }
        }

        return false;
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
            return $this->detectMimeType($file['tmp_name']);
        }

        // File object
        if (is_object($file)) {
            if (method_exists($file, 'getMimeType')) {
                return $file->getMimeType();
            }

            if ($file instanceof \SplFileInfo && $file->isFile()) {
                return $this->detectMimeType($file->getPathname());
            }
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
     * Get the validation error message.
     *
     * @return string
     */
    public function message(): string
    {
        return "The :attribute must be a file of type: " . implode(', ', $this->allowedExtensions) . ".";
    }
}
