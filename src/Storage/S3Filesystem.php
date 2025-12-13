<?php

declare(strict_types=1);

namespace Toporia\Framework\Storage;

use Toporia\Framework\Storage\Contracts\FilesystemInterface;

/**
 * Class S3Filesystem
 *
 * AWS S3 filesystem driver with stream-based operations for large files.
 * Requires aws/aws-sdk-php package.
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
final class S3Filesystem implements FilesystemInterface
{
    /** @var \Aws\S3\S3Client|object */
    private object $s3Client;

    public function __construct(
        private readonly string $bucket,
        private readonly string $region,
        private readonly string $key,
        private readonly string $secret,
        private readonly string $baseUrl = '',
        private readonly string $endpoint = '',
        private readonly string $prefix = ''
    ) {
        $this->initializeClient();
    }

    /**
     * Create from config.
     *
     * @param array<string, mixed> $config Configuration.
     * @return self
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            bucket: $config['bucket'] ?? '',
            region: $config['region'] ?? 'us-east-1',
            key: $config['key'] ?? '',
            secret: $config['secret'] ?? '',
            baseUrl: $config['url'] ?? '',
            endpoint: $config['endpoint'] ?? '',
            prefix: $config['prefix'] ?? ''
        );
    }

    public function put(string $path, mixed $contents, array $options = []): bool
    {
        try {
            $params = [
                'Bucket' => $this->bucket,
                'Key' => $path,
                'Body' => $contents,
                'ACL' => ($options['visibility'] ?? 'private') === 'public' ? 'public-read' : 'private',
            ];

            $this->s3Client->putObject($params);
            return true;
        } catch (\Throwable $e) {
            error_log("S3 put error: " . $e->getMessage());
            return false;
        }
    }

    public function get(string $path): ?string
    {
        try {
            $result = $this->s3Client->getObject(['Bucket' => $this->bucket, 'Key' => $path]);
            return (string) $result['Body'];
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function readStream(string $path)
    {
        try {
            $result = $this->s3Client->getObject(['Bucket' => $this->bucket, 'Key' => $path]);
            return $result['Body']->detach();
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function exists(string $path): bool
    {
        return $this->s3Client->doesObjectExist($this->bucket, $path);
    }

    public function delete(string|array $paths): bool
    {
        $paths = is_array($paths) ? $paths : [$paths];
        try {
            $this->s3Client->deleteObjects([
                'Bucket' => $this->bucket,
                'Delete' => ['Objects' => array_map(fn($p) => ['Key' => $p], $paths)],
            ]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function copy(string $from, string $to): bool
    {
        try {
            $this->s3Client->copyObject([
                'Bucket' => $this->bucket,
                'CopySource' => "{$this->bucket}/{$from}",
                'Key' => $to,
            ]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function move(string $from, string $to): bool
    {
        return $this->copy($from, $to) && $this->delete($from);
    }

    public function size(string $path): ?int
    {
        try {
            $result = $this->s3Client->headObject(['Bucket' => $this->bucket, 'Key' => $path]);
            return (int) $result['ContentLength'];
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function lastModified(string $path): ?int
    {
        try {
            $result = $this->s3Client->headObject(['Bucket' => $this->bucket, 'Key' => $path]);
            return $result['LastModified']->getTimestamp();
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function mimeType(string $path): ?string
    {
        try {
            $result = $this->s3Client->headObject(['Bucket' => $this->bucket, 'Key' => $path]);
            return $result['ContentType'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function files(string $directory = '', bool $recursive = false): array
    {
        try {
            $params = ['Bucket' => $this->bucket, 'Prefix' => $directory ? rtrim($directory, '/') . '/' : ''];
            if (!$recursive) $params['Delimiter'] = '/';

            $result = $this->s3Client->listObjectsV2($params);
            return array_map(fn($obj) => $obj['Key'], $result['Contents'] ?? []);
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function directories(string $directory = '', bool $recursive = false): array
    {
        try {
            $result = $this->s3Client->listObjectsV2([
                'Bucket' => $this->bucket,
                'Prefix' => $directory ? rtrim($directory, '/') . '/' : '',
                'Delimiter' => '/',
            ]);
            return array_map(fn($p) => rtrim($p['Prefix'], '/'), $result['CommonPrefixes'] ?? []);
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function makeDirectory(string $path): bool
    {
        return $this->put(rtrim($path, '/') . '/', '');
    }

    public function deleteDirectory(string $directory): bool
    {
        return $this->delete($this->files($directory, true));
    }

    public function url(string $path): string
    {
        return $this->baseUrl
            ? rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/')
            : "https://{$this->bucket}.s3.{$this->region}.amazonaws.com/" . ltrim($path, '/');
    }

    public function temporaryUrl(string $path, int $expiration): string
    {
        try {
            $cmd = $this->s3Client->getCommand('GetObject', ['Bucket' => $this->bucket, 'Key' => $path]);
            $request = $this->s3Client->createPresignedRequest($cmd, "+{$expiration} seconds");
            return (string) $request->getUri();
        } catch (\Throwable $e) {
            return $this->url($path);
        }
    }

    private function initializeClient(): void
    {
        if (!class_exists('\Aws\S3\S3Client')) {
            throw new \RuntimeException('AWS SDK not installed. Run: composer require aws/aws-sdk-php');
        }

        $config = [
            'version' => 'latest',
            'region' => $this->region,
            'credentials' => ['key' => $this->key, 'secret' => $this->secret],
        ];

        if ($this->endpoint) {
            $config['endpoint'] = $this->endpoint;
            $config['use_path_style_endpoint'] = true;
        }

        $this->s3Client = new \Aws\S3\S3Client($config);
    }
}
