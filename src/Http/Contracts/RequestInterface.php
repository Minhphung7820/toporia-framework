<?php

declare(strict_types=1);

namespace Toporia\Framework\Http\Contracts;

use Toporia\Framework\Session\Store;
use Toporia\Framework\Storage\UploadedFile;


/**
 * Interface RequestInterface
 *
 * Contract defining the interface for RequestInterface implementations in
 * the HTTP request and response handling layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Http\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @internal    This class is a core component and should not be extended
 *              directly unless you know what you're doing.
 */
interface RequestInterface
{
    /**
     * Get the HTTP method.
     *
     * @return string (GET, POST, PUT, PATCH, DELETE, etc.)
     */
    public function method(): string;

    /**
     * Get the request URI path.
     *
     * @return string
     */
    public function path(): string;

    /**
     * Get query parameter(s).
     *
     * @param string|null $key Specific key or null for all.
     * @param mixed $default Default value if key not found.
     * @return mixed
     */
    public function query(?string $key = null, mixed $default = null): mixed;

    /**
     * Get input data (body/POST).
     *
     * @param string|null $key Specific key or null for all.
     * @param mixed $default Default value if key not found.
     * @return mixed
     */
    public function input(?string $key = null, mixed $default = null): mixed;

    /**
     * Get a header value.
     *
     * @param string $name Header name.
     * @param string|null $default Default value.
     * @return string|null
     */
    public function header(string $name, ?string $default = null): ?string;

    /**
     * Check if request is AJAX.
     *
     * @return bool
     */
    public function isAjax(): bool;

    /**
     * Check if request expects JSON response.
     *
     * @return bool
     */
    public function expectsJson(): bool;

    /**
     * Check if the request is over HTTPS.
     *
     * @return bool
     */
    public function isSecure(): bool;

    /**
     * Get the host from the request.
     *
     * @return string
     */
    public function host(): string;

    /**
     * Get the raw request body.
     *
     * @return string
     */
    public function raw(): string;

    /**
     * Get the client IP address.
     *
     * @return string
     */
    public function ip(): string;

    // ============================================================================
    // Advanced Request Methods (Enhanced Interface)
    // ============================================================================

    /**
     * Merge new input into the current request's input array.
     *
     * @param array<string, mixed> $input New input data to merge
     * @return self Fluent interface
     */
    public function merge(array $input): self;

    /**
     * Get a subset of the input data containing only the specified keys.
     *
     * @param array<string> $keys Keys to retrieve
     * @return array<string, mixed> Filtered input data
     */
    public function only(array $keys): array;

    /**
     * Get all input except specified keys.
     *
     * @param array<string> $keys Keys to exclude
     * @return array<string, mixed> Filtered input data
     */
    public function except(array $keys): array;

    /**
     * Check if the request has specific input key.
     *
     * @param string $key Input key
     * @return bool
     */
    public function has(string $key): bool;

    /**
     * Get input data with type casting and validation.
     *
     * @param string $key Input key
     * @param mixed $default Default value
     * @param string|null $type Expected type
     * @param callable|null $validator Optional validation callback
     * @return mixed Typed and validated value
     */
    public function typed(string $key, mixed $default = null, ?string $type = null, ?callable $validator = null): mixed;

    /**
     * Get request data with automatic sanitization.
     *
     * @param string $key Input key
     * @param mixed $default Default value
     * @param string $sanitizer Sanitization method
     * @return mixed Sanitized value
     */
    public function safe(string $key, mixed $default = null, string $sanitizer = 'html'): mixed;

    /**
     * Check if the request is from a mobile device.
     *
     * @return bool
     */
    public function isMobile(): bool;

    /**
     * Check if the request is from a bot/crawler.
     *
     * @return bool
     */
    public function isBot(): bool;

    /**
     * Get the request fingerprint for caching/security purposes.
     *
     * @param array<string> $includeHeaders Additional headers to include
     * @return string Unique request fingerprint
     */
    public function fingerprint(array $includeHeaders = []): string;

    /**
     * Get request signature for API authentication.
     *
     * @param string $secret Secret key for signing
     * @param string $algorithm Hash algorithm
     * @param array<string> $includeHeaders Headers to include in signature
     * @return string Request signature
     */
    public function signature(string $secret, string $algorithm = 'sha256', array $includeHeaders = []): string;

    /**
     * Verify request signature for API authentication.
     *
     * @param string $expectedSignature Expected signature
     * @param string $secret Secret key
     * @param string $algorithm Hash algorithm
     * @param array<string> $includeHeaders Headers to include
     * @return bool True if signature is valid
     */
    public function verifySignature(string $expectedSignature, string $secret, string $algorithm = 'sha256', array $includeHeaders = []): bool;

    /**
     * Convert request to array for logging/debugging.
     *
     * @param bool $includeSensitive Whether to include sensitive data
     * @return array<string, mixed> Request data array
     */
    public function toArray(bool $includeSensitive = false): array;

    // ============================================================================
    // Missing Core Methods (Headers, Cookies, Files, Server)
    // ============================================================================

    /**
     * Get all headers as an array.
     *
     * @return array<string, string> All request headers
     */
    public function headers(): array;

    /**
     * Get all cookies as an array.
     *
     * @param bool $decrypt Whether to decrypt encrypted cookies
     * @return array<string, string> All request cookies
     */
    public function cookies(bool $decrypt = false): array;

    /**
     * Get a specific cookie value.
     *
     * @param string $name Cookie name
     * @param string|null $default Default value if cookie not found
     * @param bool $decrypt Whether to decrypt the cookie value
     * @return string|null Cookie value or default
     */
    public function cookie(string $name, ?string $default = null, bool $decrypt = false): ?string;

    /**
     * Get uploaded files as UploadedFile instances.
     *
     * @return array<string, UploadedFile|array<UploadedFile>> Uploaded files
     */
    public function files(): array;

    /**
     * Get a specific uploaded file as UploadedFile instance.
     *
     * @param string $name File input name
     * @return UploadedFile|array<UploadedFile>|null UploadedFile instance or null
     */
    public function file(string $name): UploadedFile|array|null;

    /**
     * Check if a file was uploaded.
     *
     * @param string $name File input name
     * @return bool True if file was uploaded successfully
     */
    public function hasFile(string $name): bool;

    /**
     * Get server and environment information.
     *
     * @param string|null $key Specific server variable or null for all
     * @param mixed $default Default value if key not found
     * @return mixed Server information
     */
    public function server(?string $key = null, mixed $default = null): mixed;

    /**
     * Get environment variable.
     *
     * @param string $key Environment variable name
     * @param mixed $default Default value if not found
     * @param string|null $type Type to cast to
     * @return mixed Environment variable value
     */
    public function env(string $key, mixed $default = null, ?string $type = null): mixed;

    /**
     * Get all input data.
     *
     * @return array<string, mixed> All input data
     */
    public function all(): array;

    // ============================================================================
    // Additional Framework-Compatible Methods
    // ============================================================================

    /**
     * Get the full URL for the request.
     *
     * @return string Full URL
     */
    public function fullUrl(): string;

    /**
     * Get the URL without query parameters.
     *
     * @return string Base URL without query string
     */
    public function url(): string;

    /**
     * Get the root URL for the application.
     *
     * @return string Root URL
     */
    public function root(): string;

    /**
     * Determine if the request contains a given input item key.
     *
     * @param array<string>|string $keys Key(s) to check
     * @return bool True if all keys exist and are filled
     */
    public function filled(array|string $keys): bool;

    /**
     * Determine if the request is missing a given input item key.
     *
     * @param array<string>|string $keys Key(s) to check
     * @return bool True if any key is missing
     */
    public function missing(array|string $keys): bool;

    /**
     * Get the bearer token from the request headers.
     *
     * @return string|null Bearer token or null if not found
     */
    public function bearerToken(): ?string;

    /**
     * Retrieve input from the request as a boolean.
     *
     * @param string $key Input key
     * @param bool $default Default value
     * @return bool Boolean value
     */
    public function boolean(string $key, bool $default = false): bool;

    /**
     * Retrieve input from the request as an integer.
     *
     * @param string $key Input key
     * @param int $default Default value
     * @return int Integer value
     */
    public function integer(string $key, int $default = 0): int;

    /**
     * Retrieve input from the request as a float.
     *
     * @param string $key Input key
     * @param float $default Default value
     * @return float Float value
     */
    public function float(string $key, float $default = 0.0): float;

    /**
     * Retrieve input from the request as a date.
     *
     * @param string $key Input key
     * @param string|null $format Date format
     * @param \DateTimeZone|null $timezone Timezone
     * @return \DateTime|null DateTime instance or null
     */
    public function date(string $key, ?string $format = null, ?\DateTimeZone $timezone = null): ?\DateTime;

    /**
     * Determine if the request is sending JSON.
     *
     * @return bool
     */
    public function isJson(): bool;

    /**
     * Determine if the current request probably expects a JSON response.
     *
     * @return bool
     */
    public function wantsJson(): bool;

    /**
     * Determine if the current request accepts HTML.
     *
     * @return bool
     */
    public function acceptsHtml(): bool;

    /**
     * Get the data format expected in the response.
     *
     * @param string $default Default format
     * @return string Expected format
     */
    public function format(string $default = 'html'): string;

    // ============================================================================
    // Session Management Methods
    // ============================================================================

    /**
     * Get the session associated with the request.
     *
     * @param string|array|null $key Session key(s) or null for all data
     * @param mixed $default Default value if key not found
     * @return mixed Session data
     */
    public function session(string|array|null $key = null, mixed $default = null): mixed;

    /**
     * Get flash data from the session.
     *
     * @param string|null $key Flash key or null for all flash data
     * @param mixed $default Default value if key not found
     * @return mixed Flash data
     */
    public function flash(string|null $key = null, mixed $default = null): mixed;

    /**
     * Get old input data from the session.
     *
     * @param string|null $key Input key or null for all old input
     * @param mixed $default Default value if key not found
     * @return mixed Old input data
     */
    public function old(string|null $key = null, mixed $default = null): mixed;

    /**
     * Flash the current input to the session.
     *
     * @param array<string>|null $keys Specific keys to flash (null = all input)
     * @return self Fluent interface
     */
    public function flashInput(?array $keys = null): self;

    /**
     * Flash data to the session.
     *
     * @param string|array $key Flash key or array of key-value pairs
     * @param mixed $value Flash value (ignored if key is array)
     * @return self Fluent interface
     */
    public function flashData(string|array $key, mixed $value = null): self;

    /**
     * Get session ID.
     *
     * @return string|null Session ID or null if no session
     */
    public function sessionId(): ?string;

    /**
     * Check if session has a specific key.
     *
     * @param string $key Session key to check
     * @return bool True if session has the key
     */
    public function hasSession(string $key): bool;

    /**
     * Check if there is flash data.
     *
     * @param string|null $key Specific flash key to check (null = any flash data)
     * @return bool True if flash data exists
     */
    public function hasFlash(?string $key = null): bool;

    /**
     * Check if there is old input data.
     *
     * @param string|null $key Specific input key to check (null = any old input)
     * @return bool True if old input exists
     */
    public function hasOldInput(?string $key = null): bool;

    /**
     * Set the session instance for this request.
     *
     * @param \Toporia\Framework\Session\Store $session Session store instance
     * @return self Fluent interface
     */
    public function setSession(Store $session): self;

    /**
     * Get the session instance for this request.
     *
     * @return \Toporia\Framework\Session\Store|null Session store instance or null
     */
    public function getSession(): ?Store;
}
