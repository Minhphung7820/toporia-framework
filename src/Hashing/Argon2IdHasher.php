<?php

declare(strict_types=1);

namespace Toporia\Framework\Hashing;

use Toporia\Framework\Hashing\Contracts\HasherInterface;

/**
 * Class Argon2IdHasher
 *
 * Modern password hashing using Argon2id algorithm.
 * Winner of Password Hashing Competition (2015).
 * Recommended for new applications (PHP 7.3+).
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Hashing
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class Argon2IdHasher implements HasherInterface
{
    /**
     * Default memory cost in KB (64 MB).
     * Recommended: 65536 KB for good security/performance balance
     */
    private const DEFAULT_MEMORY = 65536;

    /**
     * Default time cost (iterations).
     * Recommended: 4 iterations
     */
    private const DEFAULT_TIME = 4;

    /**
     * Default thread count.
     * Recommended: 1 for most applications
     */
    private const DEFAULT_THREADS = 1;

    /**
     * @param int $memory Memory cost in KB
     * @param int $time Time cost (iterations)
     * @param int $threads Parallel thread count
     */
    public function __construct(
        private readonly int $memory = self::DEFAULT_MEMORY,
        private readonly int $time = self::DEFAULT_TIME,
        private readonly int $threads = self::DEFAULT_THREADS
    ) {
        $this->verifyAlgorithmSupport();
        $this->validateOptions($memory, $time, $threads);
    }

    /**
     * {@inheritdoc}
     */
    public function make(string $value, array $options = []): string
    {
        $memory = $options['memory'] ?? $this->memory;
        $time = $options['time'] ?? $this->time;
        $threads = $options['threads'] ?? $this->threads;

        $this->validateOptions($memory, $time, $threads);

        // Hash using Argon2id algorithm
        $hash = password_hash($value, PASSWORD_ARGON2ID, [
            'memory_cost' => $memory,
            'time_cost' => $time,
            'threads' => $threads,
        ]);

        if ($hash === false) {
            throw new \RuntimeException('Argon2id hashing failed');
        }

        return $hash;
    }

    /**
     * {@inheritdoc}
     */
    public function check(string $value, string $hashedValue, array $options = []): bool
    {
        if (strlen($hashedValue) === 0) {
            return false;
        }

        // Timing-safe comparison
        return password_verify($value, $hashedValue);
    }

    /**
     * {@inheritdoc}
     */
    public function needsRehash(string $hashedValue, array $options = []): bool
    {
        $memory = $options['memory'] ?? $this->memory;
        $time = $options['time'] ?? $this->time;
        $threads = $options['threads'] ?? $this->threads;

        $this->validateOptions($memory, $time, $threads);

        return password_needs_rehash($hashedValue, PASSWORD_ARGON2ID, [
            'memory_cost' => $memory,
            'time_cost' => $time,
            'threads' => $threads,
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function info(string $hashedValue): array
    {
        $info = password_get_info($hashedValue);

        return [
            'algo' => $this->getAlgorithmName($info['algo']),
            'algoName' => $info['algoName'] ?? 'unknown',
            'options' => $info['options'] ?? [],
        ];
    }

    /**
     * Verify that Argon2id is supported.
     *
     * @return void
     * @throws \RuntimeException If not supported
     */
    private function verifyAlgorithmSupport(): void
    {
        if (!defined('PASSWORD_ARGON2ID')) {
            throw new \RuntimeException(
                'Argon2id is not supported. ' .
                    'Requires PHP 7.3+ compiled with Argon2 support. ' .
                    'Use BcryptHasher as fallback.'
            );
        }
    }

    /**
     * Validate hashing options.
     *
     * @param int $memory Memory cost in KB
     * @param int $time Time cost
     * @param int $threads Thread count
     * @return void
     * @throws \InvalidArgumentException If options invalid
     */
    private function validateOptions(int $memory, int $time, int $threads): void
    {
        if ($memory < 1024) {
            throw new \InvalidArgumentException(
                "Argon2id memory cost must be at least 1024 KB, {$memory} given"
            );
        }

        if ($time < 1) {
            throw new \InvalidArgumentException(
                "Argon2id time cost must be at least 1, {$time} given"
            );
        }

        if ($threads < 1) {
            throw new \InvalidArgumentException(
                "Argon2id threads must be at least 1, {$threads} given"
            );
        }
    }

    /**
     * Get algorithm name from constant.
     *
     * @param int|string $algo Algorithm constant
     * @return string Algorithm name
     */
    private function getAlgorithmName(int|string $algo): string
    {
        return match ($algo) {
            PASSWORD_BCRYPT, '2y' => 'bcrypt',
            PASSWORD_ARGON2I, 'argon2i' => 'argon2i',
            PASSWORD_ARGON2ID, 'argon2id' => 'argon2id',
            default => 'unknown'
        };
    }
}
