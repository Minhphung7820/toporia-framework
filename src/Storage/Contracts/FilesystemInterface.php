<?php

declare(strict_types=1);

namespace Toporia\Framework\Storage\Contracts;


/**
 * Interface FilesystemInterface
 *
 * Contract defining the interface for FilesystemInterface implementations
 * in the File storage and management layer of the Toporia Framework.
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
interface FilesystemInterface
{
    /**
     * Store a file.
     *
     * @param string $path Target path
     * @param string|resource $contents File contents or stream
     * @param array $options Driver-specific options (visibility, metadata, etc.)
     * @return bool Success status
     */
    public function put(string $path, mixed $contents, array $options = []): bool;

    /**
     * Get file contents.
     *
     * @param string $path File path
     * @return string|null File contents or null if not found
     */
    public function get(string $path): ?string;

    /**
     * Get file as a stream.
     *
     * @param string $path File path
     * @return resource|null Stream resource or null if not found
     */
    public function readStream(string $path);

    /**
     * Check if file exists.
     *
     * @param string $path File path
     * @return bool
     */
    public function exists(string $path): bool;

    /**
     * Delete a file.
     *
     * @param string|array $paths File path(s)
     * @return bool
     */
    public function delete(string|array $paths): bool;

    /**
     * Copy a file.
     *
     * @param string $from Source path
     * @param string $to Destination path
     * @return bool
     */
    public function copy(string $from, string $to): bool;

    /**
     * Move a file.
     *
     * @param string $from Source path
     * @param string $to Destination path
     * @return bool
     */
    public function move(string $from, string $to): bool;

    /**
     * Get file size.
     *
     * @param string $path File path
     * @return int|null Size in bytes or null if not found
     */
    public function size(string $path): ?int;

    /**
     * Get file last modified time.
     *
     * @param string $path File path
     * @return int|null Unix timestamp or null if not found
     */
    public function lastModified(string $path): ?int;

    /**
     * Get file MIME type.
     *
     * @param string $path File path
     * @return string|null MIME type or null if not found
     */
    public function mimeType(string $path): ?string;

    /**
     * List files in directory.
     *
     * @param string $directory Directory path
     * @param bool $recursive List recursively
     * @return array<string> Array of file paths
     */
    public function files(string $directory = '', bool $recursive = false): array;

    /**
     * List all directories.
     *
     * @param string $directory Directory path
     * @param bool $recursive List recursively
     * @return array<string> Array of directory paths
     */
    public function directories(string $directory = '', bool $recursive = false): array;

    /**
     * Create a directory.
     *
     * @param string $path Directory path
     * @return bool
     */
    public function makeDirectory(string $path): bool;

    /**
     * Delete a directory.
     *
     * @param string $directory Directory path
     * @return bool
     */
    public function deleteDirectory(string $directory): bool;

    /**
     * Get public URL for a file.
     *
     * @param string $path File path
     * @return string Public URL
     */
    public function url(string $path): string;

    /**
     * Get temporary URL for a file (for private files).
     *
     * @param string $path File path
     * @param int $expiration Expiration time in seconds
     * @return string Temporary signed URL
     */
    public function temporaryUrl(string $path, int $expiration): string;
}
