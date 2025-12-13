<?php

declare(strict_types=1);

namespace Toporia\Framework\Http\Middleware;

use Toporia\Framework\Http\Contracts\MiddlewareInterface;
use Toporia\Framework\Http\{Request, Response};
use Toporia\Framework\Security\Contracts\ReplayAttackProtectionInterface;

/**
 * Class ReplayAttackProtection
 *
 * Prevents replay attacks by validating nonces in requests. Works in conjunction with CSRF protection for comprehensive security.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Http\Middleware
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class ReplayAttackProtection implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];
    private const NONCE_FIELDS = ['_nonce', 'nonce', 'replay_nonce'];
    private const NONCE_HEADER = 'X-Replay-Nonce';

    /**
     * Default TTL for nonces (5 minutes)
     */
    private const DEFAULT_NONCE_TTL = 300;

    /**
     * Cleanup probability (1 in N requests)
     */
    private const DEFAULT_CLEANUP_PROBABILITY = 100;

    /**
     * @var int Nonce TTL in seconds
     */
    private int $nonceTtl;

    /**
     * @var int Cleanup probability (1 in N requests)
     */
    private int $cleanupProbability;

    /**
     * @param ReplayAttackProtectionInterface $protection
     * @param int|null $nonceTtl Nonce TTL in seconds (null = resolve from config)
     * @param int|null $cleanupProbability Cleanup probability (null = resolve from config)
     */
    public function __construct(
        private ReplayAttackProtectionInterface $protection,
        ?int $nonceTtl = null,
        ?int $cleanupProbability = null
    ) {
        // Auto-resolve from config if not provided
        try {
            $config = app('config');
            $this->nonceTtl = $nonceTtl ?? $config->get('security.replay.nonce_ttl', self::DEFAULT_NONCE_TTL) ?? self::DEFAULT_NONCE_TTL;
            $this->cleanupProbability = $cleanupProbability ?? $config->get('security.replay.cleanup_probability', self::DEFAULT_CLEANUP_PROBABILITY) ?? self::DEFAULT_CLEANUP_PROBABILITY;
        } catch (\Throwable $e) {
            $this->nonceTtl = $nonceTtl ?? self::DEFAULT_NONCE_TTL;
            $this->cleanupProbability = $cleanupProbability ?? self::DEFAULT_CLEANUP_PROBABILITY;
        }
    }

    /**
     * Handle the request.
     *
     * Validates nonce for state-changing requests.
     *
     * @param Request $request
     * @param Response $response
     * @param callable $next
     * @return mixed
     */
    public function handle(Request $request, Response $response, callable $next): mixed
    {
        // Skip validation for safe methods
        if ($this->isSafeMethod($request->method())) {
            return $next($request, $response);
        }

        // Get nonce from request
        $nonce = $this->getNonceFromRequest($request);

        // Validate nonce
        if (!$this->validateNonce($nonce)) {
            $response->setStatus(403);
            $response->json([
                'error' => 'Replay attack detected',
                'message' => 'The request nonce is invalid, expired, or has already been used. Please refresh the page and try again.'
            ], 403);
            return null; // Short-circuit
        }

        // Periodic cleanup (probabilistic to avoid overhead)
        // Performance: O(1) - Random check, cleanup only when triggered
        if ($this->shouldCleanup()) {
            $this->protection->cleanupExpired();
        }

        return $next($request, $response);
    }

    /**
     * Check if the HTTP method is safe (doesn't require replay protection)
     *
     * Performance: O(1) - Array lookup
     *
     * @param string $method
     * @return bool
     */
    private function isSafeMethod(string $method): bool
    {
        return in_array(strtoupper($method), self::SAFE_METHODS, true);
    }

    /**
     * Get nonce from request.
     *
     * Checks multiple locations:
     * 1. Request body/input fields
     * 2. X-Replay-Nonce header
     *
     * Performance: O(N) where N = number of nonce fields (typically 3)
     *
     * @param Request $request
     * @return string|null
     */
    private function getNonceFromRequest(Request $request): ?string
    {
        // Try input fields first
        foreach (self::NONCE_FIELDS as $field) {
            $nonce = $request->input($field);
            if ($nonce !== null && $nonce !== '') {
                return $nonce;
            }
        }

        // Try header
        $headerNonce = $request->header(self::NONCE_HEADER);
        if ($headerNonce !== null && $headerNonce !== '') {
            return $headerNonce;
        }

        return null;
    }

    /**
     * Validate the nonce.
     *
     * Performance: O(1) - Direct validation call
     *
     * @param string|null $nonce
     * @return bool
     */
    private function validateNonce(?string $nonce): bool
    {
        if ($nonce === null || $nonce === '') {
            return false;
        }

        return $this->protection->validateNonce($nonce);
    }

    /**
     * Determine if cleanup should run.
     *
     * Uses probabilistic approach to avoid overhead on every request.
     *
     * Performance: O(1) - Single random number generation
     *
     * @return bool
     */
    private function shouldCleanup(): bool
    {
        return random_int(1, $this->cleanupProbability) === 1;
    }
}
