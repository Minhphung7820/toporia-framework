<?php

declare(strict_types=1);

namespace Toporia\Framework\Storage;

use Toporia\Framework\Storage\Contracts\UploadedFileInterface;

/**
 * Class UploadedFile
 *
 * Handles HTTP file uploads with stream-based operations, lazy loading,
 * hash caching, upload validation, and security features.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Storage
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class UploadedFile implements UploadedFileInterface
{
    private ?string $hashCache = null;
    private ?string $realMimeType = null;

    public function __construct(
        private readonly string $path,
        private readonly string $originalName,
        private readonly ?string $mimeType = null,
        private readonly ?int $error = null,
        private readonly bool $test = false
    ) {
    }

    /**
     * Create from $_FILES array.
     *
     * @param array $file $_FILES['field_name']
     * @return self
     */
    public static function createFromArray(array $file): self
    {
        return new self(
            $file['tmp_name'],
            $file['name'],
            $file['type'] ?? null,
            $file['error'] ?? UPLOAD_ERR_OK
        );
    }

    public function getClientOriginalName(): string
    {
        return $this->originalName;
    }

    public function getClientOriginalExtension(): string
    {
        return pathinfo($this->originalName, PATHINFO_EXTENSION);
    }

    public function getClientMimeType(): ?string
    {
        return $this->mimeType;
    }

    /**
     * Alias for getClientMimeType() - PSR-7 compatibility.
     *
     * @return string|null MIME type or null
     */
    public function getClientMediaType(): ?string
    {
        return $this->getClientMimeType();
    }

    /**
     * Get original filename - alias for getClientOriginalName().
     *
     * @return string Original filename
     */
    public function getClientFilename(): string
    {
        return $this->getClientOriginalName();
    }

    /**
     * Get real MIME type detected from file content (server-side).
     *
     * Security: This validates the actual file content, not just the client-provided type.
     * Uses finfo_file() to detect MIME type from file magic bytes.
     *
     * @return string|null MIME type or null if detection fails
     */
    public function getRealMimeType(): ?string
    {
        if ($this->realMimeType !== null) {
            return $this->realMimeType;
        }

        if (!$this->isValid() || !file_exists($this->path)) {
            return null;
        }

        // Use finfo_file() for server-side MIME type detection (more secure)
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $this->realMimeType = finfo_file($finfo, $this->path) ?: null;
                finfo_close($finfo);
                return $this->realMimeType;
            }
        }

        // Fallback to mime_content_type() if available
        if (function_exists('mime_content_type')) {
            $this->realMimeType = mime_content_type($this->path) ?: null;
            return $this->realMimeType;
        }

        return null;
    }

    /**
     * Validate MIME type against allowed types.
     *
     * Security: Validates both client-provided and server-detected MIME types.
     * Returns false if client MIME type doesn't match server-detected type (possible spoofing).
     *
     * @param array<string>|null $allowedMimeTypes Allowed MIME types (null = any)
     * @param bool $strict If true, also validates client MIME type matches server detection
     * @return bool True if valid, false otherwise
     */
    public function isValidMimeType(?array $allowedMimeTypes = null, bool $strict = true): bool
    {
        if (!$this->isValid()) {
            return false;
        }

        $realMimeType = $this->getRealMimeType();

        // Strict mode: Check if client-provided MIME type matches server detection
        if ($strict && $this->mimeType !== null && $realMimeType !== null) {
            if ($this->mimeType !== $realMimeType) {
                // Client MIME type doesn't match server detection (possible spoofing)
                return false;
            }
        }

        // If no whitelist, just check if we can detect MIME type
        if ($allowedMimeTypes === null) {
            return $realMimeType !== null;
        }

        // Check if real MIME type is in allowed list
        if ($realMimeType !== null) {
            return in_array($realMimeType, $allowedMimeTypes, true);
        }

        // Fallback: Check client MIME type if server detection failed
        if ($this->mimeType !== null) {
            return in_array($this->mimeType, $allowedMimeTypes, true);
        }

        return false;
    }

    /**
     * Validate file extension against allowed extensions.
     *
     * Security: Validates file extension (should be used together with MIME type validation).
     *
     * @param array<string>|null $allowedExtensions Allowed extensions (null = any)
     * @return bool True if valid, false otherwise
     */
    public function isValidExtension(?array $allowedExtensions = null): bool
    {
        if ($allowedExtensions === null) {
            return true;
        }

        $extension = strtolower($this->getClientOriginalExtension());
        if (empty($extension)) {
            return false;
        }

        // Normalize extensions (remove leading dot if present)
        $allowedExtensions = array_map(function ($ext) {
            return strtolower(ltrim($ext, '.'));
        }, $allowedExtensions);

        return in_array($extension, $allowedExtensions, true);
    }

    public function getSize(): int
    {
        return $this->isValid() ? filesize($this->path) : 0;
    }

    public function getError(): int
    {
        return $this->error ?? UPLOAD_ERR_OK;
    }

    public function isValid(): bool
    {
        $isOk = $this->error === UPLOAD_ERR_OK;
        return $this->test ? $isOk : ($isOk && is_uploaded_file($this->path));
    }

    public function store(string $path, ?string $name = null, string $disk = 'default'): string|false
    {
        if (!$this->isValid()) {
            return false;
        }

        $name = $name ?? $this->hashName();
        $targetPath = trim($path, '/') . '/' . $name;

        $storage = app('storage')->disk($disk);
        $stream = fopen($this->path, 'r');

        if ($storage->put($targetPath, $stream)) {
            fclose($stream);
            return $targetPath;
        }

        fclose($stream);
        return false;
    }

    public function storePublicly(string $path, ?string $name = null, string $disk = 'default'): string|false
    {
        if (!$this->isValid()) {
            return false;
        }

        $name = $name ?? $this->hashName();
        $targetPath = trim($path, '/') . '/' . $name;

        $storage = app('storage')->disk($disk);
        $stream = fopen($this->path, 'r');

        if ($storage->put($targetPath, $stream, ['visibility' => 'public'])) {
            fclose($stream);
            return $targetPath;
        }

        fclose($stream);
        return false;
    }

    public function getContent(): string
    {
        return $this->isValid() ? file_get_contents($this->path) : '';
    }

    public function getRealPath(): string
    {
        return realpath($this->path) ?: $this->path;
    }

    public function hash(string $algorithm = 'sha256'): string
    {
        if ($this->hashCache !== null) {
            return $this->hashCache;
        }

        if (!$this->isValid()) {
            return '';
        }

        $this->hashCache = hash_file($algorithm, $this->path);
        return $this->hashCache;
    }

    /**
     * Generate hash-based filename.
     *
     * @param string|null $extension Custom extension
     * @return string Filename like: a3f5d9e2b1c4.jpg
     */
    public function hashName(?string $extension = null): string
    {
        $extension = $extension ?? $this->getClientOriginalExtension();
        $hash = $this->hash('md5');
        return $hash . ($extension ? '.' . $extension : '');
    }

    /**
     * Move uploaded file to target path.
     *
     * @param string $destination Target path
     * @return bool Success status
     */
    public function move(string $destination): bool
    {
        if (!$this->isValid()) {
            return false;
        }

        // Create directory if needed
        $directory = dirname($destination);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        if ($this->test) {
            return rename($this->path, $destination);
        }

        return move_uploaded_file($this->path, $destination);
    }

    /**
     * Alias for move() - PSR-7 compatibility.
     *
     * @param string $targetPath Target path
     * @return void
     * @throws \RuntimeException If the file cannot be moved
     */
    public function moveTo(string $targetPath): void
    {
        if (!$this->move($targetPath)) {
            throw new \RuntimeException('Failed to move uploaded file to: ' . $targetPath);
        }
    }

    /**
     * Get temporary file path.
     *
     * @return string Temporary file path
     */
    public function getPathname(): string
    {
        return $this->path;
    }

    /**
     * Get temporary file path (alias).
     *
     * @return string Temporary file path
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Store file with custom filename generator.
     *
     * @param string $directory Target directory
     * @param callable|null $nameGenerator Custom filename generator (receives UploadedFile, returns string)
     * @param string $disk Storage disk
     * @return string|false Stored path or false on failure
     */
    public function storeAs(string $directory, string $name, string $disk = 'default'): string|false
    {
        return $this->store($directory, $name, $disk);
    }

    /**
     * Get file extension based on MIME type (more secure than client extension).
     *
     * @return string|null Extension or null if unknown
     */
    public function guessExtension(): ?string
    {
        $mimeType = $this->getRealMimeType();

        if ($mimeType === null) {
            return $this->getClientOriginalExtension() ?: null;
        }

        // Common MIME type to extension mapping
        $mimeToExt = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'image/bmp' => 'bmp',
            'image/tiff' => 'tiff',
            'application/pdf' => 'pdf',
            'application/zip' => 'zip',
            'application/x-rar-compressed' => 'rar',
            'application/x-7z-compressed' => '7z',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'text/plain' => 'txt',
            'text/csv' => 'csv',
            'text/html' => 'html',
            'application/json' => 'json',
            'application/xml' => 'xml',
            'audio/mpeg' => 'mp3',
            'audio/wav' => 'wav',
            'audio/ogg' => 'ogg',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'video/ogg' => 'ogv',
        ];

        return $mimeToExt[$mimeType] ?? $this->getClientOriginalExtension() ?: null;
    }

    /**
     * Get human readable error message.
     *
     * @return string|null Error message or null if no error
     */
    public function getErrorMessage(): ?string
    {
        return match ($this->error) {
            UPLOAD_ERR_OK => null,
            UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
            UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive specified in the HTML form',
            UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload',
            default => 'Unknown upload error',
        };
    }

    /**
     * Determine if file is an image.
     *
     * @return bool True if file is an image
     */
    public function isImage(): bool
    {
        $mimeType = $this->getRealMimeType();
        return $mimeType !== null && str_starts_with($mimeType, 'image/');
    }

    /**
     * Get image dimensions if file is an image.
     *
     * @return array{width: int, height: int}|null Dimensions or null if not an image
     */
    public function dimensions(): ?array
    {
        if (!$this->isValid() || !$this->isImage()) {
            return null;
        }

        $size = @getimagesize($this->path);
        if ($size === false) {
            return null;
        }

        return [
            'width' => $size[0],
            'height' => $size[1],
        ];
    }
}
