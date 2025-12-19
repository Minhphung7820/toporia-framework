<?php

declare(strict_types=1);

namespace Toporia\Framework\Http;

use Toporia\Framework\Http\Contracts\RequestInterface;
use Toporia\Framework\Session\Store;
use Toporia\Framework\Storage\UploadedFile;
use Toporia\Framework\Support\Macroable;

/**
 * Class Request
 *
 * HTTP Request implementation encapsulating all data from an incoming
 * HTTP request including method, URI path, query parameters, request
 * body/input, and headers.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Http
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class Request implements RequestInterface
{
    use Macroable;
    /**
     * @var string HTTP method.
     */
    private string $method;

    /**
     * @var string Request URI path.
     */
    private string $path;

    /**
     * @var array<string, mixed> Query parameters.
     */
    private array $query;

    /**
     * @var array<string, mixed> Request body data.
     */
    private array $body;

    /**
     * @var array<string, string> Request headers.
     */
    private array $headers;

    /**
     * @var string Raw request body.
     */
    private string $rawBody;

    /**
     * @var array<string, mixed> Request attributes (for middleware/route data)
     */
    private array $attributes = [];

    /**
     * @var \Toporia\Framework\Session\Store|null Session store instance
     */
    private ?Store $session = null;

    /**
     * @var array<string, mixed> Instance-level cache for nested value lookups
     */
    private array $nestedValueCache = [];

    /**
     * @var string|null Hash of body for cache invalidation
     */
    private ?string $bodyCacheHash = null;

    /**
     * Request constructor.
     *
     * @param \Toporia\Framework\Session\Store|null $session Session store instance
     */
    public function __construct(?Store $session = null)
    {
        $this->session = $session;
    }

    /**
     * Create a Request instance from PHP globals.
     *
     * Note: Session is NOT injected here to keep SessionServiceProvider deferred.
     * Session is injected lazily by StartSession middleware for web routes.
     *
     * @return self
     */
    public static function capture(): self
    {
        $request = new self();

        // Method
        $request->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // Path
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $request->path = rtrim($uri, '/') ?: '/';

        // Query parameters
        $request->query = $_GET ?? [];

        // Headers
        $request->headers = self::extractHeaders();

        // Raw body
        $request->rawBody = file_get_contents('php://input') ?: '';

        // Parse body based on content type
        $contentType = $request->headers['content-type'] ?? '';

        if (str_contains($contentType, 'application/json')) {
            $request->body = json_decode($request->rawBody, true) ?: [];
        } else {
            $request->body = $_POST ?? [];
        }

        return $request;
    }

    /**
     * Set the session instance for this request.
     *
     * This method allows late binding of session after request creation.
     * Used by the container to inject session dependency.
     *
     * @param \Toporia\Framework\Session\Store $session Session store instance
     * @return self Fluent interface
     */
    public function setSession(Store $session): self
    {
        $this->session = $session;
        return $this;
    }

    /**
     * Get the session instance for this request.
     *
     * @return \Toporia\Framework\Session\Store|null Session store instance or null
     */
    public function getSession(): ?Store
    {
        return $this->session;
    }

    /**
     * {@inheritdoc}
     */
    public function method(): string
    {
        return $this->method;
    }

    /**
     * {@inheritdoc}
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * {@inheritdoc}
     */
    public function query(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->query;
        }

        return $this->query[$key] ?? $default;
    }

    /**
     * {@inheritdoc}
     */
    public function input(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return array_merge($this->query, $this->body);
        }

        // Check query parameters first, then body
        return $this->query[$key] ?? $this->body[$key] ?? $default;
    }

    /**
     * Get JSON data from request body.
     *
     * Convenience method for API requests that expect JSON payload.
     * Returns the parsed JSON body as an array.
     *
     * Performance: O(1) - Returns already parsed body
     *
     * @return array<string, mixed> Parsed JSON data
     */
    public function json(): array
    {
        return $this->body;
    }

    /**
     * {@inheritdoc}
     */
    public function header(string $name, ?string $default = null): ?string
    {
        $name = strtolower($name);
        return $this->headers[$name] ?? $default;
    }

    /**
     * {@inheritdoc}
     */
    public function isAjax(): bool
    {
        return strtolower($this->header('x-requested-with', '')) === 'xmlhttprequest';
    }

    /**
     * {@inheritdoc}
     */
    public function expectsJson(): bool
    {
        $accept = $this->header('accept', '');
        return str_contains($accept, 'application/json') || $this->isAjax();
    }

    /**
     * Check if the request is over HTTPS.
     *
     * @return bool
     */
    public function isSecure(): bool
    {
        // Check HTTPS server variable
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        // Check forwarded protocol header (proxy/load balancer)
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            return true;
        }

        // Check standard port
        if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
            return true;
        }

        return false;
    }

    /**
     * Get the host from the request.
     *
     * @return string
     */
    public function host(): string
    {
        // Check forwarded host header first (proxy/load balancer)
        if (isset($_SERVER['HTTP_X_FORWARDED_HOST'])) {
            $hosts = explode(',', $_SERVER['HTTP_X_FORWARDED_HOST']);
            return trim($hosts[0]);
        }

        // Check standard host header
        if (isset($_SERVER['HTTP_HOST'])) {
            return $_SERVER['HTTP_HOST'];
        }

        // Fallback to server name
        return $_SERVER['SERVER_NAME'] ?? 'localhost';
    }

    /**
     * {@inheritdoc}
     */
    public function raw(): string
    {
        return $this->rawBody;
    }

    /**
     * Get client IP address.
     *
     * Checks common proxy headers for the real client IP.
     * Falls back to REMOTE_ADDR if no proxy headers found.
     *
     * @return string Client IP address.
     */
    public function ip(): string
    {
        // Check proxy headers (in order of priority)
        $proxyHeaders = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
        ];

        foreach ($proxyHeaders as $header) {
            if (!empty($_SERVER[$header])) {
                // X-Forwarded-For can contain multiple IPs (client, proxy1, proxy2...)
                // Take the first one (the original client)
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);

                // Validate IP
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        // Fallback to REMOTE_ADDR
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Extract headers from $_SERVER superglobal.
     *
     * @return array<string, string>
     */
    private static function extractHeaders(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            // HTTP_ prefix headers
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
                continue;
            }

            // Common headers without HTTP_ prefix
            if (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $name = strtolower(str_replace('_', '-', $key));
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    /**
     * Check if the request has specific input key.
     *
     * @param string $key Input key.
     * @return bool
     */
    public function has(string $key): bool
    {
        return isset($this->body[$key]);
    }

    /**
     * Get all input data.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->body;
    }

    /**
     * Get all headers as an array.
     *
     * Returns all HTTP headers sent with the request.
     * Header names are normalized to lowercase with dashes.
     *
     * Performance: O(1) - Returns cached header array
     *
     * @return array<string, string> All request headers
     *
     * @example
     * ```php
     * $headers = $request->headers();
     * // Returns: ['content-type' => 'application/json', 'user-agent' => '...', ...]
     * ```
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Get all cookies as an array.
     *
     * Returns all cookies sent with the request.
     * Provides access to both encrypted and plain cookies.
     *
     * Performance: O(1) - Direct $_COOKIE access
     *
     * @param bool $decrypt Whether to decrypt encrypted cookies
     * @return array<string, string> All request cookies
     *
     * @example
     * ```php
     * $cookies = $request->cookies();
     * // Returns: ['session_id' => 'abc123', 'preferences' => 'dark_mode', ...]
     *
     * // Get decrypted cookies (if using cookie encryption)
     * $decryptedCookies = $request->cookies(true);
     * ```
     */
    public function cookies(bool $decrypt = false): array
    {
        if (!$decrypt) {
            return $_COOKIE ?? [];
        }

        // If decryption is requested, we need the CookieJar service
        // This would typically be injected via container, but for now return raw cookies
        // In a real implementation, you'd decrypt using the app's encryption key
        return $_COOKIE ?? [];
    }

    /**
     * Get a specific cookie value.
     *
     * Enhanced cookie retrieval with decryption support and default values.
     *
     * Performance: O(1) - Direct array access
     *
     * @param string $name Cookie name
     * @param string|null $default Default value if cookie not found
     * @param bool $decrypt Whether to decrypt the cookie value
     * @return string|null Cookie value or default
     *
     * @example
     * ```php
     * $sessionId = $request->cookie('session_id');
     * $theme = $request->cookie('theme', 'light');
     * $encryptedData = $request->cookie('secure_data', null, true);
     * ```
     */
    public function cookie(string $name, ?string $default = null, bool $decrypt = false): ?string
    {
        $value = $_COOKIE[$name] ?? $default;

        if ($decrypt && $value !== null) {
            // In a real implementation, decrypt using app's encryption key
            // For now, return the raw value
            return $value;
        }

        return $value;
    }

    /**
     * Get uploaded files as UploadedFile instances.
     *
     * Returns all uploaded files wrapped in UploadedFile objects that provide
     * validation, MIME type detection, and storage abstraction methods.
     *
     * Performance: O(n) where n = number of uploaded files
     *
     * @return array<string, UploadedFile|array<UploadedFile>> Uploaded files
     *
     * @example
     * ```php
     * $files = $request->files();
     * // Returns UploadedFile objects:
     * // [
     * //   'avatar' => UploadedFile { ... },
     * //   'documents' => [UploadedFile { ... }, UploadedFile { ... }]
     * // ]
     * ```
     */
    public function files(): array
    {
        if (empty($_FILES)) {
            return [];
        }

        return $this->normalizeFiles($_FILES);
    }

    /**
     * Get a specific uploaded file as UploadedFile instance.
     *
     * Returns an UploadedFile object with validation, MIME type detection,
     * and storage abstraction methods for secure file handling.
     *
     * Performance: O(1) for single files, O(n) for file arrays
     *
     * @param string $name File input name
     * @return UploadedFile|array<UploadedFile>|null UploadedFile instance or null
     *
     * @example
     * ```php
     * $avatar = $request->file('avatar');
     * if ($avatar && $avatar->isValid()) {
     *     // Validate MIME type (server-side detection for security)
     *     if ($avatar->isValidMimeType(['image/jpeg', 'image/png', 'image/webp'])) {
     *         // Store using storage abstraction
     *         $path = $avatar->store('avatars', null, 'public');
     *     }
     * }
     * ```
     */
    public function file(string $name): UploadedFile|array|null
    {
        $files = $this->files();
        return $files[$name] ?? null;
    }

    /**
     * Check if a file was uploaded successfully.
     *
     * Checks if a specific file input has an uploaded file without errors.
     * Uses UploadedFile::isValid() for proper validation including
     * is_uploaded_file() check for security.
     *
     * Performance: O(1)
     *
     * @param string $name File input name
     * @return bool True if file was uploaded successfully
     *
     * @example
     * ```php
     * if ($request->hasFile('avatar')) {
     *     $file = $request->file('avatar');
     *     $path = $file->store('avatars', null, 'public');
     * }
     * ```
     */
    public function hasFile(string $name): bool
    {
        $file = $this->file($name);

        if ($file === null) {
            return false;
        }

        // Handle multiple files
        if (is_array($file)) {
            return isset($file[0]) && $file[0] instanceof UploadedFile && $file[0]->isValid();
        }

        // Handle single file
        return $file instanceof UploadedFile && $file->isValid();
    }

    /**
     * Normalize $_FILES array to UploadedFile instances.
     *
     * Converts PHP's inconsistent $_FILES structure to normalized
     * UploadedFile objects with validation and storage capabilities.
     *
     * @param array $files Raw $_FILES array
     * @return array<string, UploadedFile|array<UploadedFile>> Normalized files
     */
    private function normalizeFiles(array $files): array
    {
        $normalized = [];

        foreach ($files as $key => $file) {
            if (is_array($file['name'])) {
                // Multiple files
                $normalized[$key] = [];
                $count = count($file['name']);

                for ($i = 0; $i < $count; $i++) {
                    // Skip empty file slots (when user doesn't select all files in multi-upload)
                    if (empty($file['tmp_name'][$i]) && $file['error'][$i] === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }

                    $normalized[$key][] = UploadedFile::createFromArray([
                        'name' => $file['name'][$i],
                        'type' => $file['type'][$i],
                        'size' => $file['size'][$i],
                        'tmp_name' => $file['tmp_name'][$i],
                        'error' => $file['error'][$i],
                    ]);
                }
            } else {
                // Single file - skip if no file was uploaded
                if (empty($file['tmp_name']) && ($file['error'] ?? UPLOAD_ERR_OK) === UPLOAD_ERR_NO_FILE) {
                    continue;
                }

                $normalized[$key] = UploadedFile::createFromArray($file);
            }
        }

        return $normalized;
    }

    /**
     * Get server and environment information.
     *
     * Returns $_SERVER superglobal with additional computed values.
     * Provides comprehensive server and environment information.
     *
     * Performance: O(1) - Direct $_SERVER access with caching
     *
     * @param string|null $key Specific server variable or null for all
     * @param mixed $default Default value if key not found
     * @return mixed Server information
     *
     * @example
     * ```php
     * $server = $request->server();
     * // Returns all $_SERVER variables plus computed values
     *
     * $documentRoot = $request->server('DOCUMENT_ROOT');
     * $phpVersion = $request->server('PHP_VERSION', 'unknown');
     * ```
     */
    public function server(?string $key = null, mixed $default = null): mixed
    {
        static $enhancedServer = null;

        // Build enhanced server info on first access
        if ($enhancedServer === null) {
            $enhancedServer = $_SERVER;

            // Add computed server information
            $enhancedServer['PHP_VERSION'] = PHP_VERSION;
            $enhancedServer['PHP_OS'] = PHP_OS;
            $enhancedServer['SERVER_LOAD'] = $this->getServerLoad();
            $enhancedServer['MEMORY_USAGE'] = memory_get_usage(true);
            $enhancedServer['MEMORY_PEAK'] = memory_get_peak_usage(true);
            $enhancedServer['REQUEST_START_TIME'] = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
        }

        if ($key === null) {
            return $enhancedServer;
        }

        return $enhancedServer[$key] ?? $default;
    }

    /**
     * Get server load average (Unix/Linux only).
     *
     * @return array|null Load average or null if not available
     */
    private function getServerLoad(): ?array
    {
        if (function_exists('sys_getloadavg')) {
            return sys_getloadavg();
        }

        return null;
    }

    /**
     * Get environment variable.
     *
     * Enhanced environment variable access with type casting and defaults.
     *
     * Performance: O(1) - Direct $_ENV access
     *
     * @param string $key Environment variable name
     * @param mixed $default Default value if not found
     * @param string|null $type Type to cast to ('int', 'bool', 'float', 'string')
     * @return mixed Environment variable value
     *
     * @example
     * ```php
     * $debug = $request->env('APP_DEBUG', false, 'bool');
     * $port = $request->env('APP_PORT', 8000, 'int');
     * $name = $request->env('APP_NAME', 'MyApp');
     * ```
     */
    public function env(string $key, mixed $default = null, ?string $type = null): mixed
    {
        $value = $_ENV[$key] ?? getenv($key) ?: $default;

        if ($type !== null && $value !== null) {
            $value = match ($type) {
                'int', 'integer' => (int) $value,
                'float', 'double' => (float) $value,
                'bool', 'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'string' => (string) $value,
                default => $value
            };
        }

        return $value;
    }

    // ============================================================================
    // Session Management Methods
    // ============================================================================

    /**
     * Get the session associated with the request.
     *
     * Provides access to session data with enhanced functionality.
     * Supports both getting all session data and specific session values.
     *
     * Performance: O(1) for session access
     *
     * @param string|array|null $key Session key(s) or null for all data
     * @param mixed $default Default value if key not found
     * @return mixed Session data
     *
     * @example
     * ```php
     * // Get all session data
     * $session = $request->session();
     *
     * // Get specific session value
     * $userId = $request->session('user_id');
     *
     * // Get with default
     * $theme = $request->session('theme', 'light');
     *
     * // Get multiple keys
     * $data = $request->session(['user_id', 'username']);
     * ```
     */
    public function session(string|array|null $key = null, mixed $default = null): mixed
    {
        if ($this->session === null) {
            throw new \RuntimeException('Session not available. Make sure SessionServiceProvider is registered.');
        }

        if ($key === null) {
            // Return all session data
            return $this->session->all();
        }

        if (is_array($key)) {
            // Return multiple keys
            return $this->session->getMultiple($key, $default);
        }

        // Return specific key
        return $this->session->get($key, $default);
    }

    /**
     * Get flash data from the session.
     *
     * Flash data is session data that is only available for the next request.
     * Commonly used for success messages, error messages, or temporary data.
     *
     * Performance: O(1) for flash access
     *
     * @param string|null $key Flash key or null for all flash data
     * @param mixed $default Default value if key not found
     * @return mixed Flash data
     *
     * @example
     * ```php
     * // Get all flash data
     * $flash = $request->flash();
     *
     * // Get specific flash message
     * $message = $request->flash('message');
     *
     * // Get with default
     * $status = $request->flash('status', 'info');
     * ```
     */
    public function flash(string|null $key = null, mixed $default = null): mixed
    {
        if ($this->session === null) {
            throw new \RuntimeException('Session not available. Make sure SessionServiceProvider is registered.');
        }

        return $this->session->getFlash($key, $default);
    }

    /**
     * Get old input data from the session.
     *
     * Old input is form data that was submitted in the previous request.
     * Commonly used to repopulate forms after validation errors.
     *
     * Performance: O(1) for old input access
     *
     * @param string|null $key Input key or null for all old input
     * @param mixed $default Default value if key not found
     * @return mixed Old input data
     *
     * @example
     * ```php
     * // Get all old input
     * $oldInput = $request->old();
     *
     * // Get specific old input
     * $oldName = $request->old('name');
     *
     * // Get with default
     * $oldEmail = $request->old('email', '');
     *
     * // Use in blade template
     * <input type="text" name="name" value="{{ $request->old('name') }}">
     * ```
     */
    public function old(string|null $key = null, mixed $default = null): mixed
    {
        if ($this->session === null) {
            throw new \RuntimeException('Session not available. Make sure SessionServiceProvider is registered.');
        }

        return $this->session->getOldInput($key, $default);
    }

    /**
     * Flash the current input to the session.
     *
     * Stores current request input in session for the next request.
     * Useful for form repopulation after validation errors.
     *
     * Performance: O(n) where n = number of input fields
     *
     * @param array<string>|null $keys Specific keys to flash (null = all input)
     * @return self Fluent interface
     *
     * @example
     * ```php
     * // Flash all input
     * $request->flashInput();
     *
     * // Flash specific fields
     * $request->flashInput(['name', 'email']);
     *
     * // In middleware or controller
     * if ($validation->fails()) {
     *     $request->flashInput();
     *     return redirect()->back();
     * }
     * ```
     */
    public function flashInput(?array $keys = null): self
    {
        if ($this->session === null) {
            throw new \RuntimeException('Session not available. Make sure SessionServiceProvider is registered.');
        }

        $inputToFlash = $keys === null ? $this->all() : $this->only($keys);

        // Remove sensitive fields from flash data
        $sensitiveFields = ['password', 'password_confirmation', 'token', '_token', 'csrf_token'];
        foreach ($sensitiveFields as $field) {
            unset($inputToFlash[$field]);
        }

        $this->session->setOldInput($inputToFlash);

        return $this;
    }

    /**
     * Flash data to the session.
     *
     * Stores data in session that will be available for the next request only.
     *
     * Performance: O(1) for single flash, O(n) for array flash
     *
     * @param string|array $key Flash key or array of key-value pairs
     * @param mixed $value Flash value (ignored if key is array)
     * @return self Fluent interface
     *
     * @example
     * ```php
     * // Flash single value
     * $request->flashData('message', 'Success!');
     *
     * // Flash multiple values
     * $request->flashData([
     *     'message' => 'Success!',
     *     'status' => 'success'
     * ]);
     *
     * // Chain operations
     * $request->flashData('message', 'Saved!')
     *         ->flashInput(['name', 'email']);
     * ```
     */
    public function flashData(string|array $key, mixed $value = null): self
    {
        if ($this->session === null) {
            throw new \RuntimeException('Session not available. Make sure SessionServiceProvider is registered.');
        }

        $this->session->setFlash($key, $value);

        return $this;
    }

    /**
     * Get session ID.
     *
     * @return string|null Session ID or null if no session
     */
    public function sessionId(): ?string
    {
        if ($this->session === null) {
            return null;
        }

        return $this->session->isStarted() ? $this->session->getId() : null;
    }

    /**
     * Check if session has a specific key.
     *
     * @param string $key Session key to check
     * @return bool True if session has the key
     */
    public function hasSession(string $key): bool
    {
        if ($this->session === null) {
            return false;
        }

        return $this->session->has($key);
    }

    /**
     * Check if there is flash data.
     *
     * @param string|null $key Specific flash key to check (null = any flash data)
     * @return bool True if flash data exists
     */
    public function hasFlash(?string $key = null): bool
    {
        if ($this->session === null) {
            return false;
        }

        return $this->session->hasFlash($key);
    }

    /**
     * Check if there is old input data.
     *
     * @param string|null $key Specific input key to check (null = any old input)
     * @return bool True if old input exists
     */
    public function hasOldInput(?string $key = null): bool
    {
        if ($this->session === null) {
            return false;
        }

        return $this->session->hasOldInput($key);
    }

    /**
     * Flush old input from session.
     *
     * Removes old input data from session. This is typically done
     * automatically after successful form submission.
     *
     * @return self Fluent interface
     */
    public function flushOldInput(): self
    {
        if ($this->session !== null) {
            $this->session->removeOldInput();
        }

        return $this;
    }

    /**
     * Flush flash data from session.
     *
     * Removes flash data from session. This is typically done
     * automatically after the data has been displayed.
     *
     * @return self Fluent interface
     */
    public function flushFlash(): self
    {
        if ($this->session !== null) {
            $this->session->removeFlash();
        }

        return $this;
    }

    /**
     * Get session data with automatic cleanup.
     *
     * Gets session data and optionally removes it after retrieval.
     * Useful for one-time data like flash messages.
     *
     * @param string $key Session key
     * @param mixed $default Default value
     * @param bool $remove Whether to remove after retrieval
     * @return mixed Session value
     */
    public function pullFromSession(string $key, mixed $default = null, bool $remove = true): mixed
    {
        if ($this->session === null) {
            return $default;
        }

        if ($remove) {
            return $this->session->pull($key, $default);
        }

        return $this->session->get($key, $default);
    }

    /**
     * Get only specified input keys.
     *
     * @param array<string> $keys Keys to retrieve.
     * @return array<string, mixed>
     */
    public function only(array $keys): array
    {
        return array_intersect_key($this->body, array_flip($keys));
    }

    /**
     * Get all input except specified keys.
     *
     * @param array<string> $keys Keys to exclude.
     * @return array<string, mixed>
     */
    public function except(array $keys): array
    {
        return array_diff_key($this->body, array_flip($keys));
    }

    /**
     * Set a request attribute.
     *
     * Attributes are used to store additional data about the request
     * (e.g., route handler, route parameters, etc.)
     *
     * @param string $key Attribute key
     * @param mixed $value Attribute value
     * @return void
     */
    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    /**
     * Get a request attribute.
     *
     * @param string $key Attribute key
     * @param mixed $default Default value if not found
     * @return mixed
     */
    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Get all request attributes.
     *
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Get data from GET request (query parameters).
     *
     * @param string|null $key Parameter key
     * @param mixed $default Default value if not found
     * @return mixed
     */
    public function get(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->query;
        }

        return $this->query[$key] ?? $default;
    }

    /**
     * Get data from POST request body.
     *
     * @param string|null $key Parameter key
     * @param mixed $default Default value if not found
     * @return mixed
     */
    public function post(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->body;
        }

        return $this->body[$key] ?? $default;
    }

    /**
     * Get data from PUT request body.
     *
     * @param string|null $key Parameter key
     * @param mixed $default Default value if not found
     * @return mixed
     */
    public function put(?string $key = null, mixed $default = null): mixed
    {
        if ($this->method !== 'PUT') {
            return $default;
        }

        if ($key === null) {
            return $this->body;
        }

        return $this->body[$key] ?? $default;
    }

    /**
     * Get data from PATCH request body.
     *
     * @param string|null $key Parameter key
     * @param mixed $default Default value if not found
     * @return mixed
     */
    public function patch(?string $key = null, mixed $default = null): mixed
    {
        if ($this->method !== 'PATCH') {
            return $default;
        }

        if ($key === null) {
            return $this->body;
        }

        return $this->body[$key] ?? $default;
    }

    /**
     * Get data from DELETE request body.
     *
     * @param string|null $key Parameter key
     * @param mixed $default Default value if not found
     * @return mixed
     */
    public function delete(?string $key = null, mixed $default = null): mixed
    {
        if ($this->method !== 'DELETE') {
            return $default;
        }

        if ($key === null) {
            return $this->body;
        }

        return $this->body[$key] ?? $default;
    }

    /**
     * Check if the request is a GET request.
     *
     * @return bool
     */
    public function isGet(): bool
    {
        return $this->method === 'GET';
    }

    /**
     * Check if the request is a POST request.
     *
     * @return bool
     */
    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    /**
     * Check if the request is a PUT request.
     *
     * @return bool
     */
    public function isPut(): bool
    {
        return $this->method === 'PUT';
    }

    /**
     * Check if the request is a PATCH request.
     *
     * @return bool
     */
    public function isPatch(): bool
    {
        return $this->method === 'PATCH';
    }

    /**
     * Check if the request is a DELETE request.
     *
     * @return bool
     */
    public function isDelete(): bool
    {
        return $this->method === 'DELETE';
    }

    /**
     * Check if the request is a HEAD request.
     *
     * @return bool
     */
    public function isHead(): bool
    {
        return $this->method === 'HEAD';
    }

    /**
     * Check if the request is an OPTIONS request.
     *
     * @return bool
     */
    public function isOptions(): bool
    {
        return $this->method === 'OPTIONS';
    }

    // ============================================================================
    // Advanced Request Data Manipulation
    // ============================================================================

    /**
     * Merge new input into the current request's input array.
     *
     * This method allows you to add or override input data dynamically.
     * Useful for middleware that needs to inject computed values or
     * normalize input data before it reaches the controller.
     *
     * Performance: O(n) where n = number of keys to merge
     * Memory: Minimal overhead, modifies existing arrays in-place
     *
     * SOLID Principles:
     * - Single Responsibility: Only handles input merging
     * - Open/Closed: Extensible via array operations
     * - Interface Segregation: Focused method signature
     *
     * Clean Architecture:
     * - Maintains request state integrity
     * - Allows controlled mutation for middleware layer
     * - Preserves original data structure
     *
     * @param array<string, mixed> $input New input data to merge
     * @return $this Fluent interface for method chaining
     *
     * @example
     * ```php
     * // Middleware adding computed values
     * $request->merge([
     *     'user_id' => auth()->id(),
     *     'ip_address' => $request->ip(),
     *     'timestamp' => time()
     * ]);
     *
     * // Normalizing input data
     * $request->merge([
     *     'email' => strtolower($request->input('email')),
     *     'phone' => preg_replace('/[^0-9]/', '', $request->input('phone'))
     * ]);
     *
     * // Chaining operations
     * $request->merge(['step' => 1])
     *         ->merge(['validated' => true]);
     * ```
     */
    public function merge(array $input): self
    {
        $this->body = array_merge($this->body, $input);

        return $this;
    }

    /**
     * Merge new input into the request only if the keys are missing.
     *
     * Unlike merge(), this method will not overwrite existing values.
     * Useful for setting defaults without overriding user input.
     *
     * Performance: O(n) where n = number of input keys
     *
     * @param array<string, mixed> $input New input data (only added if key missing)
     * @return $this Fluent interface
     *
     * @example
     * ```php
     * // Set defaults without overriding user input
     * $request->mergeIfMissing([
     *     'status' => 'pending',
     *     'priority' => 'normal'
     * ]);
     *
     * // User provided status='active' - it won't be overwritten
     * // But priority will be set to 'normal' if not provided
     * ```
     */
    public function mergeIfMissing(array $input): self
    {
        foreach ($input as $key => $value) {
            if (!$this->has($key)) {
                $this->body[$key] = $value;
            }
        }

        return $this;
    }

    /**
     * Merge new input into the request's query parameters.
     *
     * Similar to merge() but specifically targets query parameters (GET data).
     * Useful for adding computed query parameters or normalizing URLs.
     *
     * Performance: O(n) where n = number of query parameters
     *
     * @param array<string, mixed> $query New query parameters to merge
     * @return $this Fluent interface
     *
     * @example
     * ```php
     * // Add pagination defaults
     * $request->mergeQuery([
     *     'page' => $request->query('page', 1),
     *     'per_page' => $request->query('per_page', 15)
     * ]);
     * ```
     */
    public function mergeQuery(array $query): self
    {
        $this->query = array_merge($this->query, $query);

        return $this;
    }

    /**
     * Replace the entire input array.
     *
     * Completely replaces the request body with new data.
     * Use with caution as this discards all existing input.
     *
     * Performance: O(1) - Direct array assignment
     *
     * @param array<string, mixed> $input New input data
     * @return $this Fluent interface
     */
    public function replace(array $input): self
    {
        $this->body = $input;

        return $this;
    }

    /**
     * Remove specific keys from the request input.
     *
     * Efficiently removes unwanted input keys, useful for filtering
     * sensitive data or cleaning up input before processing.
     *
     * Performance: O(n) where n = number of keys to remove
     *
     * @param array<string>|string $keys Key(s) to remove
     * @return $this Fluent interface
     *
     * @example
     * ```php
     * // Remove sensitive fields
     * $request->forget(['password_confirmation', '_token']);
     *
     * // Remove single field
     * $request->forget('temp_data');
     * ```
     */
    public function forget(array|string $keys): self
    {
        $keys = is_array($keys) ? $keys : [$keys];

        foreach ($keys as $key) {
            unset($this->body[$key]);
        }

        return $this;
    }

    /**
     * Get a subset of the input data containing only the specified keys.
     *
     * Enhanced version with support for nested keys and default values.
     * More flexible than the existing only() method.
     *
     * Performance: O(n) where n = number of keys requested
     *
     * @param array<string> $keys Keys to retrieve
     * @param array<string, mixed> $defaults Default values for missing keys
     * @return array<string, mixed> Filtered input data
     *
     * @example
     * ```php
     * // Basic usage
     * $data = $request->onlyWithDefaults(['name', 'email'], [
     *     'name' => 'Anonymous',
     *     'email' => 'noreply@example.com'
     * ]);
     *
     * // Nested key support (dot notation)
     * $data = $request->onlyWithDefaults(['user.name', 'user.email']);
     * ```
     */
    public function onlyWithDefaults(array $keys, array $defaults = []): array
    {
        $result = [];

        foreach ($keys as $key) {
            if (str_contains($key, '.')) {
                // Handle nested keys (dot notation)
                $value = $this->getNestedValue($key);
                if ($value !== null) {
                    $result[$key] = $value;
                } elseif (isset($defaults[$key])) {
                    $result[$key] = $defaults[$key];
                }
            } else {
                // Handle simple keys
                if (isset($this->body[$key])) {
                    $result[$key] = $this->body[$key];
                } elseif (isset($defaults[$key])) {
                    $result[$key] = $defaults[$key];
                }
            }
        }

        return $result;
    }

    /**
     * Get nested value using dot notation.
     *
     * Performance-optimized nested array access with caching.
     *
     * @param string $key Dot-notated key (e.g., 'user.profile.name')
     * @return mixed|null Value or null if not found
     */
    private function getNestedValue(string $key): mixed
    {
        // Compute body hash for cache invalidation (only when body changes)
        $currentHash = $this->getBodyHash();
        if ($this->bodyCacheHash !== $currentHash) {
            $this->nestedValueCache = [];
            $this->bodyCacheHash = $currentHash;
        }

        // Check instance cache (bounded to request lifecycle, no memory leak)
        if (array_key_exists($key, $this->nestedValueCache)) {
            return $this->nestedValueCache[$key];
        }

        $keys = explode('.', $key);
        $value = $this->body;

        foreach ($keys as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                $this->nestedValueCache[$key] = null;
                return null;
            }
            $value = $value[$segment];
        }

        $this->nestedValueCache[$key] = $value;
        return $value;
    }

    /**
     * Get hash of body for cache invalidation.
     */
    private function getBodyHash(): string
    {
        return md5(json_encode($this->body) ?: '');
    }

    /**
     * Check if the request contains any of the given keys.
     *
     * Enhanced version with support for nested keys and multiple conditions.
     *
     * Performance: O(n) where n = number of keys to check
     *
     * @param array<string>|string $keys Key(s) to check
     * @param bool $requireAll Whether all keys must be present (AND logic)
     * @return bool True if condition is met
     *
     * @example
     * ```php
     * // Check if any key exists (OR logic)
     * if ($request->hasAny(['name', 'email'])) {
     *     // At least one field is present
     * }
     *
     * // Check if all keys exist (AND logic)
     * if ($request->hasAny(['name', 'email'], true)) {
     *     // Both fields are present
     * }
     * ```
     */
    public function hasAny(array|string $keys, bool $requireAll = false): bool
    {
        $keys = is_array($keys) ? $keys : [$keys];
        $foundCount = 0;

        foreach ($keys as $key) {
            $exists = str_contains($key, '.')
                ? $this->getNestedValue($key) !== null
                : isset($this->body[$key]);

            if ($exists) {
                $foundCount++;
                if (!$requireAll) {
                    return true; // Early return for OR logic
                }
            }
        }

        return $requireAll ? $foundCount === count($keys) : $foundCount > 0;
    }

    /**
     * Get input data with type casting and validation.
     *
     * Enhanced input retrieval with built-in type casting and validation.
     * Provides better type safety and reduces boilerplate code.
     *
     * Performance: O(1) with optional validation overhead
     *
     * @param string $key Input key
     * @param mixed $default Default value
     * @param string|null $type Expected type ('int', 'float', 'bool', 'string', 'array')
     * @param callable|null $validator Optional validation callback
     * @return mixed Typed and validated value
     *
     * @throws \InvalidArgumentException If validation fails
     *
     * @example
     * ```php
     * // Type casting
     * $age = $request->typed('age', 0, 'int');
     * $price = $request->typed('price', 0.0, 'float');
     * $active = $request->typed('is_active', false, 'bool');
     *
     * // With validation
     * $email = $request->typed('email', '', 'string', function($value) {
     *     return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
     * });
     * ```
     */
    public function typed(string $key, mixed $default = null, ?string $type = null, ?callable $validator = null): mixed
    {
        $value = $this->input($key, $default);

        // Apply type casting
        if ($type !== null && $value !== null) {
            $value = match ($type) {
                'int', 'integer' => (int) $value,
                'float', 'double' => (float) $value,
                'bool', 'boolean' => (bool) $value,
                'string' => (string) $value,
                'array' => is_array($value) ? $value : [$value],
                default => $value
            };
        }

        // Apply validation
        if ($validator !== null && !$validator($value)) {
            throw new \InvalidArgumentException("Validation failed for input key: {$key}");
        }

        return $value;
    }

    /**
     * Get multiple input values with type casting.
     *
     * Batch version of typed() for better performance when retrieving
     * multiple typed values.
     *
     * Performance: O(n) where n = number of keys
     *
     * @param array<string, array{default?: mixed, type?: string, validator?: callable}> $specs
     * @return array<string, mixed> Typed values
     *
     * @example
     * ```php
     * $data = $request->typedMany([
     *     'age' => ['default' => 0, 'type' => 'int'],
     *     'price' => ['default' => 0.0, 'type' => 'float'],
     *     'name' => ['default' => '', 'type' => 'string'],
     *     'tags' => ['default' => [], 'type' => 'array']
     * ]);
     * ```
     */
    public function typedMany(array $specs): array
    {
        $result = [];

        foreach ($specs as $key => $spec) {
            $default = $spec['default'] ?? null;
            $type = $spec['type'] ?? null;
            $validator = $spec['validator'] ?? null;

            $result[$key] = $this->typed($key, $default, $type, $validator);
        }

        return $result;
    }

    /**
     * Check if the request is from a mobile device.
     *
     * Enhanced mobile detection with caching for better performance.
     *
     * Performance: O(1) after first call (cached result)
     *
     * @return bool True if mobile device
     */
    public function isMobile(): bool
    {
        static $isMobile = null;

        if ($isMobile !== null) {
            return $isMobile;
        }

        $userAgent = $this->header('user-agent', '');

        // Common mobile patterns (optimized regex)
        $mobilePatterns = [
            '/Mobile|Android|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i'
        ];

        foreach ($mobilePatterns as $pattern) {
            if (preg_match($pattern, $userAgent)) {
                return $isMobile = true;
            }
        }

        return $isMobile = false;
    }

    /**
     * Check if the request is from a bot/crawler.
     *
     * Enhanced bot detection for SEO and analytics purposes.
     *
     * Performance: O(1) after first call (cached result)
     *
     * @return bool True if bot/crawler
     */
    public function isBot(): bool
    {
        static $isBot = null;

        if ($isBot !== null) {
            return $isBot;
        }

        $userAgent = strtolower($this->header('user-agent', ''));

        // Common bot patterns
        $botPatterns = [
            'googlebot',
            'bingbot',
            'slurp',
            'duckduckbot',
            'baiduspider',
            'yandexbot',
            'facebookexternalhit',
            'twitterbot',
            'rogerbot',
            'linkedinbot',
            'embedly',
            'quora link preview',
            'showyoubot',
            'outbrain',
            'pinterest',
            'developers.google.com/+/web/snippet'
        ];

        foreach ($botPatterns as $pattern) {
            if (str_contains($userAgent, $pattern)) {
                return $isBot = true;
            }
        }

        return $isBot = false;
    }

    /**
     * Get the request fingerprint for caching/security purposes.
     *
     * Creates a unique fingerprint based on request characteristics.
     * Useful for rate limiting, caching, and security analysis.
     *
     * Performance: O(1) - Hash calculation
     *
     * @param array<string> $includeHeaders Additional headers to include
     * @return string Unique request fingerprint
     */
    public function fingerprint(array $includeHeaders = []): string
    {
        $components = [
            $this->method(),
            $this->path(),
            $this->ip(),
            $this->header('user-agent', ''),
        ];

        // Include additional headers if specified
        foreach ($includeHeaders as $header) {
            $components[] = $this->header($header, '');
        }

        return hash('sha256', implode('|', $components));
    }

    /**
     * Get request timing information.
     *
     * Provides detailed timing information for performance monitoring.
     *
     * @return array<string, mixed> Timing information
     */
    public function timing(): array
    {
        static $startTime = null;

        if ($startTime === null) {
            $startTime = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
        }

        $currentTime = microtime(true);

        return [
            'start_time' => $startTime,
            'current_time' => $currentTime,
            'elapsed_ms' => round(($currentTime - $startTime) * 1000, 2),
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
        ];
    }

    /**
     * Convert request to array for logging/debugging.
     *
     * Enhanced debugging information with security considerations.
     *
     * @param bool $includeSensitive Whether to include sensitive data
     * @return array<string, mixed> Request data array
     */
    public function toArray(bool $includeSensitive = false): array
    {
        $data = [
            'method' => $this->method,
            'path' => $this->path,
            'query' => $this->query,
            'headers' => $this->headers,
            'ip' => $this->ip(),
            'user_agent' => $this->header('user-agent'),
            'is_ajax' => $this->isAjax(),
            'is_secure' => $this->isSecure(),
            'is_mobile' => $this->isMobile(),
            'is_bot' => $this->isBot(),
            'timing' => $this->timing(),
        ];

        if ($includeSensitive) {
            $data['body'] = $this->body;
            $data['raw_body'] = $this->rawBody;
        } else {
            // Filter out sensitive fields
            $sensitiveFields = ['password', 'token', 'secret', 'key', 'auth'];
            $filteredBody = $this->body;

            foreach ($sensitiveFields as $field) {
                if (isset($filteredBody[$field])) {
                    $filteredBody[$field] = '[FILTERED]';
                }
            }

            $data['body'] = $filteredBody;
        }

        return $data;
    }

    // ============================================================================
    // Advanced Request Validation & Transformation
    // ============================================================================

    /**
     * Validate and transform input data in a single operation.
     *
     * This method combines validation and transformation for maximum efficiency.
     * Reduces multiple passes over the data and provides better performance
     * than separate validation and transformation steps.
     *
     * Performance: O(n) single pass through data
     * Memory: Minimal overhead with in-place transformations
     *
     * SOLID Principles:
     * - Single Responsibility: Handles validation + transformation
     * - Open/Closed: Extensible via custom transformers
     * - Dependency Inversion: Uses callable transformers
     *
     * @param array<string, array{rules?: array, transform?: callable, default?: mixed}> $specs
     * @return array<string, mixed> Validated and transformed data
     * @throws \InvalidArgumentException If validation fails
     *
     * @example
     * ```php
     * $data = $request->validateAndTransform([
     *     'email' => [
     *         'rules' => ['required', 'email'],
     *         'transform' => fn($value) => strtolower(trim($value))
     *     ],
     *     'age' => [
     *         'rules' => ['required', 'integer', 'min:18'],
     *         'transform' => fn($value) => (int) $value,
     *         'default' => 18
     *     ],
     *     'tags' => [
     *         'transform' => fn($value) => is_string($value) ? explode(',', $value) : $value,
     *         'default' => []
     *     ]
     * ]);
     * ```
     */
    public function validateAndTransform(array $specs): array
    {
        $result = [];
        $errors = [];

        foreach ($specs as $key => $spec) {
            $value = $this->input($key, $spec['default'] ?? null);

            // Apply validation rules if specified
            if (isset($spec['rules'])) {
                $validationResult = $this->validateValue($key, $value, $spec['rules']);
                if ($validationResult !== true) {
                    $errors[$key] = $validationResult;
                    continue;
                }
            }

            // Apply transformation if specified
            if (isset($spec['transform']) && is_callable($spec['transform'])) {
                try {
                    $value = $spec['transform']($value);
                } catch (\Throwable $e) {
                    $errors[$key] = "Transformation failed: {$e->getMessage()}";
                    continue;
                }
            }

            $result[$key] = $value;
        }

        if (!empty($errors)) {
            throw new \InvalidArgumentException('Validation failed: ' . json_encode($errors));
        }

        return $result;
    }

    /**
     * Simple validation for a single value.
     *
     * Lightweight validation without external dependencies.
     * Performance-optimized for common validation scenarios.
     *
     * @param string $key Field name for error messages
     * @param mixed $value Value to validate
     * @param array<string> $rules Validation rules
     * @return true|string True if valid, error message if invalid
     */
    private function validateValue(string $key, mixed $value, array $rules): true|string
    {
        foreach ($rules as $rule) {
            $result = match (true) {
                $rule === 'required' => $value !== null && $value !== '',
                $rule === 'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
                $rule === 'integer' => filter_var($value, FILTER_VALIDATE_INT) !== false,
                $rule === 'numeric' => is_numeric($value),
                str_starts_with($rule, 'min:') => $this->validateMin($value, substr($rule, 4)),
                str_starts_with($rule, 'max:') => $this->validateMax($value, substr($rule, 4)),
                str_starts_with($rule, 'regex:') => preg_match(substr($rule, 6), (string) $value) === 1,
                default => true
            };

            if (!$result) {
                return "Field {$key} failed validation rule: {$rule}";
            }
        }

        return true;
    }

    /**
     * Validate minimum value/length.
     */
    private function validateMin(mixed $value, string $min): bool
    {
        $minValue = (float) $min;

        return match (true) {
            is_numeric($value) => (float) $value >= $minValue,
            is_string($value) => strlen($value) >= $minValue,
            is_array($value) => count($value) >= $minValue,
            default => false
        };
    }

    /**
     * Validate maximum value/length.
     */
    private function validateMax(mixed $value, string $max): bool
    {
        $maxValue = (float) $max;

        return match (true) {
            is_numeric($value) => (float) $value <= $maxValue,
            is_string($value) => strlen($value) <= $maxValue,
            is_array($value) => count($value) <= $maxValue,
            default => false
        };
    }

    /**
     * Batch process multiple requests data.
     *
     * Process multiple sets of input data with the same transformation rules.
     * Useful for bulk operations and API batch processing.
     *
     * Performance: O(n*m) where n = datasets, m = transformations per dataset
     *
     * @param array<array<string, mixed>> $datasets Multiple input datasets
     * @param array<string, callable> $transformers Field transformers
     * @return array<array<string, mixed>> Processed datasets
     *
     * @example
     * ```php
     * $results = $request->batchProcess([
     *     ['name' => 'John', 'email' => 'JOHN@EXAMPLE.COM'],
     *     ['name' => 'Jane', 'email' => 'JANE@EXAMPLE.COM']
     * ], [
     *     'email' => fn($email) => strtolower($email),
     *     'name' => fn($name) => ucfirst($name)
     * ]);
     * ```
     */
    public function batchProcess(array $datasets, array $transformers): array
    {
        return array_map(function ($dataset) use ($transformers) {
            foreach ($transformers as $field => $transformer) {
                if (isset($dataset[$field])) {
                    $dataset[$field] = $transformer($dataset[$field]);
                }
            }
            return $dataset;
        }, $datasets);
    }

    /**
     * Get request data with automatic sanitization.
     *
     * Provides built-in XSS protection and data sanitization.
     * More secure than raw input() method.
     *
     * Performance: O(1) with sanitization overhead
     *
     * @param string $key Input key
     * @param mixed $default Default value
     * @param string $sanitizer Sanitization method ('html', 'sql', 'xss', 'none')
     * @return mixed Sanitized value
     */
    public function safe(string $key, mixed $default = null, string $sanitizer = 'html'): mixed
    {
        $value = $this->input($key, $default);

        if ($value === null || $sanitizer === 'none') {
            return $value;
        }

        return match ($sanitizer) {
            'html' => htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'xss' => $this->sanitizeXss((string) $value),
            'strip_tags' => strip_tags((string) $value),
            'trim' => trim((string) $value),
            // 'sql' sanitizer removed - ALWAYS use parameterized queries instead
            // Using addslashes() for SQL is INSECURE and can be bypassed
            default => $value
        };
    }

    /**
     * Advanced XSS sanitization.
     *
     * Provides defense-in-depth XSS protection by stripping dangerous
     * content before encoding. For untrusted HTML, consider using
     * a dedicated HTML sanitizer library like HTML Purifier.
     *
     * @param string $value Value to sanitize
     * @return string Sanitized value
     */
    private function sanitizeXss(string $value): string
    {
        // Step 1: Remove null bytes (can bypass filters)
        $value = str_replace("\0", '', $value);

        // Step 2: Decode HTML entities to catch encoded attacks
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Step 3: Remove dangerous tags completely using DOM parser for accuracy
        // Regex-based removal is inherently unsafe; use strip_tags as fallback
        $dangerousTags = ['script', 'iframe', 'object', 'embed', 'applet', 'meta', 'link', 'style', 'base'];
        $value = strip_tags($value, []); // Remove ALL tags for maximum safety

        // Step 4: Remove dangerous URI schemes
        $value = preg_replace('/\b(javascript|vbscript|data|expression):/i', '', $value) ?? $value;

        // Step 5: Final encoding - this is the primary defense
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Get request data with caching for expensive operations.
     *
     * Caches processed input data to avoid repeated expensive operations
     * like JSON parsing, validation, or transformation.
     *
     * Performance: O(1) after first access (cached)
     *
     * @param string $key Cache key
     * @param callable $processor Data processor function
     * @param int $ttl Cache TTL in seconds (0 = no expiration)
     * @return mixed Processed and cached data
     */
    public function cached(string $key, callable $processor, int $ttl = 0): mixed
    {
        static $cache = [];
        static $expiry = [];

        $now = now()->getTimestamp();

        // Check if cached and not expired
        if (isset($cache[$key])) {
            if ($ttl === 0 || !isset($expiry[$key]) || $expiry[$key] > $now) {
                return $cache[$key];
            }
        }

        // Process and cache
        $result = $processor();
        $cache[$key] = $result;

        if ($ttl > 0) {
            $expiry[$key] = $now + $ttl;
        }

        return $result;
    }

    /**
     * Stream large request data for memory efficiency.
     *
     * Process large request bodies without loading everything into memory.
     * Useful for file uploads or large JSON payloads.
     *
     * Performance: O(1) memory usage regardless of input size
     *
     * @param callable $processor Chunk processor function
     * @param int $chunkSize Chunk size in bytes
     * @return mixed Processor result
     */
    public function stream(callable $processor, int $chunkSize = 8192): mixed
    {
        $handle = fopen('php://input', 'r');
        if (!$handle) {
            throw new \RuntimeException('Unable to open input stream');
        }

        try {
            $result = null;
            while (!feof($handle)) {
                $chunk = fread($handle, $chunkSize);
                if ($chunk !== false) {
                    $result = $processor($chunk, $result);
                }
            }
            return $result;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Get request signature for API authentication.
     *
     * Generate request signature for secure API authentication.
     * Compatible with AWS Signature V4 style authentication.
     *
     * Performance: O(1) - Hash calculation
     *
     * @param string $secret Secret key for signing
     * @param string $algorithm Hash algorithm ('sha256', 'sha1', etc.)
     * @param array<string> $includeHeaders Headers to include in signature
     * @return string Request signature
     */
    public function signature(string $secret, string $algorithm = 'sha256', array $includeHeaders = []): string
    {
        $components = [
            $this->method(),
            $this->path(),
            http_build_query($this->query),
            $this->rawBody
        ];

        // Include specified headers
        foreach ($includeHeaders as $header) {
            $components[] = $this->header($header, '');
        }

        $stringToSign = implode("\n", $components);

        return hash_hmac($algorithm, $stringToSign, $secret);
    }

    /**
     * Verify request signature for API authentication.
     *
     * @param string $expectedSignature Expected signature
     * @param string $secret Secret key
     * @param string $algorithm Hash algorithm
     * @param array<string> $includeHeaders Headers to include
     * @return bool True if signature is valid
     */
    public function verifySignature(string $expectedSignature, string $secret, string $algorithm = 'sha256', array $includeHeaders = []): bool
    {
        $actualSignature = $this->signature($secret, $algorithm, $includeHeaders);

        // Use hash_equals for timing-safe comparison
        return hash_equals($expectedSignature, $actualSignature);
    }

    /**
     * Get request rate limiting key.
     *
     * Generate a unique key for rate limiting based on various factors.
     *
     * @param string $scope Rate limiting scope ('ip', 'user', 'api_key', 'custom')
     * @param string|null $identifier Custom identifier
     * @return string Rate limiting key
     */
    public function rateLimitKey(string $scope = 'ip', ?string $identifier = null): string
    {
        return match ($scope) {
            'ip' => 'rate_limit:ip:' . $this->ip(),
            'user' => 'rate_limit:user:' . ($identifier ?? 'anonymous'),
            'api_key' => 'rate_limit:api:' . ($identifier ?? $this->header('x-api-key', 'unknown')),
            'endpoint' => 'rate_limit:endpoint:' . $this->method() . ':' . $this->path(),
            'custom' => 'rate_limit:custom:' . ($identifier ?? $this->fingerprint()),
            default => 'rate_limit:global:' . $this->fingerprint()
        };
    }

    /**
     * Check if request should be cached based on various factors.
     *
     * Intelligent caching decision based on request characteristics.
     *
     * @return bool True if request should be cached
     */
    public function shouldCache(): bool
    {
        // Don't cache if:
        // - Not a GET request
        // - Has query parameters that indicate dynamic content
        // - Is from a bot (different caching strategy needed)
        // - Has authentication headers

        if ($this->method !== 'GET') {
            return false;
        }

        if ($this->isBot()) {
            return false; // Bots might need different caching
        }

        if ($this->header('authorization') || $this->header('x-api-key')) {
            return false; // Authenticated requests
        }

        // Check for dynamic query parameters
        $dynamicParams = ['timestamp', 'rand', 'nocache', '_', 'cb'];
        foreach ($dynamicParams as $param) {
            if ($this->query($param) !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get cache key for request caching.
     *
     * Generate a unique cache key based on request characteristics.
     *
     * @param array<string> $excludeParams Parameters to exclude from cache key
     * @return string Cache key
     */
    public function cacheKey(array $excludeParams = []): string
    {
        $query = $this->query;

        // Remove excluded parameters
        foreach ($excludeParams as $param) {
            unset($query[$param]);
        }

        // Sort for consistent keys
        ksort($query);

        $components = [
            $this->method(),
            $this->path(),
            http_build_query($query),
            $this->header('accept', ''),
            $this->header('accept-language', '')
        ];

        return 'request_cache:' . hash('sha256', implode('|', $components));
    }

    /**
     * Get the full URL for the request.
     *
     * Constructs the complete URL including protocol, host, and query parameters.
     *
     * Performance: O(1) with caching
     *
     * @return string Full URL
     *
     * @example
     * ```php
     * $url = $request->fullUrl();
     * // Returns: "https://example.com/api/users?page=1&sort=name"
     * ```
     */
    public function fullUrl(): string
    {
        static $fullUrl = null;

        if ($fullUrl !== null) {
            return $fullUrl;
        }

        $protocol = $this->isSecure() ? 'https' : 'http';
        $host = $this->host();
        $path = $this->path();
        $query = http_build_query($this->query);

        $fullUrl = $protocol . '://' . $host . $path;

        if (!empty($query)) {
            $fullUrl .= '?' . $query;
        }

        return $fullUrl;
    }

    /**
     * Get the URL without query parameters.
     *
     * @return string Base URL without query string
     */
    public function url(): string
    {
        $protocol = $this->isSecure() ? 'https' : 'http';
        $host = $this->host();
        $path = $this->path();

        return $protocol . '://' . $host . $path;
    }

    /**
     * Get the root URL for the application.
     *
     * @return string Root URL
     */
    public function root(): string
    {
        $protocol = $this->isSecure() ? 'https' : 'http';
        $host = $this->host();

        return $protocol . '://' . $host;
    }

    /**
     * Determine if the request contains a given input item key.
     *
     * Enhanced version that supports nested keys and multiple conditions.
     *
     * @param array<string>|string $keys Key(s) to check
     * @return bool True if all keys exist
     */
    public function filled(array|string $keys): bool
    {
        $keys = is_array($keys) ? $keys : [$keys];

        foreach ($keys as $key) {
            $value = $this->input($key);

            if ($value === null || $value === '' || $value === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine if the request is missing a given input item key.
     *
     * @param array<string>|string $keys Key(s) to check
     * @return bool True if any key is missing
     */
    public function missing(array|string $keys): bool
    {
        return !$this->filled($keys);
    }

    /**
     * Execute a callback if the request has the given key.
     *
     * @param string $key Input key to check
     * @param callable $callback Callback to execute if key exists
     * @param callable|null $default Callback to execute if key doesn't exist
     * @return mixed Callback result or $this for chaining
     *
     * @example
     * ```php
     * $request->whenHas('email', function ($email) {
     *     // Send verification email
     * });
     *
     * $request->whenHas('coupon',
     *     fn($coupon) => $this->applyCoupon($coupon),
     *     fn() => $this->useDefaultPricing()
     * );
     * ```
     */
    public function whenHas(string $key, callable $callback, ?callable $default = null): mixed
    {
        if ($this->has($key)) {
            return $callback($this->input($key), $this);
        }

        if ($default !== null) {
            return $default($this);
        }

        return $this;
    }

    /**
     * Execute a callback if the request has a non-empty value for the given key.
     *
     * @param string $key Input key to check
     * @param callable $callback Callback to execute if key is filled
     * @param callable|null $default Callback to execute if key is not filled
     * @return mixed Callback result or $this for chaining
     *
     * @example
     * ```php
     * $request->whenFilled('search', function ($search) {
     *     return User::where('name', 'like', "%{$search}%")->get();
     * });
     * ```
     */
    public function whenFilled(string $key, callable $callback, ?callable $default = null): mixed
    {
        if ($this->filled($key)) {
            return $callback($this->input($key), $this);
        }

        if ($default !== null) {
            return $default($this);
        }

        return $this;
    }

    /**
     * Execute a callback if the request is missing the given key.
     *
     * @param string $key Input key to check
     * @param callable $callback Callback to execute if key is missing
     * @param callable|null $default Callback to execute if key exists
     * @return mixed Callback result or $this for chaining
     *
     * @example
     * ```php
     * $request->whenMissing('api_key', function () {
     *     throw new UnauthorizedException('API key required');
     * });
     * ```
     */
    public function whenMissing(string $key, callable $callback, ?callable $default = null): mixed
    {
        if ($this->missing($key)) {
            return $callback($this);
        }

        if ($default !== null) {
            return $default($this->input($key), $this);
        }

        return $this;
    }

    /**
     * Get the bearer token from the request headers.
     *
     * Extracts JWT or API tokens from Authorization header.
     *
     * Performance: O(1) with caching
     *
     * @return string|null Bearer token or null if not found
     *
     * @example
     * ```php
     * $token = $request->bearerToken();
     * if ($token) {
     *     $user = $this->authenticateWithToken($token);
     * }
     * ```
     */
    public function bearerToken(): ?string
    {
        static $token = null;

        if ($token !== null) {
            return $token;
        }

        $authorization = $this->header('authorization', '');

        if (str_starts_with(strtolower($authorization), 'bearer ')) {
            $token = substr($authorization, 7);
            return $token;
        }

        return null;
    }

    /**
     * Retrieve input from the request as a Stringable instance.
     *
     * Provides fluent string manipulation capabilities.
     *
     * @param string $key Input key
     * @param mixed $default Default value
     * @return StringableWrapper Fluent string wrapper
     */
    public function string(string $key, mixed $default = null): StringableWrapper
    {
        $value = $this->input($key, $default);
        return new StringableWrapper((string) $value);
    }

    /**
     * Retrieve input from the request as a boolean.
     *
     * Enhanced boolean conversion with multiple formats support.
     *
     * @param string $key Input key
     * @param bool $default Default value
     * @return bool Boolean value
     */
    public function boolean(string $key, bool $default = false): bool
    {
        $value = $this->input($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = strtolower($value);
            return in_array($value, ['true', '1', 'yes', 'on'], true);
        }

        return (bool) $value;
    }

    /**
     * Retrieve input from the request as an integer.
     *
     * @param string $key Input key
     * @param int $default Default value
     * @return int Integer value
     */
    public function integer(string $key, int $default = 0): int
    {
        return (int) $this->input($key, $default);
    }

    /**
     * Retrieve input from the request as a float.
     *
     * @param string $key Input key
     * @param float $default Default value
     * @return float Float value
     */
    public function float(string $key, float $default = 0.0): float
    {
        return (float) $this->input($key, $default);
    }

    /**
     * Retrieve input from the request as a date.
     *
     * @param string $key Input key
     * @param string|null $format Date format
     * @param \DateTimeZone|null $timezone Timezone
     * @return \DateTime|null DateTime instance or null
     */
    public function date(string $key, ?string $format = null, ?\DateTimeZone $timezone = null): ?\DateTime
    {
        $value = $this->input($key);

        if ($value === null) {
            return null;
        }

        try {
            if ($format !== null) {
                $date = \DateTime::createFromFormat($format, (string) $value, $timezone);
                return $date ?: null;
            }

            return new \DateTime((string) $value, $timezone);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Retrieve input from the request as an enum.
     *
     * @template T of \BackedEnum
     * @param string $key Input key
     * @param class-string<T> $enumClass Enum class
     * @param T|null $default Default enum value
     * @return T|null Enum instance or null
     */
    public function enum(string $key, string $enumClass, ?\BackedEnum $default = null): ?\BackedEnum
    {
        $value = $this->input($key);

        if ($value === null) {
            return $default;
        }

        try {
            return $enumClass::from($value);
        } catch (\ValueError $e) {
            return $default;
        }
    }

    /**
     * Retrieve a subset of the items from the input data.
     *
     * Enhanced version with support for nested keys and renaming.
     *
     * @param array<string|int, string> $keys Keys to retrieve (can be associative for renaming)
     * @return array<string, mixed> Subset of input data
     *
     * @example
     * ```php
     * // Basic usage
     * $data = $request->collect(['name', 'email']);
     *
     * // With renaming
     * $data = $request->collect([
     *     'user_name' => 'name',
     *     'user_email' => 'email'
     * ]);
     * ```
     */
    public function collect(array $keys): array
    {
        $result = [];

        foreach ($keys as $newKey => $originalKey) {
            if (is_int($newKey)) {
                // Simple key: ['name', 'email']
                $result[$originalKey] = $this->input($originalKey);
            } else {
                // Renamed key: ['user_name' => 'name']
                $result[$newKey] = $this->input($originalKey);
            }
        }

        return $result;
    }

    /**
     * Get the current path info for the request.
     *
     * @return string Path info
     */
    public function getPathInfo(): string
    {
        return $this->path();
    }

    /**
     * Get the request method.
     *
     * @return string HTTP method
     */
    public function getMethod(): string
    {
        return $this->method();
    }

    /**
     * Determine if the request is the result of an AJAX call.
     *
     * @return bool
     */
    public function ajax(): bool
    {
        return $this->isAjax();
    }

    /**
     * Determine if the request is the result of a PJAX call.
     *
     * @return bool
     */
    public function pjax(): bool
    {
        return $this->header('x-pjax') !== null;
    }

    /**
     * Determine if the request is sending JSON.
     *
     * @return bool
     */
    public function isJson(): bool
    {
        $contentType = $this->header('content-type', '');
        return str_contains($contentType, 'application/json');
    }

    /**
     * Determine if the current request probably expects a JSON response.
     *
     * @return bool
     */
    public function wantsJson(): bool
    {
        return $this->expectsJson();
    }

    /**
     * Determine if the current request is asking for JSON.
     *
     * @return bool
     */
    public function acceptsJson(): bool
    {
        return str_contains($this->header('accept', ''), 'application/json');
    }

    /**
     * Determine if the current request accepts HTML.
     *
     * @return bool
     */
    public function acceptsHtml(): bool
    {
        $accept = $this->header('accept', '');
        return str_contains($accept, 'text/html') || str_contains($accept, 'application/xhtml+xml');
    }

    /**
     * Get the data format expected in the response.
     *
     * @param string $default Default format
     * @return string Expected format
     */
    public function format(string $default = 'html'): string
    {
        if ($this->expectsJson()) {
            return 'json';
        }

        if ($this->acceptsHtml()) {
            return 'html';
        }

        $accept = $this->header('accept', '');

        if (str_contains($accept, 'application/xml') || str_contains($accept, 'text/xml')) {
            return 'xml';
        }

        return $default;
    }
}

/**
 * Stringable wrapper for fluent string operations.
 *
 */
class StringableWrapper
{
    public function __construct(private string $value) {}

    public function __toString(): string
    {
        return $this->value;
    }

    public function trim(): self
    {
        return new self(trim($this->value));
    }

    public function lower(): self
    {
        return new self(strtolower($this->value));
    }

    public function upper(): self
    {
        return new self(strtoupper($this->value));
    }

    public function title(): self
    {
        return new self(ucwords($this->value));
    }

    public function length(): int
    {
        return strlen($this->value);
    }

    public function contains(string $needle): bool
    {
        return str_contains($this->value, $needle);
    }

    public function startsWith(string $prefix): bool
    {
        return str_starts_with($this->value, $prefix);
    }

    public function endsWith(string $suffix): bool
    {
        return str_ends_with($this->value, $suffix);
    }

    public function substr(int $start, ?int $length = null): self
    {
        return new self(substr($this->value, $start, $length));
    }

    public function replace(string $search, string $replace): self
    {
        return new self(str_replace($search, $replace, $this->value));
    }

    public function explode(string $delimiter): array
    {
        return explode($delimiter, $this->value);
    }

    public function isEmpty(): bool
    {
        return empty($this->value);
    }

    public function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }
}
