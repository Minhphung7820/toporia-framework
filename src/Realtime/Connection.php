<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime;

use Toporia\Framework\Realtime\Contracts\ConnectionInterface;

/**
 * Class Connection
 *
 * Represents a client connection with metadata and channel subscriptions.
 *
 * Provides a developer-friendly API for accessing connection data:
 *
 * Authentication:
 *   $connection->isAuthenticated()     // Check if logged in
 *   $connection->isGuest()             // Check if guest
 *   $connection->getUserId()           // Get user ID
 *   $connection->getUser()             // Get full user object
 *   $connection->getUsername()         // Get username
 *   $connection->getEmail()            // Get email
 *
 * Authorization:
 *   $connection->hasRole('admin')               // Check single role
 *   $connection->hasAnyRole(['admin', 'mod'])   // Check any role
 *   $connection->hasAllRoles(['user', 'verified']) // Check all roles
 *   $connection->can('users.edit')              // Check permission
 *   $connection->isAdmin()                      // Shortcut for admin
 *   $connection->isVerified()                   // Shortcut for verified
 *
 * Channel Management:
 *   $connection->getChannels()         // Get subscribed channels
 *   $connection->isSubscribed('chat')  // Check subscription
 *   $connection->subscribe('chat')     // Subscribe to channel
 *   $connection->unsubscribe('chat')   // Unsubscribe from channel
 *
 * Connection Info:
 *   $connection->getIpAddress()        // Client IP
 *   $connection->getUserAgent()        // Browser info
 *   $connection->getConnectionDuration() // How long connected
 *   $connection->isIdle(300)           // Idle for 5 minutes?
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Realtime
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class Connection implements ConnectionInterface
{
    private string $id;
    private array $metadata = [];
    private array $channels = [];
    private int $connectedAt;
    private int $lastActivityAt;

    /**
     * @param mixed $resource Underlying connection resource (socket, stream, etc.)
     * @param array $metadata Initial metadata
     */
    public function __construct(
        private readonly mixed $resource,
        array $metadata = []
    ) {
        $this->id = uniqid('conn_', true);
        $this->metadata = $metadata;
        $this->connectedAt = now()->getTimestamp();
        $this->lastActivityAt = now()->getTimestamp();
    }

    // =========================================================================
    // IDENTIFICATION
    // =========================================================================

    /**
     * {@inheritdoc}
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Set connection ID (for testing).
     *
     * @param string $id
     * @return void
     */
    public function setId(string $id): void
    {
        $this->id = $id;
    }

    // =========================================================================
    // AUTHENTICATION - User Identity
    // =========================================================================

    /**
     * {@inheritdoc}
     */
    public function isAuthenticated(): bool
    {
        return $this->getUserId() !== null;
    }

    /**
     * {@inheritdoc}
     */
    public function isGuest(): bool
    {
        return !$this->isAuthenticated();
    }

    /**
     * {@inheritdoc}
     */
    public function getUserId(): string|int|null
    {
        return $this->metadata['user_id'] ?? null;
    }

    /**
     * {@inheritdoc}
     */
    public function setUserId(string|int|null $userId): void
    {
        if ($userId === null) {
            unset($this->metadata['user_id']);
        } else {
            $this->metadata['user_id'] = $userId;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getUser(): array|object|null
    {
        return $this->metadata['user'] ?? null;
    }

    /**
     * {@inheritdoc}
     */
    public function setUser(array|object|null $user): void
    {
        if ($user === null) {
            unset($this->metadata['user']);
        } else {
            $this->metadata['user'] = $user;

            // Auto-extract common fields if array
            if (is_array($user)) {
                if (isset($user['id']) && !isset($this->metadata['user_id'])) {
                    $this->metadata['user_id'] = $user['id'];
                }
                if (isset($user['username']) && !isset($this->metadata['username'])) {
                    $this->metadata['username'] = $user['username'];
                }
                if (isset($user['email']) && !isset($this->metadata['email'])) {
                    $this->metadata['email'] = $user['email'];
                }
                if (isset($user['roles']) && !isset($this->metadata['roles'])) {
                    $this->metadata['roles'] = $user['roles'];
                }
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getUsername(): ?string
    {
        return $this->get('username');
    }

    /**
     * {@inheritdoc}
     */
    public function getEmail(): ?string
    {
        return $this->get('email');
    }

    // =========================================================================
    // AUTHORIZATION - Roles & Permissions
    // =========================================================================

    /**
     * {@inheritdoc}
     */
    public function getRoles(): array
    {
        $roles = $this->get('roles', []);
        return is_array($roles) ? $roles : [];
    }

    /**
     * {@inheritdoc}
     */
    public function setRoles(array $roles): void
    {
        $this->metadata['roles'] = array_values(array_unique($roles));
    }

    /**
     * {@inheritdoc}
     */
    public function hasRole(string $role): bool
    {
        return in_array($role, $this->getRoles(), true);
    }

    /**
     * {@inheritdoc}
     */
    public function hasAnyRole(array $roles): bool
    {
        $userRoles = $this->getRoles();
        return !empty(array_intersect($roles, $userRoles));
    }

    /**
     * {@inheritdoc}
     */
    public function hasAllRoles(array $roles): bool
    {
        $userRoles = $this->getRoles();
        return count(array_intersect($roles, $userRoles)) === count($roles);
    }

    /**
     * {@inheritdoc}
     */
    public function getPermissions(): array
    {
        $permissions = $this->get('permissions', []);
        return is_array($permissions) ? $permissions : [];
    }

    /**
     * {@inheritdoc}
     */
    public function setPermissions(array $permissions): void
    {
        $this->metadata['permissions'] = array_values(array_unique($permissions));
    }

    /**
     * {@inheritdoc}
     */
    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->getPermissions(), true);
    }

    /**
     * {@inheritdoc}
     */
    public function can(string $permission): bool
    {
        return $this->hasPermission($permission);
    }

    /**
     * {@inheritdoc}
     */
    public function cannot(string $permission): bool
    {
        return !$this->hasPermission($permission);
    }

    // =========================================================================
    // AUTHORIZATION - Role Shortcuts
    // =========================================================================

    /**
     * {@inheritdoc}
     */
    public function isAdmin(): bool
    {
        return $this->hasAnyRole(['admin', 'administrator', 'super_admin']);
    }

    /**
     * {@inheritdoc}
     */
    public function isModerator(): bool
    {
        return $this->hasAnyRole(['moderator', 'mod', 'admin', 'administrator']);
    }

    /**
     * {@inheritdoc}
     */
    public function isVerified(): bool
    {
        // Check role or flag
        if ($this->hasRole('verified')) {
            return true;
        }
        return (bool) $this->get('is_verified', false);
    }

    /**
     * {@inheritdoc}
     */
    public function isPremium(): bool
    {
        // Check role or flag
        if ($this->hasAnyRole(['premium', 'vip', 'pro'])) {
            return true;
        }
        return (bool) $this->get('is_premium', false);
    }

    // =========================================================================
    // CHANNEL MANAGEMENT
    // =========================================================================

    /**
     * {@inheritdoc}
     */
    public function getChannels(): array
    {
        return array_keys($this->channels);
    }

    /**
     * {@inheritdoc}
     */
    public function getChannelCount(): int
    {
        return count($this->channels);
    }

    /**
     * {@inheritdoc}
     */
    public function subscribe(string $channel): void
    {
        $this->channels[$channel] = true;
    }

    /**
     * {@inheritdoc}
     */
    public function unsubscribe(string $channel): void
    {
        unset($this->channels[$channel]);
    }

    /**
     * {@inheritdoc}
     */
    public function unsubscribeAll(): void
    {
        $this->channels = [];
    }

    /**
     * {@inheritdoc}
     */
    public function isSubscribed(string $channel): bool
    {
        return isset($this->channels[$channel]);
    }

    // =========================================================================
    // CONNECTION INFO
    // =========================================================================

    /**
     * {@inheritdoc}
     */
    public function getIpAddress(): ?string
    {
        return $this->get('ip_address') ?? $this->get('ip');
    }

    /**
     * {@inheritdoc}
     */
    public function getUserAgent(): ?string
    {
        return $this->get('user_agent');
    }

    /**
     * {@inheritdoc}
     */
    public function getOrigin(): ?string
    {
        return $this->get('origin');
    }

    /**
     * {@inheritdoc}
     */
    public function getConnectedAt(): int
    {
        return $this->connectedAt;
    }

    /**
     * {@inheritdoc}
     */
    public function getLastActivityAt(): int
    {
        return $this->lastActivityAt;
    }

    /**
     * {@inheritdoc}
     */
    public function updateLastActivity(): void
    {
        $this->lastActivityAt = now()->getTimestamp();
    }

    /**
     * {@inheritdoc}
     */
    public function getConnectionDuration(): int
    {
        return now()->getTimestamp() - $this->connectedAt;
    }

    /**
     * {@inheritdoc}
     */
    public function isIdle(int $seconds = 300): bool
    {
        return (now()->getTimestamp() - $this->lastActivityAt) >= $seconds;
    }

    // =========================================================================
    // METADATA - Generic Key-Value Storage
    // =========================================================================

    /**
     * {@inheritdoc}
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * {@inheritdoc}
     */
    public function setMetadata(array $metadata): void
    {
        $this->metadata = $metadata;
    }

    /**
     * {@inheritdoc}
     */
    public function mergeMetadata(array $metadata): void
    {
        $this->metadata = array_merge($this->metadata, $metadata);
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $key, mixed $value): void
    {
        $this->metadata[$key] = $value;
    }

    /**
     * {@inheritdoc}
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->metadata);
    }

    /**
     * {@inheritdoc}
     */
    public function forget(string $key): void
    {
        unset($this->metadata[$key]);
    }

    // =========================================================================
    // UTILITY
    // =========================================================================

    /**
     * {@inheritdoc}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->getUserId(),
            'username' => $this->getUsername(),
            'email' => $this->getEmail(),
            'roles' => $this->getRoles(),
            'permissions' => $this->getPermissions(),
            'channels' => $this->getChannels(),
            'ip_address' => $this->getIpAddress(),
            'user_agent' => $this->getUserAgent(),
            'origin' => $this->getOrigin(),
            'is_authenticated' => $this->isAuthenticated(),
            'is_admin' => $this->isAdmin(),
            'is_verified' => $this->isVerified(),
            'is_premium' => $this->isPremium(),
            'connected_at' => $this->connectedAt,
            'last_activity_at' => $this->lastActivityAt,
            'connection_duration' => $this->getConnectionDuration(),
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Get underlying connection resource.
     *
     * @return mixed Socket, stream, or other resource
     */
    public function getResource(): mixed
    {
        return $this->resource;
    }

    /**
     * Clear connection state to prevent memory leaks.
     *
     * Call this method when disconnecting a client to ensure
     * all references are released and can be garbage collected.
     *
     * @return void
     */
    public function clear(): void
    {
        // Clear all channel subscriptions
        $this->channels = [];

        // Clear metadata (including user data, roles, permissions)
        $this->metadata = [];
    }
}
