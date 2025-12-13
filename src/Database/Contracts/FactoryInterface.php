<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\Contracts;

use Toporia\Framework\Database\ORM\Model;


/**
 * Interface FactoryInterface
 *
 * Contract defining the interface for FactoryInterface implementations in
 * the Database query building and ORM layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Database\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @internal    This class is a core component and should not be extended
 *              directly unless you know what you're doing.
 */
interface FactoryInterface
{
    /**
     * Create a new factory instance.
     *
     * @return static
     */
    public static function new(): static;

    /**
     * Create a new model instance (not persisted).
     *
     * @param array<string, mixed> $attributes
     * @return T
     */
    public function make(array $attributes = []): Model;

    /**
     * Create a model instance and persist it to database.
     *
     * @param array<string, mixed> $attributes
     * @return T
     */
    public function create(array $attributes = []): Model;

    /**
     * Create multiple model instances (not persisted).
     *
     * @param int $count
     * @param array<string, mixed> $attributes
     * @return array<int, T>
     */
    public function makeMany(int $count, array $attributes = []): array;

    /**
     * Create multiple model instances and persist them to database.
     *
     * @param int $count
     * @param array<string, mixed> $attributes
     * @return array<int, T>
     */
    public function createMany(int $count, array $attributes = []): array;

    /**
     * Apply state transformations.
     *
     * @param string|callable|array<string, mixed> $state
     * @return static
     */
    public function state(string|callable|array $state): static;

    /**
     * Define model's default attributes.
     *
     * @return array<string, mixed>
     */
    public function definition(): array;
}

