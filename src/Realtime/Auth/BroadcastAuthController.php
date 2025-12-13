<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Auth;

use Toporia\Framework\Auth\Authenticatable;
use Toporia\Framework\Auth\Contracts\AuthManagerInterface;
use Toporia\Framework\Http\Request;
use Toporia\Framework\Http\Response;

/**
 * Broadcast Authentication Controller
 *
 * Handles HTTP POST /broadcasting/auth requests for channel authorization.
 * Similar to other frameworks's BroadcastController.
 *
 * Supports flexible guard selection:
 *   - Via request parameter: { "guard": "admin" }
 *   - Via query string: /broadcasting/auth?guard=api
 *   - Via config: config('broadcasting.auth_guard')
 *   - Default: 'api'
 *
 * Usage:
 *   // In routes/api.php
 *   $router->post('/broadcasting/auth', [BroadcastAuthController::class, 'authenticate']);
 *
 * Client Request:
 *   POST /broadcasting/auth
 *   Headers: { Authorization: "Bearer jwt_token" }
 *   Body: { "socket_id": "abc123.456", "channel_name": "private-orders.123", "guard": "api" }
 *
 * Response (success):
 *   { "auth": "app_key:hmac_signature" }
 *   { "auth": "...", "channel_data": "{...}" } // for presence
 *
 * Response (error):
 *   403 Forbidden
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
final class BroadcastAuthController
{
    private BroadcastAuthenticator $authenticator;
    private ?AuthManagerInterface $authManager;

    public function __construct(
        ?BroadcastAuthenticator $authenticator = null,
        ?AuthManagerInterface $authManager = null
    ) {
        $this->authenticator = $authenticator ?? new BroadcastAuthenticator();
        $this->authManager = $authManager;

        // Try to resolve AuthManager from container if not provided
        if ($this->authManager === null && function_exists('app')) {
            try {
                $this->authManager = app(AuthManagerInterface::class);
            } catch (\Throwable $e) {
                // AuthManager not available
            }
        }
    }

    /**
     * Handle broadcast authentication request.
     *
     * @param Request $request HTTP request
     * @return Response JSON response
     */
    public function authenticate(Request $request): Response
    {
        // Determine which guard to use
        $guardName = $this->resolveGuard($request);

        // Get authenticated user from request using the resolved guard
        $user = $this->getUser($request, $guardName);

        // Get request parameters
        $socketId = $request->input('socket_id', '');
        $channelName = $request->input('channel_name', '');

        // Validate required fields
        if (empty($socketId) || empty($channelName)) {
            return $this->errorResponse('Missing socket_id or channel_name', 422);
        }

        // Check channel type
        $channelType = $this->authenticator->getChannelType($channelName);

        // Public channels don't need auth - just return success
        if ($channelType === 'public') {
            $authResponse = $this->authenticator->generateAuthResponse($socketId, $channelName);
            return $this->jsonResponse($authResponse);
        }

        // Private/Presence channels require authenticated user
        if ($user === null) {
            return $this->errorResponse('Unauthorized', 401);
        }

        // Authenticate and authorize (pass guard name for context)
        $authResponse = $this->authenticator->authenticate($user, $socketId, $channelName, $guardName);

        if ($authResponse === null) {
            return $this->errorResponse('Forbidden', 403);
        }

        // Include guard in response for client reference
        $authResponse['guard'] = $guardName;

        return $this->jsonResponse($authResponse);
    }

    /**
     * Resolve which authentication guard to use.
     *
     * Priority:
     * 1. Request body parameter 'guard'
     * 2. Query string parameter 'guard'
     * 3. Config 'broadcasting.auth_guard'
     * 4. Config 'realtime.auth_guard'
     * 5. Default 'api'
     *
     * @param Request $request HTTP request
     * @return string Guard name
     */
    private function resolveGuard(Request $request): string
    {
        // 1. From request body
        $guard = $request->input('guard');
        if (!empty($guard) && is_string($guard)) {
            return $this->validateGuard($guard);
        }

        // 2. From query string
        $guard = $request->query('guard');
        if (!empty($guard) && is_string($guard)) {
            return $this->validateGuard($guard);
        }

        // 3. From broadcasting config
        if (function_exists('config')) {
            $guard = config('broadcasting.auth_guard');
            if (!empty($guard) && is_string($guard)) {
                return $this->validateGuard($guard);
            }

            // 4. From realtime config
            $guard = config('realtime.auth_guard');
            if (!empty($guard) && is_string($guard)) {
                return $this->validateGuard($guard);
            }
        }

        // 5. Default to 'api'
        return 'api';
    }

    /**
     * Validate that a guard exists.
     *
     * @param string $guardName Guard name to validate
     * @return string Valid guard name or default
     */
    private function validateGuard(string $guardName): string
    {
        if ($this->authManager !== null && $this->authManager->hasGuard($guardName)) {
            return $guardName;
        }

        // Check if guard exists in config
        if (function_exists('config')) {
            $guards = config('auth.guards', []);
            if (isset($guards[$guardName])) {
                return $guardName;
            }
        }

        // Log warning and return as-is (might still work)
        error_log("[BroadcastAuth] Guard '{$guardName}' not found in config, using anyway");
        return $guardName;
    }

    /**
     * Get authenticated user from request using specified guard.
     *
     * Supports multiple auth methods:
     * - auth($guard)->user() - Framework's auth guard
     * - $request->user($guard) - Request user attribute
     * - Manual JWT verification (fallback)
     *
     * @param Request $request HTTP request
     * @param string $guardName Guard name to use
     * @return Authenticatable|array|null
     */
    private function getUser(Request $request, string $guardName): Authenticatable|array|null
    {
        // Method 1: Use framework's auth manager with specific guard
        if ($this->authManager !== null) {
            try {
                $user = $this->authManager->guard($guardName)->user();
                if ($user !== null) {
                    return $user;
                }
            } catch (\Throwable $e) {
                // Guard not available or error, try other methods
                error_log("[BroadcastAuth] Guard '{$guardName}' error: {$e->getMessage()}");
            }
        }

        // Method 2: Use auth() helper with specific guard
        if (function_exists('auth')) {
            try {
                $user = auth($guardName)->user();
                if ($user !== null) {
                    return $user;
                }
            } catch (\Throwable $e) {
                // Auth not available
            }
        }

        // Method 3: Check request user attribute (set by auth middleware)
        if (method_exists($request, 'user')) {
            $user = $request->user($guardName);
            if ($user !== null) {
                return $user;
            }
        }

        // Method 4: Manual JWT verification from Authorization header (fallback)
        $token = $this->extractBearerToken($request);
        if ($token !== null) {
            return $this->verifyJwtToken($token, $guardName);
        }

        return null;
    }

    /**
     * Extract Bearer token from Authorization header.
     *
     * @param Request $request HTTP request
     * @return string|null Token or null
     */
    private function extractBearerToken(Request $request): ?string
    {
        $header = $request->header('authorization') ?? $request->header('Authorization');

        if ($header === null) {
            return null;
        }

        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Verify JWT token and return user data.
     *
     * Supports guard-specific JWT secrets if configured:
     *   JWT_SECRET_API=xxx
     *   JWT_SECRET_ADMIN=xxx
     *
     * @param string $token JWT token
     * @param string $guardName Guard name for secret lookup
     * @return array|null User data or null if invalid
     */
    private function verifyJwtToken(string $token, string $guardName = 'api'): ?array
    {
        try {
            $parts = explode('.', $token);

            if (count($parts) !== 3) {
                return null;
            }

            [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;

            // Get secret - try guard-specific first, then fallback to default
            $secret = $this->getJwtSecret($guardName);
            if ($secret === null || strlen($secret) < 32) {
                return null;
            }

            // Verify signature
            $expectedSignature = hash_hmac(
                'sha256',
                "$headerEncoded.$payloadEncoded",
                $secret,
                true
            );
            $expectedSignatureEncoded = rtrim(strtr(base64_encode($expectedSignature), '+/', '-_'), '=');

            if (!hash_equals($expectedSignatureEncoded, $signatureEncoded)) {
                return null;
            }

            // Decode payload
            $payload = json_decode(
                base64_decode(strtr($payloadEncoded, '-_', '+/')),
                true
            );

            if (!is_array($payload)) {
                return null;
            }

            // Check expiration
            if (isset($payload['exp']) && $payload['exp'] < time()) {
                return null;
            }

            // Validate guard matches if specified in token
            $tokenGuard = $payload['guard'] ?? null;
            if ($tokenGuard !== null && $tokenGuard !== $guardName) {
                error_log("[BroadcastAuth] Token guard '{$tokenGuard}' does not match requested guard '{$guardName}'");
                return null;
            }

            // Return user data as array (compatible with authorization callbacks)
            return [
                'id' => $payload['sub'] ?? null,
                'user_id' => $payload['sub'] ?? null,
                'name' => $payload['name'] ?? null,
                'username' => $payload['username'] ?? null,
                'email' => $payload['email'] ?? null,
                'roles' => $payload['roles'] ?? [],
                'guard' => $guardName,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Get JWT secret for a specific guard.
     *
     * Lookup priority:
     * 1. JWT_SECRET_{GUARD} env variable (e.g., JWT_SECRET_ADMIN)
     * 2. config('auth.guards.{guard}.jwt_secret')
     * 3. JWT_SECRET env variable (default)
     *
     * @param string $guardName Guard name
     * @return string|null JWT secret or null
     */
    private function getJwtSecret(string $guardName): ?string
    {
        $guardUpper = strtoupper($guardName);

        // 1. Guard-specific env variable
        $secret = $_ENV["JWT_SECRET_{$guardUpper}"] ?? getenv("JWT_SECRET_{$guardUpper}");
        if (!empty($secret) && is_string($secret)) {
            return $secret;
        }

        // 2. Config-based secret
        if (function_exists('config')) {
            $secret = config("auth.guards.{$guardName}.jwt_secret");
            if (!empty($secret) && is_string($secret)) {
                return $secret;
            }
        }

        // 3. Default JWT secret
        return $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?: null;
    }

    /**
     * Create JSON response.
     *
     * @param array $data Response data
     * @param int $status HTTP status code
     * @return Response
     */
    private function jsonResponse(array $data, int $status = 200): Response
    {
        return response()
            ->json($data, $status)
            ->header('Content-Type', 'application/json');
    }

    /**
     * Create error response.
     *
     * @param string $message Error message
     * @param int $status HTTP status code
     * @return Response
     */
    private function errorResponse(string $message, int $status): Response
    {
        return response()
            ->json(['error' => $message], $status)
            ->header('Content-Type', 'application/json');
    }

    /**
     * Verify session and return authenticated user data.
     *
     * This endpoint allows the realtime server to verify session authentication
     * by making an HTTP request instead of reading session files directly.
     *
     * Request:
     *   POST /broadcasting/verify-session
     *   Body: { "session_id": "abc123", "guard": "web" }
     *   Headers: { "X-Realtime-Secret": "your-realtime-secret" }
     *
     * Response (success):
     *   { "authenticated": true, "user": { "id": 1, "name": "...", ... }, "guard": "web" }
     *
     * Response (error):
     *   { "authenticated": false, "error": "..." }
     *
     * @param Request $request HTTP request
     * @return Response JSON response
     */
    public function verifySession(Request $request): Response
    {
        // Verify internal request (only realtime server should call this)
        $realtimeSecret = config('realtime.internal_secret') ?? env('REALTIME_INTERNAL_SECRET');
        $requestSecret = $request->header('X-Realtime-Secret');

        if (!empty($realtimeSecret) && $requestSecret !== $realtimeSecret) {
            return $this->jsonResponse(['authenticated' => false, 'error' => 'Invalid secret'], 403);
        }

        $sessionId = $request->input('session_id', '');
        $guardName = $request->input('guard', 'web');

        if (empty($sessionId)) {
            return $this->jsonResponse(['authenticated' => false, 'error' => 'Missing session_id'], 422);
        }

        // Validate guard exists
        $guardName = $this->validateGuard($guardName);

        // Read session from Toporia's storage/sessions directory
        try {
            $sessionPath = config('session.stores.file.path', storage_path('sessions'));
            $sessionFile = $sessionPath . '/sess_' . $sessionId;

            if (!file_exists($sessionFile)) {
                return $this->jsonResponse([
                    'authenticated' => false,
                    'error' => 'Session not found',
                    'guard' => $guardName
                ]);
            }

            // Read and parse session file with size limit (prevent memory exhaustion)
            $maxSize = 1024 * 1024; // 1MB max session file
            $fileSize = @filesize($sessionFile);
            if ($fileSize === false || $fileSize > $maxSize) {
                return $this->jsonResponse([
                    'authenticated' => false,
                    'error' => 'Session file too large or unreadable',
                    'guard' => $guardName
                ]);
            }

            $content = @file_get_contents($sessionFile, false, null, 0, $maxSize);
            if ($content === false) {
                return $this->jsonResponse([
                    'authenticated' => false,
                    'error' => 'Cannot read session file',
                    'guard' => $guardName
                ]);
            }

            // Parse Toporia session format: serialize(['data' => [...], 'expires_at' => timestamp])
            $sessionData = @unserialize($content, ['allowed_classes' => false]);

            if (!is_array($sessionData) || !isset($sessionData['data'], $sessionData['expires_at'])) {
                return $this->jsonResponse([
                    'authenticated' => false,
                    'error' => 'Invalid session format',
                    'guard' => $guardName
                ]);
            }

            // Check expiration
            if ($sessionData['expires_at'] < time()) {
                return $this->jsonResponse([
                    'authenticated' => false,
                    'error' => 'Session expired',
                    'guard' => $guardName
                ]);
            }

            // Get user ID from session data
            $authKey = "auth_{$guardName}";
            $userId = $sessionData['data'][$authKey] ?? null;

            if ($userId === null) {
                return $this->jsonResponse([
                    'authenticated' => false,
                    'error' => 'No authenticated user in session',
                    'guard' => $guardName
                ]);
            }

            // Load user data from database
            $userData = $this->loadUserById($userId, $guardName);

            if ($userData === null) {
                return $this->jsonResponse([
                    'authenticated' => false,
                    'error' => 'User not found',
                    'guard' => $guardName
                ]);
            }

            return $this->jsonResponse([
                'authenticated' => true,
                'user' => $userData,
                'guard' => $guardName
            ]);
        } catch (\Throwable $e) {
            error_log("[BroadcastAuth] Session verify error: {$e->getMessage()}");
            return $this->jsonResponse([
                'authenticated' => false,
                'error' => 'Session verification failed'
            ], 500);
        }
    }

    /**
     * Load user by ID using the configured user provider.
     *
     * @param int|string $userId User ID
     * @param string $guardName Guard name
     * @return array|null User data or null
     */
    private function loadUserById(int|string $userId, string $guardName): ?array
    {
        // Method 1: Use auth manager's user provider
        if ($this->authManager !== null) {
            try {
                $guard = $this->authManager->guard($guardName);

                // Get user provider from guard if available
                if (method_exists($guard, 'getProvider')) {
                    $provider = $guard->getProvider();
                    $user = $provider->findById($userId);
                    if ($user !== null) {
                        return $this->formatUserData($user);
                    }
                }
            } catch (\Throwable $e) {
                error_log("[BroadcastAuth] loadUserById via guard error: {$e->getMessage()}");
            }
        }

        // Method 2: Try to find UserModel directly
        try {
            $userModelClass = config("auth.providers.users.model", 'Toporia\\App\\Infrastructure\\Persistence\\Models\\UserModel');

            if (class_exists($userModelClass)) {
                $user = $userModelClass::find($userId);
                if ($user !== null) {
                    return $this->formatUserData($user);
                }
            }
        } catch (\Throwable $e) {
            error_log("[BroadcastAuth] loadUserById via model error: {$e->getMessage()}");
        }

        // Method 3: Return minimal data if user exists
        return [
            'id' => $userId,
            'user_id' => $userId,
        ];
    }

    /**
     * Format user data for response.
     *
     * @param mixed $user User model or array
     * @return array Formatted user data
     */
    private function formatUserData(mixed $user): array
    {
        if (is_array($user)) {
            return [
                'id' => $user['id'] ?? null,
                'user_id' => $user['id'] ?? null,
                'name' => $user['name'] ?? null,
                'username' => $user['username'] ?? $user['name'] ?? null,
                'email' => $user['email'] ?? null,
                'roles' => $user['roles'] ?? [],
            ];
        }

        // Object (model)
        return [
            'id' => $user->id ?? $user->getId() ?? null,
            'user_id' => $user->id ?? $user->getId() ?? null,
            'name' => $user->name ?? null,
            'username' => $user->username ?? $user->name ?? null,
            'email' => $user->email ?? null,
            'roles' => $user->roles ?? [],
        ];
    }
}
