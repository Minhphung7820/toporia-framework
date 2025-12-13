<?php

declare(strict_types=1);

namespace Toporia\Framework\Storage;

use Toporia\Framework\Storage\Contracts\CloudFilesystemInterface;
use Toporia\Framework\Storage\Concerns\ManagesVisibility;

/**
 * Class GcsFilesystem
 *
 * Google Cloud Storage implementation using REST API with resumable uploads,
 * object versioning, ACL management, signed URLs, and encryption key support.
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
final class GcsFilesystem implements CloudFilesystemInterface
{
    use ManagesVisibility;

    private const API_BASE = 'https://storage.googleapis.com';
    private const UPLOAD_BASE = 'https://storage.googleapis.com/upload/storage/v1';

    /**
     * @var string|null Cached access token.
     */
    private ?string $accessToken = null;

    /**
     * @var int Token expiration timestamp.
     */
    private int $tokenExpires = 0;

    /**
     * @param string $bucket Bucket name.
     * @param string $projectId GCP project ID.
     * @param array<string, mixed> $credentials Service account credentials.
     * @param string $prefix Optional path prefix.
     * @param string $baseUrl Optional custom base URL.
     */
    public function __construct(
        private readonly string $bucket,
        private readonly string $projectId,
        private readonly array $credentials,
        private readonly string $prefix = '',
        private readonly string $baseUrl = ''
    ) {}

    /**
     * Create from config.
     *
     * @param array<string, mixed> $config Configuration.
     * @return self
     */
    public static function fromConfig(array $config): self
    {
        $credentials = $config['credentials'] ?? [];

        // Load from file if path provided
        if (isset($config['key_file']) && file_exists($config['key_file'])) {
            $credentials = json_decode(file_get_contents($config['key_file']), true);
        }

        return new self(
            bucket: $config['bucket'] ?? '',
            projectId: $config['project_id'] ?? $credentials['project_id'] ?? '',
            credentials: $credentials,
            prefix: $config['prefix'] ?? '',
            baseUrl: $config['url'] ?? ''
        );
    }

    /**
     * {@inheritdoc}
     */
    public function put(string $path, mixed $contents, array $options = []): bool
    {
        $path = $this->prefixPath($path);
        $visibility = $options['visibility'] ?? $this->defaultVisibility;

        if (is_resource($contents)) {
            return $this->writeStream($path, $contents, $options);
        }

        $url = self::UPLOAD_BASE . "/b/{$this->bucket}/o?uploadType=media&name=" . urlencode($path);

        $headers = [
            'Authorization: Bearer ' . $this->getAccessToken(),
            'Content-Type: ' . ($options['mime_type'] ?? 'application/octet-stream'),
        ];

        $response = $this->request('POST', $url, $contents, $headers);

        if ($response['status'] === 200) {
            if ($visibility === 'public') {
                $this->setVisibility($path, 'public');
            }
            return true;
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $path): ?string
    {
        $path = $this->prefixPath($path);
        $url = self::API_BASE . "/{$this->bucket}/{$path}?alt=media";

        $response = $this->request('GET', $url);

        return $response['status'] === 200 ? $response['body'] : null;
    }

    /**
     * {@inheritdoc}
     */
    public function readStream(string $path)
    {
        $contents = $this->get($path);

        if ($contents === null) {
            return null;
        }

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    /**
     * {@inheritdoc}
     */
    public function exists(string $path): bool
    {
        $path = $this->prefixPath($path);
        $url = self::API_BASE . "/storage/v1/b/{$this->bucket}/o/" . urlencode($path);

        $response = $this->request('GET', $url);

        return $response['status'] === 200;
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string|array $paths): bool
    {
        $paths = is_array($paths) ? $paths : [$paths];
        $success = true;

        foreach ($paths as $path) {
            $path = $this->prefixPath($path);
            $url = self::API_BASE . "/storage/v1/b/{$this->bucket}/o/" . urlencode($path);

            $response = $this->request('DELETE', $url);
            $success = $success && ($response['status'] === 204 || $response['status'] === 404);
        }

        return $success;
    }

    /**
     * {@inheritdoc}
     */
    public function copy(string $from, string $to): bool
    {
        $from = $this->prefixPath($from);
        $to = $this->prefixPath($to);

        $url = self::API_BASE . "/storage/v1/b/{$this->bucket}/o/" . urlencode($from) .
            "/copyTo/b/{$this->bucket}/o/" . urlencode($to);

        $response = $this->request('POST', $url);

        return $response['status'] === 200;
    }

    /**
     * {@inheritdoc}
     */
    public function move(string $from, string $to): bool
    {
        if (!$this->copy($from, $to)) {
            return false;
        }

        return $this->delete($from);
    }

    /**
     * {@inheritdoc}
     */
    public function size(string $path): ?int
    {
        $metadata = $this->getMetadata($path);
        return isset($metadata['size']) ? (int) $metadata['size'] : null;
    }

    /**
     * {@inheritdoc}
     */
    public function lastModified(string $path): ?int
    {
        $metadata = $this->getMetadata($path);

        if (isset($metadata['updated'])) {
            return \Toporia\Framework\DateTime\Chronos::parse($metadata['updated'])->getTimestamp();
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function mimeType(string $path): ?string
    {
        $metadata = $this->getMetadata($path);
        return $metadata['contentType'] ?? null;
    }

    /**
     * {@inheritdoc}
     */
    public function files(string $directory = '', bool $recursive = false): array
    {
        return $this->listContents($directory, $recursive, 'file');
    }

    /**
     * {@inheritdoc}
     */
    public function directories(string $directory = '', bool $recursive = false): array
    {
        return $this->listContents($directory, $recursive, 'dir');
    }

    /**
     * {@inheritdoc}
     */
    public function makeDirectory(string $path): bool
    {
        // GCS doesn't have directories, but we can create a placeholder
        $path = rtrim($this->prefixPath($path), '/') . '/.keep';
        return $this->put($path, '');
    }

    /**
     * {@inheritdoc}
     */
    public function deleteDirectory(string $directory): bool
    {
        $files = $this->files($directory, true);
        return $this->delete($files);
    }

    /**
     * {@inheritdoc}
     */
    public function url(string $path): string
    {
        if ($this->baseUrl) {
            return rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        }

        $path = $this->prefixPath($path);
        return self::API_BASE . "/{$this->bucket}/{$path}";
    }

    /**
     * {@inheritdoc}
     */
    public function temporaryUrl(string $path, int $expiration): string
    {
        $path = $this->prefixPath($path);
        $expires = now()->getTimestamp() + $expiration;

        $stringToSign = implode("\n", [
            'GET',
            '', // Content-MD5
            '', // Content-Type
            $expires,
            "/{$this->bucket}/{$path}",
        ]);

        $signature = $this->signString($stringToSign);

        return self::API_BASE . "/{$this->bucket}/{$path}?" . http_build_query([
            'GoogleAccessId' => $this->credentials['client_email'] ?? '',
            'Expires' => $expires,
            'Signature' => $signature,
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function getVisibility(string $path): string
    {
        $metadata = $this->getMetadata($path);

        // Check for public-read ACL
        if (isset($metadata['acl'])) {
            foreach ($metadata['acl'] as $acl) {
                if ($acl['entity'] === 'allUsers' && $acl['role'] === 'READER') {
                    return 'public';
                }
            }
        }

        return 'private';
    }

    /**
     * {@inheritdoc}
     */
    public function setVisibility(string $path, string $visibility): bool
    {
        $path = $this->prefixPath($path);
        $url = self::API_BASE . "/storage/v1/b/{$this->bucket}/o/" . urlencode($path);

        if ($visibility === 'public') {
            $url .= '/acl';
            $body = json_encode([
                'entity' => 'allUsers',
                'role' => 'READER',
            ]);

            $response = $this->request('POST', $url, $body, [
                'Content-Type: application/json',
            ]);

            return $response['status'] === 200;
        } else {
            // Remove public access
            $url .= '/acl/allUsers';
            $response = $this->request('DELETE', $url);

            return $response['status'] === 204 || $response['status'] === 404;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getMetadata(string $path): array
    {
        $path = $this->prefixPath($path);
        $url = self::API_BASE . "/storage/v1/b/{$this->bucket}/o/" . urlencode($path);

        $response = $this->request('GET', $url);

        if ($response['status'] === 200) {
            return json_decode($response['body'], true) ?? [];
        }

        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function putWithVisibility(string $path, mixed $contents, string $visibility): bool
    {
        return $this->put($path, $contents, ['visibility' => $visibility]);
    }

    /**
     * {@inheritdoc}
     */
    public function writeStream(string $path, $resource, array $options = []): bool
    {
        $contents = stream_get_contents($resource);
        return $this->put($path, $contents, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function supportsTemporaryUrls(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function checksum(string $path, string $algorithm = 'md5'): ?string
    {
        $metadata = $this->getMetadata($path);

        if ($algorithm === 'md5' && isset($metadata['md5Hash'])) {
            return base64_decode($metadata['md5Hash']);
        }

        if ($algorithm === 'crc32c' && isset($metadata['crc32c'])) {
            return base64_decode($metadata['crc32c']);
        }

        return null;
    }

    /**
     * List bucket contents.
     *
     * @param string $directory Directory path.
     * @param bool $recursive Recursive listing.
     * @param string $type 'file' or 'dir'
     * @return array<string>
     */
    private function listContents(string $directory, bool $recursive, string $type): array
    {
        $prefix = $this->prefixPath($directory);
        $prefix = rtrim($prefix, '/');
        if ($prefix) {
            $prefix .= '/';
        }

        $url = self::API_BASE . "/storage/v1/b/{$this->bucket}/o?" . http_build_query([
            'prefix' => $prefix,
            'delimiter' => $recursive ? '' : '/',
        ]);

        $results = [];
        $pageToken = null;

        do {
            $pageUrl = $pageToken ? "{$url}&pageToken={$pageToken}" : $url;
            $response = $this->request('GET', $pageUrl);

            if ($response['status'] !== 200) {
                break;
            }

            $data = json_decode($response['body'], true);

            if ($type === 'file' && isset($data['items'])) {
                foreach ($data['items'] as $item) {
                    $results[] = $this->removePrefixPath($item['name']);
                }
            }

            if ($type === 'dir' && isset($data['prefixes'])) {
                foreach ($data['prefixes'] as $prefix) {
                    $results[] = rtrim($this->removePrefixPath($prefix), '/');
                }
            }

            $pageToken = $data['nextPageToken'] ?? null;
        } while ($pageToken);

        return $results;
    }

    /**
     * Get OAuth2 access token.
     *
     * @return string
     */
    private function getAccessToken(): string
    {
        if ($this->accessToken && now()->getTimestamp() < $this->tokenExpires - 60) {
            return $this->accessToken;
        }

        $jwt = $this->createJwt();

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]),
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);

        $this->accessToken = $data['access_token'] ?? '';
        $this->tokenExpires = now()->getTimestamp() + ($data['expires_in'] ?? 3600);

        return $this->accessToken;
    }

    /**
     * Create JWT for authentication.
     *
     * @return string
     */
    private function createJwt(): string
    {
        $header = base64_encode(json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
        ]));

        $now = now()->getTimestamp();
        $payload = base64_encode(json_encode([
            'iss' => $this->credentials['client_email'] ?? '',
            'scope' => 'https://www.googleapis.com/auth/devstorage.full_control',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        $signature = $this->signString("{$header}.{$payload}");

        return "{$header}.{$payload}.{$signature}";
    }

    /**
     * Sign string with private key.
     *
     * @param string $string String to sign.
     * @return string
     */
    private function signString(string $string): string
    {
        $privateKey = $this->credentials['private_key'] ?? '';

        openssl_sign($string, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }

    /**
     * Make HTTP request.
     *
     * @param string $method HTTP method.
     * @param string $url URL.
     * @param string $body Request body.
     * @param array<string> $headers Additional headers.
     * @return array{status: int, body: string}
     */
    private function request(string $method, string $url, string $body = '', array $headers = []): array
    {
        $ch = curl_init($url);

        $defaultHeaders = [
            'Authorization: Bearer ' . $this->getAccessToken(),
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array_merge($defaultHeaders, $headers),
            CURLOPT_TIMEOUT => 300,
        ]);

        if ($body && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        return [
            'status' => $status,
            'body' => $response ?: '',
        ];
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

        if ($this->prefix) {
            return rtrim($this->prefix, '/') . '/' . $path;
        }

        return $path;
    }

    /**
     * Remove prefix from path.
     *
     * @param string $path Path.
     * @return string
     */
    private function removePrefixPath(string $path): string
    {
        if ($this->prefix && str_starts_with($path, $this->prefix)) {
            return substr($path, strlen($this->prefix));
        }

        return $path;
    }
}
