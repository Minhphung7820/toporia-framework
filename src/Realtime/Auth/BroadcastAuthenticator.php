<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Auth;

use Toporia\Framework\Auth\Authenticatable;
use Toporia\Framework\Realtime\ChannelRoute;

/**
 * Broadcast Authenticator
 *
 * Handles channel authorization similar to other frameworks Broadcasting.
 * Generates HMAC signatures for Pusher-compatible authentication.
 *
 * Authentication Flow (Toporia-style):
 * 1. Client connects to WebSocket server (gets socket_id)
 * 2. Client wants to join private/presence channel
 * 3. Client sends POST /broadcasting/auth with socket_id + channel_name
 * 4. Server validates user's JWT/session
 * 5. Server checks channel authorization via routes/channels.php
 * 6. Server generates HMAC signature
 * 7. Client uses signature to authenticate with WebSocket server
 *
 * Channel Types:
 * - Public: No authentication required (no prefix)
 * - Private: Requires auth, prefix "private-"
 * - Presence: Requires auth + user info, prefix "presence-"
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Realtime\Auth
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class BroadcastAuthenticator
{
    /**
     * Application key for auth signature.
     */
    private string $appKey;

    /**
     * Secret key for HMAC signature.
     */
    private string $appSecret;

    /**
     * @param string|null $appKey Application key (default: from env)
     * @param string|null $appSecret Application secret (default: from env)
     */
    public function __construct(
        ?string $appKey = null,
        ?string $appSecret = null
    ) {
        $this->appKey = $appKey ?? $_ENV['BROADCAST_APP_KEY'] ?? $_ENV['APP_KEY'] ?? 'toporia';
        $this->appSecret = $appSecret ?? $_ENV['BROADCAST_APP_SECRET'] ?? $_ENV['JWT_SECRET'] ?? '';

        if (empty($this->appSecret)) {
            throw new \RuntimeException(
                'BROADCAST_APP_SECRET or JWT_SECRET must be configured for broadcast authentication.'
            );
        }
    }

    /**
     * Authenticate a channel subscription request.
     *
     * This is the main entry point, called from /broadcasting/auth endpoint.
     *
     * @param Authenticatable|array|null $user Authenticated user
     * @param string $socketId Socket ID from client
     * @param string $channelName Full channel name (e.g., "private-orders.123")
     * @param string|null $guardName Guard name for context (passed to channel callbacks)
     * @return array{auth: string, channel_data?: string}|null Auth response or null if denied
     */
    public function authenticate(
        Authenticatable|array|null $user,
        string $socketId,
        string $channelName,
        ?string $guardName = null
    ): ?array {
        // Validate inputs
        if (empty($socketId) || empty($channelName)) {
            return null;
        }

        // Determine channel type
        $channelType = $this->getChannelType($channelName);

        // Public channels don't need authentication
        if ($channelType === 'public') {
            return $this->generateAuthResponse($socketId, $channelName);
        }

        // Private/Presence channels require authenticated user
        if ($user === null) {
            return null;
        }

        // Normalize channel name (remove prefix for route matching)
        $normalizedChannel = $this->normalizeChannelName($channelName);

        // Check authorization via routes/channels.php (pass guard for context)
        $authResult = $this->authorizeChannel($user, $normalizedChannel, $guardName);

        if ($authResult === false || $authResult === null) {
            return null;
        }

        // Generate auth response
        if ($channelType === 'presence') {
            return $this->generatePresenceAuthResponse(
                $socketId,
                $channelName,
                $user,
                is_array($authResult) ? $authResult : []
            );
        }

        return $this->generateAuthResponse($socketId, $channelName);
    }

    /**
     * Get channel type from channel name.
     *
     * @param string $channelName Full channel name
     * @return string 'public', 'private', or 'presence'
     */
    public function getChannelType(string $channelName): string
    {
        if (str_starts_with($channelName, 'private-')) {
            return 'private';
        }

        if (str_starts_with($channelName, 'presence-')) {
            return 'presence';
        }

        return 'public';
    }

    /**
     * Normalize channel name by removing prefix.
     *
     * @param string $channelName Full channel name (e.g., "private-orders.123")
     * @return string Normalized name (e.g., "orders.123")
     */
    public function normalizeChannelName(string $channelName): string
    {
        // Remove private- or presence- prefix
        if (str_starts_with($channelName, 'private-')) {
            return substr($channelName, 8);
        }

        if (str_starts_with($channelName, 'presence-')) {
            return substr($channelName, 9);
        }

        return $channelName;
    }

    /**
     * Authorize user for a channel.
     *
     * Checks routes/channels.php definitions.
     * The callback receives: ($user, ...channelParams) or ($user, ...channelParams, $guard)
     *
     * Example in routes/channels.php:
     *   // Allow specific guards
     *   ChannelRoute::channel('orders.{orderId}', function ($user, $orderId) {
     *       return $user['id'] === Order::find($orderId)?->user_id;
     *   }, ['guards' => ['web', 'admin']]);
     *
     *   // Or with guard in callback
     *   ChannelRoute::channel('admin.dashboard', function ($user, $guard = null) {
     *       return $guard === 'admin';
     *   }, ['guards' => ['admin']]);
     *
     * @param Authenticatable|array $user Authenticated user
     * @param string $channelName Normalized channel name
     * @param string|null $guardName Guard name for context
     * @return bool|array True/false for private, array for presence, null if no match
     */
    public function authorizeChannel(
        Authenticatable|array $user,
        string $channelName,
        ?string $guardName = null
    ): bool|array|null {
        // Find matching channel definition
        $channelDef = ChannelRoute::match($channelName);

        if ($channelDef === null) {
            // No channel definition found - deny by default for security
            return null;
        }

        // Check if guard is allowed for this channel
        if (!ChannelRoute::isGuardAllowed($channelDef, $guardName)) {
            error_log("[BroadcastAuth] Guard '{$guardName}' not allowed for channel '{$channelName}'");
            return false;
        }

        // Build callback parameters
        $params = array_values($channelDef['params'] ?? []);

        // Execute authorization callback
        try {
            $callback = $channelDef['callback'];

            // Check if callback accepts guard parameter (reflection)
            $reflection = new \ReflectionFunction($callback);
            $paramCount = $reflection->getNumberOfParameters();

            // If callback has more parameters than user + channel params, pass guard
            // Callback signature: function($user, ...$channelParams, $guard = null)
            if ($paramCount > count($params) + 1 && $guardName !== null) {
                // Append guard to params array before unpacking
                $paramsWithGuard = array_merge($params, [$guardName]);
                $result = $callback($user, ...$paramsWithGuard);
            } else {
                $result = $callback($user, ...$params);
            }

            return $result;
        } catch (\Throwable $e) {
            error_log("[BroadcastAuth] Authorization callback error: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Generate auth response for private channel.
     *
     * @param string $socketId Socket ID
     * @param string $channelName Full channel name (with prefix)
     * @return array{auth: string}
     */
    public function generateAuthResponse(string $socketId, string $channelName): array
    {
        $signature = $this->generateSignature($socketId, $channelName);

        return [
            'auth' => "{$this->appKey}:{$signature}",
        ];
    }

    /**
     * Generate auth response for presence channel.
     *
     * @param string $socketId Socket ID
     * @param string $channelName Full channel name (with prefix)
     * @param Authenticatable|array $user User object
     * @param array $userData Additional user data from callback
     * @return array{auth: string, channel_data: string}
     */
    public function generatePresenceAuthResponse(
        string $socketId,
        string $channelName,
        Authenticatable|array $user,
        array $userData = []
    ): array {
        // Build channel_data
        $channelData = $this->buildChannelData($user, $userData);
        $channelDataJson = json_encode($channelData);

        // Generate signature including channel_data
        $signature = $this->generateSignature($socketId, $channelName, $channelDataJson);

        return [
            'auth' => "{$this->appKey}:{$signature}",
            'channel_data' => $channelDataJson,
        ];
    }

    /**
     * Build channel_data for presence channels.
     *
     * @param Authenticatable|array $user User object
     * @param array $userData Additional user data
     * @return array{user_id: mixed, user_info: array}
     */
    private function buildChannelData(Authenticatable|array $user, array $userData): array
    {
        // Extract user ID
        if ($user instanceof Authenticatable) {
            $userId = $user->getAuthIdentifier();
        } elseif (is_array($user)) {
            $userId = $user['id'] ?? $user['user_id'] ?? null;
        } else {
            $userId = null;
        }

        // Build user_info from callback result or user object
        $userInfo = $userData;

        if (empty($userInfo)) {
            // Default user info
            if ($user instanceof Authenticatable) {
                $userInfo = [
                    'name' => method_exists($user, 'getName') ? $user->getName() : null,
                    'email' => method_exists($user, 'getEmail') ? $user->getEmail() : null,
                ];
            } elseif (is_array($user)) {
                $userInfo = [
                    'name' => $user['name'] ?? $user['username'] ?? null,
                    'email' => $user['email'] ?? null,
                ];
            }
        }

        // Remove null values
        $userInfo = array_filter($userInfo, fn($v) => $v !== null);

        return [
            'user_id' => $userId,
            'user_info' => $userInfo,
        ];
    }

    /**
     * Generate HMAC signature.
     *
     * Pusher-compatible signature format:
     * - Private: HMAC_SHA256(secret, "socket_id:channel_name")
     * - Presence: HMAC_SHA256(secret, "socket_id:channel_name:channel_data")
     *
     * @param string $socketId Socket ID
     * @param string $channelName Full channel name
     * @param string|null $channelData JSON channel data (for presence)
     * @return string Hex-encoded HMAC signature
     */
    public function generateSignature(
        string $socketId,
        string $channelName,
        ?string $channelData = null
    ): string {
        // Build string to sign
        $stringToSign = "{$socketId}:{$channelName}";

        if ($channelData !== null) {
            $stringToSign .= ":{$channelData}";
        }

        // Generate HMAC SHA256 signature
        return hash_hmac('sha256', $stringToSign, $this->appSecret);
    }

    /**
     * Verify auth signature.
     *
     * Used by WebSocket server to validate client's auth token.
     *
     * @param string $socketId Socket ID
     * @param string $channelName Full channel name
     * @param string $authToken Auth token from client (format: "app_key:signature")
     * @param string|null $channelData Channel data (for presence)
     * @return bool True if valid
     */
    public function verifySignature(
        string $socketId,
        string $channelName,
        string $authToken,
        ?string $channelData = null
    ): bool {
        // Parse auth token
        $parts = explode(':', $authToken, 2);

        if (count($parts) !== 2) {
            return false;
        }

        [$appKey, $signature] = $parts;

        // Verify app key
        if ($appKey !== $this->appKey) {
            return false;
        }

        // Generate expected signature
        $expectedSignature = $this->generateSignature($socketId, $channelName, $channelData);

        // Constant-time comparison to prevent timing attacks
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Get app key.
     *
     * @return string
     */
    public function getAppKey(): string
    {
        return $this->appKey;
    }
}
