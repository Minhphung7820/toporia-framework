<?php

declare(strict_types=1);

namespace Toporia\Framework\Hashing;

use Toporia\Framework\Hashing\Contracts\HasherInterface;

/**
 * Class BcryptHasher
 *
 * Bcrypt implementation using PHP's password_hash() with PASSWORD_BCRYPT.
 * Industry standard, battle-tested, widely supported.
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
final class BcryptHasher implements HasherInterface
{
    /**
     * Default bcrypt cost factor.
     * Recommended: 12 (good balance of security and performance)
     */
    private const DEFAULT_COST = 12;

    /**
     * Minimum allowed cost.
     */
    private const MIN_COST = 4;

    /**
     * Maximum allowed cost.
     */
    private const MAX_COST = 31;

    /**
     * @param int $cost Default cost factor (4-31)
     */
    public function __construct(
        private readonly int $cost = self::DEFAULT_COST
    ) {
        $this->validateCost($cost);
    }

    /**
     * {@inheritdoc}
     */
    public function make(string $value, array $options = []): string
    {
        $cost = $options['cost'] ?? $this->cost;
        $this->validateCost($cost);

        // Hash using bcrypt algorithm
        $hash = password_hash($value, PASSWORD_BCRYPT, [
            'cost' => $cost,
        ]);

        if ($hash === false) {
            throw new \RuntimeException('Bcrypt hashing failed');
        }

        return $hash;
    }

    /**
     * {@inheritdoc}
     *
     * Uses password_verify() which is timing-safe.
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
        $cost = $options['cost'] ?? $this->cost;
        $this->validateCost($cost);

        return password_needs_rehash($hashedValue, PASSWORD_BCRYPT, [
            'cost' => $cost,
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
     * Validate cost parameter.
     *
     * @param int $cost Cost factor
     * @return void
     * @throws \InvalidArgumentException If cost invalid
     */
    private function validateCost(int $cost): void
    {
        if ($cost < self::MIN_COST || $cost > self::MAX_COST) {
            throw new \InvalidArgumentException(
                "Bcrypt cost must be between " . self::MIN_COST . " and " . self::MAX_COST .
                    ", {$cost} given"
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

    /**
     * Set default cost.
     *
     * Useful for testing or adjusting security level.
     *
     * @param int $cost Cost factor
     * @return static
     */
    public function setDefaultCost(int $cost): static
    {
        $this->validateCost($cost);
        return new static($cost);
    }
}
