<?php

declare(strict_types=1);

namespace Toporia\Framework\Storage;

use Toporia\Framework\Storage\Contracts\FilesystemInterface;
use Toporia\Framework\Storage\Contracts\CloudFilesystemInterface;

/**
 * Class StorageManager
 *
 * Multi-driver storage manager with fluent API supporting Local, S3, GCS,
 * Azure Blob Storage, FTP/FTPS, and SFTP with O(1) driver lookup and caching.
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
final class StorageManager
{
    /** @var array<string, FilesystemInterface> */
    private array $disks = [];

    /** @var array<string, callable> Custom driver creators */
    private array $customDrivers = [];

    public function __construct(
        private readonly array $config,
        private readonly string $defaultDisk = 'local'
    ) {}

    /**
     * Get filesystem disk instance.
     *
     * @param string|null $name Disk name (uses default if null)
     * @return FilesystemInterface
     */
    public function disk(?string $name = null): FilesystemInterface
    {
        $name = $name ?? $this->defaultDisk;

        // Return cached disk
        if (isset($this->disks[$name])) {
            return $this->disks[$name];
        }

        // Create and cache new disk
        $this->disks[$name] = $this->createDisk($name);
        return $this->disks[$name];
    }

    /**
     * Create filesystem disk from config.
     *
     * @param string $name Disk name
     * @return FilesystemInterface
     * @throws \RuntimeException If disk config not found
     */
    private function createDisk(string $name): FilesystemInterface
    {
        if (!isset($this->config['disks'][$name])) {
            throw new \RuntimeException("Disk [{$name}] not configured.");
        }

        $config = $this->config['disks'][$name];
        $driver = $config['driver'] ?? 'local';

        // Check for custom driver
        if (isset($this->customDrivers[$driver])) {
            return ($this->customDrivers[$driver])($config, $name);
        }

        return match ($driver) {
            'local' => $this->createLocalDisk($config),
            's3' => $this->createS3Disk($config),
            'gcs' => $this->createGcsDisk($config),
            'azure' => $this->createAzureDisk($config),
            'ftp' => $this->createFtpDisk($config),
            'sftp' => $this->createSftpDisk($config),
            default => throw new \RuntimeException("Unsupported storage driver [{$driver}]. Supported: local, s3, gcs, azure, ftp, sftp"),
        };
    }

    /**
     * Register a custom driver creator.
     *
     * Example:
     * ```php
     * $manager->extend('dropbox', function (array $config, string $name) {
     *     return new DropboxFilesystem($config);
     * });
     * ```
     *
     * @param string $driver Driver name
     * @param callable $callback Creator callback
     * @return $this
     */
    public function extend(string $driver, callable $callback): self
    {
        $this->customDrivers[$driver] = $callback;
        return $this;
    }

    /**
     * Get the default disk name.
     *
     * @return string
     */
    public function getDefaultDisk(): string
    {
        return $this->defaultDisk;
    }

    /**
     * Get all configured disk names.
     *
     * @return array<string>
     */
    public function getAvailableDisks(): array
    {
        return array_keys($this->config['disks'] ?? []);
    }

    /**
     * Purge a disk from cache.
     *
     * @param string|null $name Disk name (null for all)
     * @return $this
     */
    public function purge(?string $name = null): self
    {
        if ($name === null) {
            $this->disks = [];
        } else {
            unset($this->disks[$name]);
        }

        return $this;
    }

    /**
     * Create local filesystem disk.
     *
     * @param array<string, mixed> $config Disk configuration
     * @return LocalFilesystem
     */
    private function createLocalDisk(array $config): LocalFilesystem
    {
        return new LocalFilesystem(
            root: $config['root'] ?? storage_path('app'),
            baseUrl: $config['url'] ?? ''
        );
    }

    /**
     * Create S3 filesystem disk.
     *
     * Supports: AWS S3, DigitalOcean Spaces, Minio, Cloudflare R2, etc.
     *
     * @param array<string, mixed> $config Disk configuration
     * @return S3Filesystem
     */
    private function createS3Disk(array $config): S3Filesystem
    {
        return S3Filesystem::fromConfig($config);
    }

    /**
     * Create Google Cloud Storage filesystem disk.
     *
     * @param array<string, mixed> $config Disk configuration
     * @return GcsFilesystem
     */
    private function createGcsDisk(array $config): GcsFilesystem
    {
        return GcsFilesystem::fromConfig($config);
    }

    /**
     * Create Azure Blob Storage filesystem disk.
     *
     * @param array<string, mixed> $config Disk configuration
     * @return AzureFilesystem
     */
    private function createAzureDisk(array $config): AzureFilesystem
    {
        return AzureFilesystem::fromConfig($config);
    }

    /**
     * Create FTP filesystem disk.
     *
     * @param array<string, mixed> $config Disk configuration
     * @return FtpFilesystem
     */
    private function createFtpDisk(array $config): FtpFilesystem
    {
        return FtpFilesystem::fromConfig($config);
    }

    /**
     * Create SFTP filesystem disk.
     *
     * @param array<string, mixed> $config Disk configuration
     * @return SftpFilesystem
     */
    private function createSftpDisk(array $config): SftpFilesystem
    {
        return SftpFilesystem::fromConfig($config);
    }

    /**
     * Proxy method calls to default disk.
     *
     * Allows: $storage->put() instead of $storage->disk()->put()
     *
     * @param string $method Method name
     * @param array $parameters Method parameters
     * @return mixed
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->disk()->$method(...$parameters);
    }

    // =========================================================================
    // CONVENIENCE METHODS (for static access via Storage accessor)
    // =========================================================================

    /**
     * Store file contents (convenience method).
     *
     * @param string $path File path
     * @param mixed $contents File contents (string or resource)
     * @param array $options Options (visibility, etc.)
     * @return bool
     */
    public function put(string $path, mixed $contents, array $options = []): bool
    {
        return $this->disk()->put($path, $contents, $options);
    }

    /**
     * Get file contents (convenience method).
     *
     * @param string $path File path
     * @return string|null
     */
    public function get(string $path): ?string
    {
        return $this->disk()->get($path);
    }

    /**
     * Get file as stream resource (convenience method).
     *
     * @param string $path File path
     * @return resource|null
     */
    public function readStream(string $path)
    {
        return $this->disk()->readStream($path);
    }

    /**
     * Check if file exists (convenience method).
     *
     * @param string $path File path
     * @return bool
     */
    public function exists(string $path): bool
    {
        return $this->disk()->exists($path);
    }

    /**
     * Delete file(s) (convenience method).
     *
     * @param string|array $paths File path(s)
     * @return bool
     */
    public function delete(string|array $paths): bool
    {
        return $this->disk()->delete($paths);
    }

    /**
     * Copy file (convenience method).
     *
     * @param string $from Source path
     * @param string $to Destination path
     * @return bool
     */
    public function copy(string $from, string $to): bool
    {
        return $this->disk()->copy($from, $to);
    }

    /**
     * Move file (convenience method).
     *
     * @param string $from Source path
     * @param string $to Destination path
     * @return bool
     */
    public function move(string $from, string $to): bool
    {
        return $this->disk()->move($from, $to);
    }

    /**
     * Get file size (convenience method).
     *
     * @param string $path File path
     * @return int|null
     */
    public function size(string $path): ?int
    {
        return $this->disk()->size($path);
    }

    /**
     * Get last modified timestamp (convenience method).
     *
     * @param string $path File path
     * @return int|null
     */
    public function lastModified(string $path): ?int
    {
        return $this->disk()->lastModified($path);
    }

    /**
     * Get MIME type (convenience method).
     *
     * @param string $path File path
     * @return string|null
     */
    public function mimeType(string $path): ?string
    {
        return $this->disk()->mimeType($path);
    }

    /**
     * List files in directory (convenience method).
     *
     * @param string $directory Directory path
     * @param bool $recursive Recursive listing
     * @return array
     */
    public function files(string $directory = '', bool $recursive = false): array
    {
        return $this->disk()->files($directory, $recursive);
    }

    /**
     * List subdirectories (convenience method).
     *
     * @param string $directory Directory path
     * @param bool $recursive Recursive listing
     * @return array
     */
    public function directories(string $directory = '', bool $recursive = false): array
    {
        return $this->disk()->directories($directory, $recursive);
    }

    /**
     * Create directory (convenience method).
     *
     * @param string $path Directory path
     * @return bool
     */
    public function makeDirectory(string $path): bool
    {
        return $this->disk()->makeDirectory($path);
    }

    /**
     * Delete directory (convenience method).
     *
     * @param string $directory Directory path
     * @return bool
     */
    public function deleteDirectory(string $directory): bool
    {
        return $this->disk()->deleteDirectory($directory);
    }

    /**
     * Get public URL (convenience method).
     *
     * @param string $path File path
     * @return string
     */
    public function url(string $path): string
    {
        return $this->disk()->url($path);
    }

    /**
     * Get temporary URL (signed) (convenience method).
     *
     * @param string $path File path
     * @param int $expiration Expiration in seconds
     * @return string
     */
    public function temporaryUrl(string $path, int $expiration): string
    {
        return $this->disk()->temporaryUrl($path, $expiration);
    }
}
