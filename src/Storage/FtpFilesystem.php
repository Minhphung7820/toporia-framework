<?php

declare(strict_types=1);

namespace Toporia\Framework\Storage;

use Toporia\Framework\Storage\Contracts\FilesystemInterface;

/**
 * Class FtpFilesystem
 *
 * FTP/FTPS implementation for file storage with support for passive mode,
 * ASCII and binary transfers, directory operations, and permission management.
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
final class FtpFilesystem implements FilesystemInterface
{
    /**
     * @var \FTP\Connection|resource|null FTP connection.
     */
    private mixed $connection = null;

    /**
     * @var bool Whether connection is established.
     */
    private bool $connected = false;

    /**
     * @param string $host FTP host.
     * @param string $username FTP username.
     * @param string $password FTP password.
     * @param int $port FTP port (default: 21).
     * @param string $root Root directory.
     * @param bool $ssl Use FTPS.
     * @param bool $passive Use passive mode.
     * @param int $timeout Connection timeout.
     * @param bool $utf8 Enable UTF-8 mode.
     * @param int $permissions Default file permissions.
     * @param int $directoryPermissions Default directory permissions.
     */
    public function __construct(
        private readonly string $host,
        private readonly string $username,
        private readonly string $password,
        private readonly int $port = 21,
        private readonly string $root = '/',
        private readonly bool $ssl = false,
        private readonly bool $passive = true,
        private readonly int $timeout = 30,
        private readonly bool $utf8 = false,
        private readonly int $permissions = 0644,
        private readonly int $directoryPermissions = 0755
    ) {}

    /**
     * Create from config.
     *
     * @param array<string, mixed> $config Configuration.
     * @return self
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            host: $config['host'] ?? '',
            username: $config['username'] ?? '',
            password: $config['password'] ?? '',
            port: (int) ($config['port'] ?? 21),
            root: $config['root'] ?? '/',
            ssl: (bool) ($config['ssl'] ?? false),
            passive: (bool) ($config['passive'] ?? true),
            timeout: (int) ($config['timeout'] ?? 30),
            utf8: (bool) ($config['utf8'] ?? false),
            permissions: (int) ($config['permissions'] ?? 0644),
            directoryPermissions: (int) ($config['directory_permissions'] ?? 0755)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function put(string $path, mixed $contents, array $options = []): bool
    {
        $this->ensureConnected();
        $path = $this->prefixPath($path);

        // Ensure parent directory exists
        $directory = dirname($path);
        if ($directory !== '.' && $directory !== '/') {
            $this->ensureDirectoryExists($directory);
        }

        // Create temp file for upload
        $tempFile = tmpfile();
        if ($tempFile === false) {
            return false;
        }

        if (is_resource($contents)) {
            stream_copy_to_stream($contents, $tempFile);
        } else {
            fwrite($tempFile, $contents);
        }
        rewind($tempFile);

        $mode = ($options['ascii'] ?? false) ? FTP_ASCII : FTP_BINARY;
        $result = @ftp_fput($this->connection, $path, $tempFile, $mode);

        fclose($tempFile);

        if ($result && isset($options['permissions'])) {
            @ftp_chmod($this->connection, $options['permissions'], $path);
        } elseif ($result) {
            @ftp_chmod($this->connection, $this->permissions, $path);
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $path): ?string
    {
        $this->ensureConnected();
        $path = $this->prefixPath($path);

        $tempFile = tmpfile();
        if ($tempFile === false) {
            return null;
        }

        $result = @ftp_fget($this->connection, $tempFile, $path, FTP_BINARY);

        if (!$result) {
            fclose($tempFile);
            return null;
        }

        rewind($tempFile);
        $contents = stream_get_contents($tempFile);
        fclose($tempFile);

        return $contents !== false ? $contents : null;
    }

    /**
     * {@inheritdoc}
     */
    public function readStream(string $path)
    {
        $this->ensureConnected();
        $path = $this->prefixPath($path);

        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            return null;
        }

        $result = @ftp_fget($this->connection, $stream, $path, FTP_BINARY);

        if (!$result) {
            fclose($stream);
            return null;
        }

        rewind($stream);
        return $stream;
    }

    /**
     * {@inheritdoc}
     */
    public function exists(string $path): bool
    {
        $this->ensureConnected();
        $path = $this->prefixPath($path);

        // Try to get file size (works for files)
        $size = @ftp_size($this->connection, $path);
        if ($size !== -1) {
            return true;
        }

        // Check if it's a directory
        $currentDir = @ftp_pwd($this->connection);
        $result = @ftp_chdir($this->connection, $path);
        if ($result) {
            @ftp_chdir($this->connection, $currentDir);
            return true;
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string|array $paths): bool
    {
        $this->ensureConnected();
        $paths = is_array($paths) ? $paths : [$paths];
        $success = true;

        foreach ($paths as $path) {
            $path = $this->prefixPath($path);
            $result = @ftp_delete($this->connection, $path);
            $success = $success && $result;
        }

        return $success;
    }

    /**
     * {@inheritdoc}
     */
    public function copy(string $from, string $to): bool
    {
        // FTP doesn't support server-side copy, download and re-upload
        $contents = $this->get($from);
        if ($contents === null) {
            return false;
        }

        return $this->put($to, $contents);
    }

    /**
     * {@inheritdoc}
     */
    public function move(string $from, string $to): bool
    {
        $this->ensureConnected();
        $from = $this->prefixPath($from);
        $to = $this->prefixPath($to);

        // Ensure target directory exists
        $directory = dirname($to);
        if ($directory !== '.' && $directory !== '/') {
            $this->ensureDirectoryExists($directory);
        }

        return @ftp_rename($this->connection, $from, $to);
    }

    /**
     * {@inheritdoc}
     */
    public function size(string $path): ?int
    {
        $this->ensureConnected();
        $path = $this->prefixPath($path);

        $size = @ftp_size($this->connection, $path);
        return $size !== -1 ? $size : null;
    }

    /**
     * {@inheritdoc}
     */
    public function lastModified(string $path): ?int
    {
        $this->ensureConnected();
        $path = $this->prefixPath($path);

        $timestamp = @ftp_mdtm($this->connection, $path);
        return $timestamp !== -1 ? $timestamp : null;
    }

    /**
     * {@inheritdoc}
     */
    public function mimeType(string $path): ?string
    {
        // FTP doesn't provide MIME type, guess from extension
        return $this->guessMimeType($path);
    }

    /**
     * {@inheritdoc}
     */
    public function files(string $directory = '', bool $recursive = false): array
    {
        $this->ensureConnected();
        $path = $this->prefixPath($directory);

        return $this->listContents($path, $recursive, 'file');
    }

    /**
     * {@inheritdoc}
     */
    public function directories(string $directory = '', bool $recursive = false): array
    {
        $this->ensureConnected();
        $path = $this->prefixPath($directory);

        return $this->listContents($path, $recursive, 'dir');
    }

    /**
     * {@inheritdoc}
     */
    public function makeDirectory(string $path): bool
    {
        $this->ensureConnected();
        $path = $this->prefixPath($path);

        return $this->ensureDirectoryExists($path);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteDirectory(string $directory): bool
    {
        $this->ensureConnected();
        $directory = $this->prefixPath($directory);

        // Recursively delete contents
        $files = $this->listContents($directory, true, 'file');
        foreach ($files as $file) {
            @ftp_delete($this->connection, $this->prefixPath($file));
        }

        // Delete directories from deepest to shallowest
        $dirs = $this->listContents($directory, true, 'dir');
        usort($dirs, fn($a, $b) => substr_count($b, '/') - substr_count($a, '/'));

        foreach ($dirs as $dir) {
            @ftp_rmdir($this->connection, $this->prefixPath($dir));
        }

        return @ftp_rmdir($this->connection, $directory);
    }

    /**
     * {@inheritdoc}
     */
    public function url(string $path): string
    {
        $protocol = $this->ssl ? 'ftps' : 'ftp';
        $path = $this->prefixPath($path);

        return "{$protocol}://{$this->host}:{$this->port}{$path}";
    }

    /**
     * {@inheritdoc}
     */
    public function temporaryUrl(string $path, int $expiration): string
    {
        // FTP doesn't support temporary URLs
        return $this->url($path);
    }

    /**
     * Set file permissions.
     *
     * @param string $path File path.
     * @param int $permissions Unix permissions.
     * @return bool
     */
    public function setPermissions(string $path, int $permissions): bool
    {
        $this->ensureConnected();
        $path = $this->prefixPath($path);

        return @ftp_chmod($this->connection, $permissions, $path) !== false;
    }

    /**
     * Get raw FTP listing.
     *
     * @param string $directory Directory path.
     * @return array<string>
     */
    public function rawList(string $directory = ''): array
    {
        $this->ensureConnected();
        $path = $this->prefixPath($directory);

        return @ftp_rawlist($this->connection, $path) ?: [];
    }

    /**
     * Execute raw FTP command.
     *
     * @param string $command FTP command.
     * @return array<string>
     */
    public function raw(string $command): array
    {
        $this->ensureConnected();
        return @ftp_raw($this->connection, $command);
    }

    /**
     * Disconnect from server.
     *
     * @return void
     */
    public function disconnect(): void
    {
        if ($this->connection !== null) {
            @ftp_close($this->connection);
            $this->connection = null;
            $this->connected = false;
        }
    }

    /**
     * Destructor.
     */
    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * Ensure connection is established.
     *
     * @return void
     * @throws \RuntimeException If connection fails.
     */
    private function ensureConnected(): void
    {
        if ($this->connected && $this->connection !== null) {
            return;
        }

        // Connect
        if ($this->ssl) {
            $this->connection = @ftp_ssl_connect($this->host, $this->port, $this->timeout);
        } else {
            $this->connection = @ftp_connect($this->host, $this->port, $this->timeout);
        }

        if ($this->connection === false) {
            throw new \RuntimeException("Could not connect to FTP server: {$this->host}:{$this->port}");
        }

        // Login
        if (!@ftp_login($this->connection, $this->username, $this->password)) {
            @ftp_close($this->connection);
            $this->connection = null;
            throw new \RuntimeException("FTP login failed for user: {$this->username}");
        }

        // Set passive mode
        if ($this->passive) {
            @ftp_pasv($this->connection, true);
        }

        // Enable UTF-8
        if ($this->utf8) {
            @ftp_raw($this->connection, 'OPTS UTF8 ON');
        }

        // Change to root directory
        if ($this->root !== '/' && $this->root !== '') {
            if (!@ftp_chdir($this->connection, $this->root)) {
                // Try to create root directory
                $this->ensureDirectoryExists($this->root);
                @ftp_chdir($this->connection, $this->root);
            }
        }

        $this->connected = true;
    }

    /**
     * Ensure directory exists, create if not.
     *
     * @param string $path Directory path.
     * @return bool
     */
    private function ensureDirectoryExists(string $path): bool
    {
        $path = trim($path, '/');
        if ($path === '') {
            return true;
        }

        $parts = explode('/', $path);
        $currentPath = '';

        foreach ($parts as $part) {
            $currentPath .= '/' . $part;

            // Check if directory exists
            $originalDir = @ftp_pwd($this->connection);
            $exists = @ftp_chdir($this->connection, $currentPath);
            if ($exists) {
                @ftp_chdir($this->connection, $originalDir);
                continue;
            }

            // Create directory
            if (!@ftp_mkdir($this->connection, $currentPath)) {
                return false;
            }

            @ftp_chmod($this->connection, $this->directoryPermissions, $currentPath);
        }

        return true;
    }

    /**
     * List directory contents.
     *
     * @param string $directory Directory path.
     * @param bool $recursive Recursive listing.
     * @param string $type 'file' or 'dir'
     * @return array<string>
     */
    private function listContents(string $directory, bool $recursive, string $type): array
    {
        $results = [];
        $rawList = @ftp_rawlist($this->connection, $directory);

        if ($rawList === false) {
            return $results;
        }

        foreach ($rawList as $item) {
            $info = $this->parseRawListItem($item);
            if ($info === null) {
                continue;
            }

            $fullPath = rtrim($directory, '/') . '/' . $info['name'];
            $relativePath = $this->removePrefixPath($fullPath);

            if ($info['type'] === 'dir') {
                if ($type === 'dir') {
                    $results[] = $relativePath;
                }

                if ($recursive) {
                    $results = array_merge(
                        $results,
                        $this->listContents($fullPath, true, $type)
                    );
                }
            } elseif ($type === 'file') {
                $results[] = $relativePath;
            }
        }

        return $results;
    }

    /**
     * Parse raw list item.
     *
     * @param string $item Raw list item.
     * @return array<string, mixed>|null
     */
    private function parseRawListItem(string $item): ?array
    {
        // Unix-style listing
        if (preg_match('/^([drwx\-]+)\s+\d+\s+\S+\s+\S+\s+(\d+)\s+(\w+\s+\d+\s+[\d:]+)\s+(.+)$/', $item, $matches)) {
            $name = $matches[4];

            // Skip . and ..
            if ($name === '.' || $name === '..') {
                return null;
            }

            return [
                'type' => $matches[1][0] === 'd' ? 'dir' : 'file',
                'permissions' => $matches[1],
                'size' => (int) $matches[2],
                'date' => $matches[3],
                'name' => $name,
            ];
        }

        // Windows-style listing
        if (preg_match('/^(\d{2}-\d{2}-\d{2})\s+(\d{2}:\d{2}[AP]M)\s+(<DIR>|\d+)\s+(.+)$/', $item, $matches)) {
            $name = $matches[4];

            if ($name === '.' || $name === '..') {
                return null;
            }

            return [
                'type' => $matches[3] === '<DIR>' ? 'dir' : 'file',
                'size' => $matches[3] === '<DIR>' ? 0 : (int) $matches[3],
                'date' => $matches[1] . ' ' . $matches[2],
                'name' => $name,
            ];
        }

        return null;
    }

    /**
     * Add prefix to path.
     *
     * @param string $path Path.
     * @return string
     */
    private function prefixPath(string $path): string
    {
        $path = ltrim($path, '/');
        $root = rtrim($this->root, '/');

        if ($root && $root !== '/') {
            return $root . '/' . $path;
        }

        return '/' . $path;
    }

    /**
     * Remove prefix from path.
     *
     * @param string $path Path.
     * @return string
     */
    private function removePrefixPath(string $path): string
    {
        $root = rtrim($this->root, '/');

        if ($root && str_starts_with($path, $root)) {
            return ltrim(substr($path, strlen($root)), '/');
        }

        return ltrim($path, '/');
    }

    /**
     * Guess MIME type from path.
     *
     * @param string $path File path.
     * @return string
     */
    private function guessMimeType(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $mimeTypes = [
            'txt' => 'text/plain',
            'html' => 'text/html',
            'css' => 'text/css',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'zip' => 'application/zip',
        ];

        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }
}
