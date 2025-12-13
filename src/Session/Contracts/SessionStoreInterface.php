<?php

declare(strict_types=1);

namespace Toporia\Framework\Session\Contracts;


/**
 * Interface SessionStoreInterface
 *
 * Contract defining the interface for SessionStoreInterface
 * implementations in the Session management layer of the Toporia
 * Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Session\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface SessionStoreInterface
{
    /**
     * Start the session.
     *
     * @return bool True on success
     */
    public function start(): bool;

    /**
     * Get a session value.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Set a session value.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function set(string $key, mixed $value): void;

    /**
     * Put a session value (alias for set).
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function put(string $key, mixed $value): void;

    /**
     * Check if a session key exists.
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool;

    /**
     * Remove a session value.
     *
     * @param string $key
     * @return void
     */
    public function remove(string $key): void;

    /**
     * Get all session data.
     *
     * @return array
     */
    public function all(): array;

    /**
     * Clear all session data.
     *
     * @return void
     */
    public function flush(): void;

    /**
     * Regenerate session ID.
     *
     * @param bool $deleteOldSession Delete old session data
     * @return bool True on success
     */
    public function regenerate(bool $deleteOldSession = false): bool;

    /**
     * Get session ID.
     *
     * @return string
     */
    public function getId(): string;

    /**
     * Set session ID.
     *
     * @param string $id
     * @return void
     */
    public function setId(string $id): void;

    /**
     * Get session name.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Save session data.
     *
     * @return bool True on success
     */
    public function save(): bool;
}
