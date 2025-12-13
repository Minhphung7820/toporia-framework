<?php

declare(strict_types=1);

namespace Toporia\Framework\Encryption\Contracts;

/**
 * Interface EncrypterInterface
 *
 * Contract defining the interface for EncrypterInterface implementations
 * in the Encryption layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Encryption\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface EncrypterInterface
{
    /**
     * Encrypt the given value.
     *
     * @param mixed $value
     * @param bool $serialize
     * @return string
     */
    public function encrypt(mixed $value, bool $serialize = true): string;

    /**
     * Encrypt a string without serialization.
     *
     * @param string $value
     * @return string
     */
    public function encryptString(string $value): string;

    /**
     * Decrypt the given value.
     *
     * @param string $payload
     * @param bool $unserialize
     * @return mixed
     */
    public function decrypt(string $payload, bool $unserialize = true): mixed;

    /**
     * Decrypt a string without unserialization.
     *
     * @param string $payload
     * @return string
     */
    public function decryptString(string $payload): string;

    /**
     * Get the encryption key.
     *
     * @return string
     */
    public function getKey(): string;
}
