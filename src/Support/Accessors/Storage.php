<?php

declare(strict_types=1);

namespace Toporia\Framework\Support\Accessors;

use Toporia\Framework\Foundation\ServiceAccessor;
use Toporia\Framework\Storage\StorageManager;
use Toporia\Framework\Storage\Contracts\FilesystemInterface;

/**
 * Class Storage
 *
 * Storage Service Accessor - Provides static-like access to the Storage system.
 * All methods are automatically delegated to the underlying service via __callStatic().
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
 * Supported drivers:
 * - local: Local filesystem
 * - s3: Amazon S3 / DigitalOcean Spaces / Minio / Cloudflare R2
 * - gcs: Google Cloud Storage
 * - azure: Azure Blob Storage
 * - ftp: FTP/FTPS server
 * - sftp: SFTP server
 *
 * @method static FilesystemInterface disk(?string $name = null) Get filesystem disk instance
 * @method static string getDefaultDisk() Get default disk name
 * @method static array getAvailableDisks() Get all configured disk names
 * @method static self extend(string $driver, callable $callback) Register custom driver
 * @method static self purge(?string $name = null) Clear cached disk(s)
 * @method static bool put(string $path, mixed $contents, array $options = []) Store file contents
 * @method static string|null get(string $path) Get file contents
 * @method static resource|null readStream(string $path) Get file as stream resource
 * @method static bool exists(string $path) Check if file exists
 * @method static bool delete(string|array $paths) Delete file(s)
 * @method static bool copy(string $from, string $to) Copy file
 * @method static bool move(string $from, string $to) Move file
 * @method static int|null size(string $path) Get file size
 * @method static int|null lastModified(string $path) Get last modified timestamp
 * @method static string|null mimeType(string $path) Get MIME type
 * @method static array files(string $directory = '', bool $recursive = false) List files in directory
 * @method static array directories(string $directory = '', bool $recursive = false) List subdirectories
 * @method static bool makeDirectory(string $path) Create directory
 * @method static bool deleteDirectory(string $directory) Delete directory
 * @method static string url(string $path) Get public URL
 * @method static string temporaryUrl(string $path, int $expiration) Get temporary URL (signed)
 *
 * @see StorageManager
 *
 * @example
 * // Get default disk and use it
 * Storage::put('file.txt', 'content');
 * $content = Storage::get('file.txt');
 *
 * // Get specific disk
 * Storage::disk('s3')->put('uploads/photo.jpg', $data);
 * Storage::disk('gcs')->put('backups/db.sql', $dump);
 * Storage::disk('azure')->put('documents/report.pdf', $pdf);
 *
 * // FTP/SFTP
 * Storage::disk('ftp')->put('remote/file.txt', $content);
 * Storage::disk('sftp')->get('secure/data.json');
 *
 * // Temporary URLs (S3, GCS, Azure)
 * $url = Storage::disk('s3')->temporaryUrl('private/file.pdf', 3600);
 *
 * // Register custom driver
 * Storage::extend('dropbox', function (array $config) {
 *     return new DropboxFilesystem($config);
 * });
 */
final class Storage extends ServiceAccessor
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
        return 'storage';
    }
}
