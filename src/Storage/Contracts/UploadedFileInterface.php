<?php

declare(strict_types=1);

namespace Toporia\Framework\Storage\Contracts;


/**
 * Interface UploadedFileInterface
 *
 * Contract defining the interface for UploadedFileInterface
 * implementations in the File storage and management layer of the Toporia
 * Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Storage\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface UploadedFileInterface
{
    /**
     * Get original filename.
     *
     * @return string
     */
    public function getClientOriginalName(): string;

    /**
     * Get file extension.
     *
     * @return string
     */
    public function getClientOriginalExtension(): string;

    /**
     * Get file MIME type.
     *
     * @return string|null
     */
    public function getClientMimeType(): ?string;

    /**
     * Get file size in bytes.
     *
     * @return int
     */
    public function getSize(): int;

    /**
     * Get upload error code.
     *
     * @return int
     */
    public function getError(): int;

    /**
     * Check if upload was successful.
     *
     * @return bool
     */
    public function isValid(): bool;

    /**
     * Store uploaded file.
     *
     * @param string $path Target directory
     * @param string|null $name Custom filename (optional)
     * @param string $disk Storage disk (default, s3, etc.)
     * @return string|false Stored file path or false on failure
     */
    public function store(string $path, ?string $name = null, string $disk = 'default'): string|false;

    /**
     * Store uploaded file with public visibility.
     *
     * @param string $path Target directory
     * @param string|null $name Custom filename (optional)
     * @param string $disk Storage disk
     * @return string|false Stored file path or false on failure
     */
    public function storePublicly(string $path, ?string $name = null, string $disk = 'default'): string|false;

    /**
     * Get file contents.
     *
     * @return string
     */
    public function getContent(): string;

    /**
     * Get temporary file path.
     *
     * @return string
     */
    public function getRealPath(): string;

    /**
     * Generate hash of file contents.
     *
     * @param string $algorithm Hash algorithm (md5, sha1, sha256)
     * @return string Hash value
     */
    public function hash(string $algorithm = 'sha256'): string;
}
