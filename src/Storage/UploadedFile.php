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
     * Move uploaded file (for testing).
     *
     * @param string $destination Target path
     * @return bool
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
}
