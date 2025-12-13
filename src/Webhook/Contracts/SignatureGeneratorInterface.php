<?php

declare(strict_types=1);

namespace Toporia\Framework\Webhook\Contracts;

/**
 * Interface SignatureGeneratorInterface
 *
 * Contract for generating webhook signatures for security.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Webhook\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface SignatureGeneratorInterface
{
    /**
     * Generate signature for webhook payload.
     *
     * @param array<string, mixed> $payload Webhook payload
     * @param string $secret Secret key
     * @return string Signature string
     */
    public function generate(array $payload, string $secret): string;

    /**
     * Verify signature.
     *
     * @param string $signature Signature to verify
     * @param array<string, mixed> $payload Webhook payload
     * @param string $secret Secret key
     * @return bool True if signature is valid
     */
    public function verify(string $signature, array $payload, string $secret): bool;

    /**
     * Get signature algorithm name.
     *
     * @return string Algorithm name (e.g., 'sha256', 'sha1')
     */
    public function getAlgorithm(): string;
}

