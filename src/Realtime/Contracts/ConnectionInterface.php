<?php

declare(strict_types=1);

namespace Toporia\Framework\Realtime\Contracts;


/**
 * Interface ConnectionInterface
 *
 * Contract defining the interface for ConnectionInterface implementations
 * in the Real-time broadcasting layer of the Toporia Framework.
 *
 * Provides a developer-friendly API for accessing connection data:
 *
 * Authentication:
 *   - isAuthenticated(), isGuest()
 *   - getUserId(), getUser(), getUsername(), getEmail()
 *
 * Authorization:
 *   - hasRole('admin'), hasAnyRole(['admin', 'mod']), hasAllRoles(['user', 'verified'])
 *   - hasPermission('edit'), can('delete')
 *   - isAdmin(), isModerator(), isVerified(), isPremium()
 *
 * Channel Management:
 *   - getChannels(), subscribe(), unsubscribe(), isSubscribed()
 *
 * Connection Info:
 *   - getId(), getIpAddress(), getUserAgent(), getOrigin()
 *   - getConnectedAt(), getLastActivityAt(), getConnectionDuration()
 *
 * Metadata:
 *   - get('key'), set('key', value), has('key'), forget('key')
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Realtime\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface ConnectionInterface
{
    // =========================================================================
    // IDENTIFICATION
    // =========================================================================

    /**
     * Get connection unique identifier.
     *
     * Format: conn_xxxxx (uniqid)
     *
     * @return string Connection ID
     */
    public function getId(): string;

    // =========================================================================
    // AUTHENTICATION - User Identity
    // =========================================================================

    /**
     * Check if connection is authenticated (has user_id).
     *
     * @return bool
     */
    public function isAuthenticated(): bool;

    /**
     * Check if connection is a guest (not authenticated).
     *
     * @return bool
     */
    public function isGuest(): bool;

    /**
     * Get authenticated user ID.
     *
     * @return string|int|null
     */
    public function getUserId(): string|int|null;

    /**
     * Set authenticated user ID.
     *
     * @param string|int|null $userId
     * @return void
     */
    public function setUserId(string|int|null $userId): void;

    /**
     * Get the authenticated user object/array.
     *
     * @return array|object|null
     */
    public function getUser(): array|object|null;

    /**
     * Set the authenticated user object/array.
     *
     * @param array|object|null $user
     * @return void
     */
    public function setUser(array|object|null $user): void;

    /**
     * Get username of authenticated user.
     *
     * @return string|null
     */
    public function getUsername(): ?string;

    /**
     * Get email of authenticated user.
     *
     * @return string|null
     */
    public function getEmail(): ?string;

    // =========================================================================
    // AUTHORIZATION - Roles & Permissions
    // =========================================================================

    /**
     * Get all roles of the user.
     *
     * @return array<string>
     */
    public function getRoles(): array;

    /**
     * Set user roles.
     *
     * @param array<string> $roles
     * @return void
     */
    public function setRoles(array $roles): void;

    /**
     * Check if user has a specific role.
     *
     * Example: $connection->hasRole('admin')
     *
     * @param string $role Role name
     * @return bool
     */
    public function hasRole(string $role): bool;

    /**
     * Check if user has ANY of the specified roles.
     *
     * Example: $connection->hasAnyRole(['admin', 'moderator'])
     *
     * @param array<string> $roles Role names
     * @return bool
     */
    public function hasAnyRole(array $roles): bool;

    /**
     * Check if user has ALL of the specified roles.
     *
     * Example: $connection->hasAllRoles(['user', 'verified'])
     *
     * @param array<string> $roles Role names
     * @return bool
     */
    public function hasAllRoles(array $roles): bool;

    /**
     * Get all permissions of the user.
     *
     * @return array<string>
     */
    public function getPermissions(): array;

    /**
     * Set user permissions.
     *
     * @param array<string> $permissions
     * @return void
     */
    public function setPermissions(array $permissions): void;

    /**
     * Check if user has a specific permission.
     *
     * Example: $connection->hasPermission('users.edit')
     *
     * @param string $permission Permission name
     * @return bool
     */
    public function hasPermission(string $permission): bool;

    /**
     * Alias for hasPermission().
     *
     * Example: $connection->can('users.delete')
     *
     * @param string $permission Permission name
     * @return bool
     */
    public function can(string $permission): bool;

    /**
     * Check if user cannot perform an action.
     *
     * Example: $connection->cannot('users.delete')
     *
     * @param string $permission Permission name
     * @return bool
     */
    public function cannot(string $permission): bool;

    // =========================================================================
    // AUTHORIZATION - Role Shortcuts
    // =========================================================================

    /**
     * Check if user is an admin.
     *
     * @return bool
     */
    public function isAdmin(): bool;

    /**
     * Check if user is a moderator.
     *
     * @return bool
     */
    public function isModerator(): bool;

    /**
     * Check if user is verified.
     *
     * @return bool
     */
    public function isVerified(): bool;

    /**
     * Check if user has premium subscription.
     *
     * @return bool
     */
    public function isPremium(): bool;

    // =========================================================================
    // CHANNEL MANAGEMENT
    // =========================================================================

    /**
     * Get subscribed channels.
     *
     * @return array<string> Channel names
     */
    public function getChannels(): array;

    /**
     * Get number of subscribed channels.
     *
     * @return int
     */
    public function getChannelCount(): int;

    /**
     * Subscribe to a channel.
     *
     * @param string $channel Channel name
     * @return void
     */
    public function subscribe(string $channel): void;

    /**
     * Unsubscribe from a channel.
     *
     * @param string $channel Channel name
     * @return void
     */
    public function unsubscribe(string $channel): void;

    /**
     * Unsubscribe from all channels.
     *
     * @return void
     */
    public function unsubscribeAll(): void;

    /**
     * Check if subscribed to a channel.
     *
     * @param string $channel Channel name
     * @return bool
     */
    public function isSubscribed(string $channel): bool;

    // =========================================================================
    // CONNECTION INFO
    // =========================================================================

    /**
     * Get client IP address.
     *
     * @return string|null
     */
    public function getIpAddress(): ?string;

    /**
     * Get client User-Agent.
     *
     * @return string|null
     */
    public function getUserAgent(): ?string;

    /**
     * Get request origin.
     *
     * @return string|null
     */
    public function getOrigin(): ?string;

    /**
     * Get connection timestamp.
     *
     * @return int Unix timestamp
     */
    public function getConnectedAt(): int;

    /**
     * Get last activity timestamp.
     *
     * @return int Unix timestamp
     */
    public function getLastActivityAt(): int;

    /**
     * Update last activity timestamp to now.
     *
     * @return void
     */
    public function updateLastActivity(): void;

    /**
     * Get connection duration in seconds.
     *
     * @return int Duration in seconds
     */
    public function getConnectionDuration(): int;

    /**
     * Check if connection is idle (no activity for given seconds).
     *
     * @param int $seconds Idle threshold in seconds
     * @return bool
     */
    public function isIdle(int $seconds = 300): bool;

    // =========================================================================
    // METADATA - Generic Key-Value Storage
    // =========================================================================

    /**
     * Get all connection metadata.
     *
     * Contains: user_id, ip_address, user_agent, connected_at, etc.
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array;

    /**
     * Set all connection metadata (replaces existing).
     *
     * @param array<string, mixed> $metadata
     * @return void
     */
    public function setMetadata(array $metadata): void;

    /**
     * Merge metadata with existing values.
     *
     * @param array<string, mixed> $metadata
     * @return void
     */
    public function mergeMetadata(array $metadata): void;

    /**
     * Get specific metadata value.
     *
     * Example: $connection->get('custom_field', 'default')
     *
     * @param string $key Metadata key
     * @param mixed $default Default value if key not found
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Set specific metadata value.
     *
     * Example: $connection->set('custom_field', 'value')
     *
     * @param string $key Metadata key
     * @param mixed $value Metadata value
     * @return void
     */
    public function set(string $key, mixed $value): void;

    /**
     * Check if metadata key exists.
     *
     * @param string $key Metadata key
     * @return bool
     */
    public function has(string $key): bool;

    /**
     * Remove a metadata key.
     *
     * @param string $key Metadata key
     * @return void
     */
    public function forget(string $key): void;

    // =========================================================================
    // UTILITY
    // =========================================================================

    /**
     * Convert connection to array for debugging/logging.
     *
     * @return array
     */
    public function toArray(): array;

    /**
     * Clear connection state to prevent memory leaks.
     *
     * Call this method when disconnecting a client to ensure
     * all references are released and can be garbage collected.
     *
     * @return void
     */
    public function clear(): void;
}
