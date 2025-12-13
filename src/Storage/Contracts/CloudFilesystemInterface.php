<?php

declare(strict_types=1);

namespace Toporia\Framework\Storage\Contracts;

/**
 * Interface CloudFilesystemInterface
 *
 * Extended interface for cloud storage providers.
 * Adds cloud-specific features like visibility, temporary URLs, and metadata.
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
interface CloudFilesystemInterface extends FilesystemInterface
{
    /**
     * Get file visibility (public/private).
     *
     * @param string $path File path.
     * @return string 'public' or 'private'
     */
    public function getVisibility(string $path): string;

    /**
     * Set file visibility.
     *
     * @param string $path File path.
     * @param string $visibility 'public' or 'private'
     * @return bool
     */
    public function setVisibility(string $path, string $visibility): bool;

    /**
     * Get file metadata.
     *
     * @param string $path File path.
     * @return array<string, mixed>
     */
    public function getMetadata(string $path): array;

    /**
     * Write file with visibility.
     *
     * @param string $path File path.
     * @param mixed $contents File contents.
     * @param string $visibility Visibility setting.
     * @return bool
     */
    public function putWithVisibility(string $path, mixed $contents, string $visibility): bool;

    /**
     * Write from stream.
     *
     * @param string $path File path.
     * @param resource $resource Stream resource.
     * @param array<string, mixed> $options Options.
     * @return bool
     */
    public function writeStream(string $path, $resource, array $options = []): bool;

    /**
     * Check if temporary URLs are supported.
     *
     * @return bool
     */
    public function supportsTemporaryUrls(): bool;

    /**
     * Get checksum/hash of file.
     *
     * @param string $path File path.
     * @param string $algorithm Hash algorithm (md5, sha1, sha256).
     * @return string|null
     */
    public function checksum(string $path, string $algorithm = 'md5'): ?string;
}
