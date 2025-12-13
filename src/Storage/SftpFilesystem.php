<?php

declare(strict_types=1);

namespace Toporia\Framework\Storage;

use Toporia\Framework\Storage\Contracts\FilesystemInterface;

/**
 * Class SftpFilesystem
 *
 * SSH/SFTP implementation for secure file storage with password and key-based
 * authentication, agent support, permission management, and fingerprint verification.
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
final class SftpFilesystem implements FilesystemInterface
{
    /**
     * @var resource|null SSH connection.
     */
    private mixed $connection = null;

    /**
     * @var resource|null SFTP subsystem.
     */
    private mixed $sftp = null;

    /**
     * @var bool Whether connection is established.
     */
    private bool $connected = false;

    /**
     * @param string $host SSH host.
     * @param string $username SSH username.
     * @param string $password SSH password (optional if using key).
     * @param string $privateKey Path to private key file.
     * @param string $passphrase Passphrase for private key.
     * @param int $port SSH port (default: 22).
     * @param string $root Root directory.
     * @param bool $useAgent Use SSH agent for authentication.
     * @param string|null $hostFingerprint Expected host fingerprint.
     * @param int $timeout Connection timeout.
     * @param int $permissions Default file permissions.
     * @param int $directoryPermissions Default directory permissions.
     */
    public function __construct(
        private readonly string $host,
        private readonly string $username,
        private readonly string $password = '',
        private readonly string $privateKey = '',
        private readonly string $passphrase = '',
        private readonly int $port = 22,
        private readonly string $root = '/',
        private readonly bool $useAgent = false,
        private readonly ?string $hostFingerprint = null,
        private readonly int $timeout = 30,
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
            privateKey: $config['private_key'] ?? $config['privateKey'] ?? '',
            passphrase: $config['passphrase'] ?? '',
            port: (int) ($config['port'] ?? 22),
            root: $config['root'] ?? '/',
            useAgent: (bool) ($config['use_agent'] ?? $config['useAgent'] ?? false),
            hostFingerprint: $config['host_fingerprint'] ?? $config['hostFingerprint'] ?? null,
            timeout: (int) ($config['timeout'] ?? 30),
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

        // Handle resource
        if (is_resource($contents)) {
            $contents = stream_get_contents($contents);
        }

        $sftpPath = $this->getSftpPath($path);

        $result = @file_put_contents($sftpPath, $contents);

        if ($result === false) {
            return false;
        }

        // Set permissions
        $permissions = $options['permissions'] ?? $this->permissions;
        @ssh2_sftp_chmod($this->sftp, $path, $permissions);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $path): ?string
    {
        $this->ensureConnected();
        $path = $this->prefixPath($path);
        $sftpPath = $this->getSftpPath($path);

        $contents = @file_get_contents($sftpPath);

        return $contents !== false ? $contents : null;
    }

    /**
     * {@inheritdoc}
     */
    public function readStream(string $path)
    {
        $this->ensureConnected();
        $path = $this->prefixPath($path);
        $sftpPath = $this->getSftpPath($path);

        $stream = @fopen($sftpPath, 'r');

        return $stream !== false ? $stream : null;
    }

    /**
     * {@inheritdoc}
     */
    public function exists(string $path): bool
    {
        $this->ensureConnected();
        $path = $this->prefixPath($path);

        $stat = @ssh2_sftp_stat($this->sftp, $path);

        return $stat !== false;
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
            $result = @ssh2_sftp_unlink($this->sftp, $path);
            $success = $success && $result;
        }

        return $success;
    }

    /**
     * {@inheritdoc}
     */
    public function copy(string $from, string $to): bool
    {
        // SFTP doesn't support server-side copy
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

        return @ssh2_sftp_rename($this->sftp, $from, $to);
    }

    /**
     * {@inheritdoc}
     */
    public function size(string $path): ?int
    {
        $this->ensureConnected();
        $path = $this->prefixPath($path);

        $stat = @ssh2_sftp_stat($this->sftp, $path);

        return $stat !== false ? $stat['size'] : null;
    }

    /**
     * {@inheritdoc}
     */
    public function lastModified(string $path): ?int
    {
        $this->ensureConnected();
        $path = $this->prefixPath($path);

        $stat = @ssh2_sftp_stat($this->sftp, $path);

        return $stat !== false ? $stat['mtime'] : null;
    }

    /**
     * {@inheritdoc}
     */
    public function mimeType(string $path): ?string
    {
        // SFTP doesn't provide MIME type, guess from extension
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
        return $this->deleteDirectoryRecursive($directory);
    }

    /**
     * {@inheritdoc}
     */
    public function url(string $path): string
    {
        $path = $this->prefixPath($path);
        return "sftp://{$this->username}@{$this->host}:{$this->port}{$path}";
    }

    /**
     * {@inheritdoc}
     */
    public function temporaryUrl(string $path, int $expiration): string
    {
        // SFTP doesn't support temporary URLs
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

        return @ssh2_sftp_chmod($this->sftp, $path, $permissions);
    }

    /**
     * Create symbolic link.
     *
     * @param string $target Target path.
     * @param string $link Link path.
     * @return bool
     */
    public function symlink(string $target, string $link): bool
    {
        $this->ensureConnected();
        $target = $this->prefixPath($target);
        $link = $this->prefixPath($link);

        return @ssh2_sftp_symlink($this->sftp, $target, $link);
    }

    /**
     * Read symbolic link target.
     *
     * @param string $path Link path.
     * @return string|null
     */
    public function readlink(string $path): ?string
    {
        $this->ensureConnected();
        $path = $this->prefixPath($path);

        $target = @ssh2_sftp_readlink($this->sftp, $path);

        return $target !== false ? $target : null;
    }

    /**
     * Get file stat information.
     *
     * @param string $path File path.
     * @return array<string, mixed>|null
     */
    public function stat(string $path): ?array
    {
        $this->ensureConnected();
        $path = $this->prefixPath($path);

        $stat = @ssh2_sftp_stat($this->sftp, $path);

        return $stat !== false ? $stat : null;
    }

    /**
     * Execute SSH command.
     *
     * @param string $command Command to execute.
     * @return string|null Command output.
     */
    public function exec(string $command): ?string
    {
        $this->ensureConnected();

        $stream = @ssh2_exec($this->connection, $command);

        if ($stream === false) {
            return null;
        }

        stream_set_blocking($stream, true);
        $output = stream_get_contents($stream);
        fclose($stream);

        return $output !== false ? $output : null;
    }

    /**
     * Disconnect from server.
     *
     * @return void
     */
    public function disconnect(): void
    {
        if ($this->connection !== null) {
            @ssh2_disconnect($this->connection);
            $this->connection = null;
            $this->sftp = null;
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
        if ($this->connected && $this->connection !== null && $this->sftp !== null) {
            return;
        }

        // Check if ssh2 extension is loaded
        if (!function_exists('ssh2_connect')) {
            throw new \RuntimeException('SSH2 extension is not installed');
        }

        // Connect
        $this->connection = @ssh2_connect($this->host, $this->port);

        if ($this->connection === false) {
            throw new \RuntimeException("Could not connect to SSH server: {$this->host}:{$this->port}");
        }

        // Verify host fingerprint
        if ($this->hostFingerprint !== null) {
            $fingerprint = ssh2_fingerprint($this->connection, SSH2_FINGERPRINT_SHA1 | SSH2_FINGERPRINT_HEX);
            if ($fingerprint !== $this->hostFingerprint) {
                @ssh2_disconnect($this->connection);
                $this->connection = null;
                throw new \RuntimeException('Host fingerprint verification failed');
            }
        }

        // Authenticate
        $authenticated = false;

        // Try agent authentication
        if ($this->useAgent) {
            $authenticated = @ssh2_auth_agent($this->connection, $this->username);
        }

        // Try key authentication
        if (!$authenticated && $this->privateKey) {
            if ($this->passphrase) {
                $authenticated = @ssh2_auth_pubkey_file(
                    $this->connection,
                    $this->username,
                    $this->privateKey . '.pub',
                    $this->privateKey,
                    $this->passphrase
                );
            } else {
                $authenticated = @ssh2_auth_pubkey_file(
                    $this->connection,
                    $this->username,
                    $this->privateKey . '.pub',
                    $this->privateKey
                );
            }
        }

        // Try password authentication
        if (!$authenticated && $this->password) {
            $authenticated = @ssh2_auth_password($this->connection, $this->username, $this->password);
        }

        if (!$authenticated) {
            @ssh2_disconnect($this->connection);
            $this->connection = null;
            throw new \RuntimeException("SSH authentication failed for user: {$this->username}");
        }

        // Initialize SFTP subsystem
        $this->sftp = @ssh2_sftp($this->connection);

        if ($this->sftp === false) {
            @ssh2_disconnect($this->connection);
            $this->connection = null;
            throw new \RuntimeException('Could not initialize SFTP subsystem');
        }

        $this->connected = true;
    }

    /**
     * Get SFTP stream wrapper path.
     *
     * @param string $path File path.
     * @return string
     */
    private function getSftpPath(string $path): string
    {
        // Cast resource to int for path
        $sftpId = (int) $this->sftp;
        return "ssh2.sftp://{$sftpId}{$path}";
    }

    /**
     * Ensure directory exists, create if not.
     *
     * @param string $path Directory path.
     * @return bool
     */
    private function ensureDirectoryExists(string $path): bool
    {
        $path = rtrim($path, '/');
        if ($path === '' || $path === '/') {
            return true;
        }

        // Check if already exists
        $stat = @ssh2_sftp_stat($this->sftp, $path);
        if ($stat !== false) {
            return true;
        }

        // Ensure parent exists
        $parent = dirname($path);
        if ($parent !== '/' && $parent !== '.') {
            $this->ensureDirectoryExists($parent);
        }

        // Create directory
        return @ssh2_sftp_mkdir($this->sftp, $path, $this->directoryPermissions, true);
    }

    /**
     * Delete directory recursively.
     *
     * @param string $directory Directory path.
     * @return bool
     */
    private function deleteDirectoryRecursive(string $directory): bool
    {
        $handle = @opendir($this->getSftpPath($directory));

        if ($handle === false) {
            return false;
        }

        while (($item = readdir($handle)) !== false) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = rtrim($directory, '/') . '/' . $item;
            $stat = @ssh2_sftp_stat($this->sftp, $itemPath);

            if ($stat !== false && ($stat['mode'] & 0040000)) {
                // Is directory
                $this->deleteDirectoryRecursive($itemPath);
            } else {
                @ssh2_sftp_unlink($this->sftp, $itemPath);
            }
        }

        closedir($handle);

        return @ssh2_sftp_rmdir($this->sftp, $directory);
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
        $handle = @opendir($this->getSftpPath($directory));

        if ($handle === false) {
            return $results;
        }

        while (($item = readdir($handle)) !== false) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = rtrim($directory, '/') . '/' . $item;
            $relativePath = $this->removePrefixPath($fullPath);
            $stat = @ssh2_sftp_stat($this->sftp, $fullPath);

            if ($stat === false) {
                continue;
            }

            $isDir = ($stat['mode'] & 0040000) !== 0;

            if ($isDir) {
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

        closedir($handle);

        return $results;
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
