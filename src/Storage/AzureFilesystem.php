<?php

declare(strict_types=1);

namespace Toporia\Framework\Storage;

use Toporia\Framework\Storage\Contracts\CloudFilesystemInterface;
use Toporia\Framework\Storage\Concerns\ManagesVisibility;

/**
 * Class AzureFilesystem
 *
 * Azure Blob Storage implementation using REST API with support for block blobs,
 * SAS tokens, access tier management, metadata, and container access levels.
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
final class AzureFilesystem implements CloudFilesystemInterface
{
    use ManagesVisibility;

    private const API_VERSION = '2023-11-03';

    /**
     * @param string $account Storage account name.
     * @param string $container Container name.
     * @param string $key Account key.
     * @param string $prefix Optional path prefix.
     * @param string $baseUrl Optional custom URL.
     */
    public function __construct(
        private readonly string $account,
        private readonly string $container,
        private readonly string $key,
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
        return new self(
            account: $config['account'] ?? $config['name'] ?? '',
            container: $config['container'] ?? '',
            key: $config['key'] ?? '',
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

        if (is_resource($contents)) {
            $contents = stream_get_contents($contents);
        }

        $url = $this->getUrl($path);
        $contentType = $options['mime_type'] ?? $this->guessMimeType($path);

        $headers = [
            'x-ms-blob-type' => 'BlockBlob',
            'Content-Type' => $contentType,
            'Content-Length' => strlen($contents),
        ];

        if (isset($options['metadata'])) {
            foreach ($options['metadata'] as $key => $value) {
                $headers["x-ms-meta-{$key}"] = $value;
            }
        }

        $response = $this->request('PUT', $url, $contents, $headers);

        return $response['status'] === 201;
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $path): ?string
    {
        $path = $this->prefixPath($path);
        $url = $this->getUrl($path);

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
        $url = $this->getUrl($path);

        $response = $this->request('HEAD', $url);

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
            $url = $this->getUrl($path);

            $response = $this->request('DELETE', $url);
            $success = $success && ($response['status'] === 202 || $response['status'] === 404);
        }

        return $success;
    }

    /**
     * {@inheritdoc}
     */
    public function copy(string $from, string $to): bool
    {
        $fromPath = $this->prefixPath($from);
        $toPath = $this->prefixPath($to);

        $sourceUrl = $this->getUrl($fromPath);
        $destUrl = $this->getUrl($toPath);

        $headers = [
            'x-ms-copy-source' => $sourceUrl,
        ];

        $response = $this->request('PUT', $destUrl, '', $headers);

        return $response['status'] === 202;
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
        return isset($metadata['Content-Length']) ? (int) $metadata['Content-Length'] : null;
    }

    /**
     * {@inheritdoc}
     */
    public function lastModified(string $path): ?int
    {
        $metadata = $this->getMetadata($path);

        if (isset($metadata['Last-Modified'])) {
            return \Toporia\Framework\DateTime\Chronos::parse($metadata['Last-Modified'])->getTimestamp();
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function mimeType(string $path): ?string
    {
        $metadata = $this->getMetadata($path);
        return $metadata['Content-Type'] ?? null;
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
        // Azure Blob doesn't have directories, create placeholder
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
        return "https://{$this->account}.blob.core.windows.net/{$this->container}/{$path}";
    }

    /**
     * {@inheritdoc}
     */
    public function temporaryUrl(string $path, int $expiration): string
    {
        $path = $this->prefixPath($path);
        $start = now()->subSeconds(60)->toIso8601String();
        $expiry = now()->addSeconds($expiration)->toIso8601String();

        $sasParams = [
            'sv' => self::API_VERSION,
            'sr' => 'b',
            'st' => $start,
            'se' => $expiry,
            'sp' => 'r',
        ];

        $stringToSign = implode("\n", [
            $sasParams['sp'],
            $sasParams['st'],
            $sasParams['se'],
            "/blob/{$this->account}/{$this->container}/{$path}",
            '',
            '',
            self::API_VERSION,
            $sasParams['sr'],
            '',
            '',
            '',
            '',
            '',
        ]);

        $signature = base64_encode(hash_hmac('sha256', $stringToSign, base64_decode($this->key), true));
        $sasParams['sig'] = $signature;

        return $this->getUrl($path) . '?' . http_build_query($sasParams);
    }

    /**
     * {@inheritdoc}
     */
    public function getVisibility(string $path): string
    {
        // Azure uses container-level access, not per-blob
        return $this->defaultVisibility;
    }

    /**
     * {@inheritdoc}
     */
    public function setVisibility(string $path, string $visibility): bool
    {
        // Azure Blob visibility is container-level
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getMetadata(string $path): array
    {
        $path = $this->prefixPath($path);
        $url = $this->getUrl($path);

        $response = $this->request('HEAD', $url);

        return $response['headers'] ?? [];
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

        if ($algorithm === 'md5' && isset($metadata['Content-MD5'])) {
            return base64_decode($metadata['Content-MD5']);
        }

        return null;
    }

    /**
     * List container contents.
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

        $url = "https://{$this->account}.blob.core.windows.net/{$this->container}?restype=container&comp=list";
        $url .= "&prefix=" . urlencode($prefix);

        if (!$recursive) {
            $url .= "&delimiter=/";
        }

        $results = [];
        $marker = null;

        do {
            $pageUrl = $marker ? "{$url}&marker={$marker}" : $url;
            $response = $this->request('GET', $pageUrl);

            if ($response['status'] !== 200) {
                break;
            }

            // SECURITY: Disable external entity loading to prevent XXE attacks
            // Note: LIBXML_NONET blocks network access, we removed LIBXML_NOENT which enables entity expansion
            $previousValue = libxml_disable_entity_loader(true);
            $xml = simplexml_load_string($response['body'], 'SimpleXMLElement', LIBXML_NONET);
            libxml_disable_entity_loader($previousValue);

            if ($type === 'file' && isset($xml->Blobs->Blob)) {
                foreach ($xml->Blobs->Blob as $blob) {
                    $results[] = $this->removePrefixPath((string) $blob->Name);
                }
            }

            if ($type === 'dir' && isset($xml->Blobs->BlobPrefix)) {
                foreach ($xml->Blobs->BlobPrefix as $prefix) {
                    $results[] = rtrim($this->removePrefixPath((string) $prefix->Name), '/');
                }
            }

            $marker = isset($xml->NextMarker) ? (string) $xml->NextMarker : null;
        } while ($marker);

        return $results;
    }

    /**
     * Make HTTP request.
     *
     * @param string $method HTTP method.
     * @param string $url URL.
     * @param string $body Request body.
     * @param array<string, string> $additionalHeaders Additional headers.
     * @return array{status: int, body: string, headers: array}
     */
    private function request(string $method, string $url, string $body = '', array $additionalHeaders = []): array
    {
        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $parsedUrl = parse_url($url);
        $path = $parsedUrl['path'] ?? '';

        $headers = array_merge([
            'x-ms-date' => $date,
            'x-ms-version' => self::API_VERSION,
        ], $additionalHeaders);

        // Build authorization header
        $stringToSign = $this->buildStringToSign($method, $path, $headers, $body);
        $signature = base64_encode(hash_hmac('sha256', $stringToSign, base64_decode($this->key), true));
        $headers['Authorization'] = "SharedKey {$this->account}:{$signature}";

        $ch = curl_init($url);

        $headerList = [];
        foreach ($headers as $key => $value) {
            $headerList[] = "{$key}: {$value}";
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerList,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 300,
        ]);

        if ($body && in_array($method, ['PUT', 'POST'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        $headerStr = substr($response, 0, $headerSize);
        $responseBody = substr($response, $headerSize);

        return [
            'status' => $status,
            'body' => $responseBody ?: '',
            'headers' => $this->parseHeaders($headerStr),
        ];
    }

    /**
     * Build string to sign for Azure authentication.
     *
     * @param string $method HTTP method.
     * @param string $path Request path.
     * @param array<string, string> $headers Headers.
     * @param string $body Request body.
     * @return string
     */
    private function buildStringToSign(string $method, string $path, array $headers, string $body): string
    {
        $contentLength = strlen($body) > 0 ? strlen($body) : '';
        $contentType = $headers['Content-Type'] ?? '';

        // Get canonical headers (x-ms-*)
        $canonicalHeaders = '';
        ksort($headers);
        foreach ($headers as $key => $value) {
            if (str_starts_with(strtolower($key), 'x-ms-')) {
                $canonicalHeaders .= strtolower($key) . ':' . trim($value) . "\n";
            }
        }

        $canonicalResource = "/{$this->account}{$path}";

        return implode("\n", [
            $method,
            '', // Content-Encoding
            '', // Content-Language
            $contentLength,
            '', // Content-MD5
            $contentType,
            '', // Date
            '', // If-Modified-Since
            '', // If-Match
            '', // If-None-Match
            '', // If-Unmodified-Since
            '', // Range
            $canonicalHeaders . $canonicalResource,
        ]);
    }

    /**
     * Parse response headers.
     *
     * @param string $headerStr Header string.
     * @return array<string, string>
     */
    private function parseHeaders(string $headerStr): array
    {
        $headers = [];
        $lines = explode("\r\n", $headerStr);

        foreach ($lines as $line) {
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $headers[trim($key)] = trim($value);
            }
        }

        return $headers;
    }

    /**
     * Get blob URL.
     *
     * @param string $path Blob path.
     * @return string
     */
    private function getUrl(string $path): string
    {
        return "https://{$this->account}.blob.core.windows.net/{$this->container}/{$path}";
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
            return ltrim(substr($path, strlen($this->prefix)), '/');
        }

        return $path;
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
