<?php

declare(strict_types=1);

namespace Toporia\Framework\Repository\Exceptions;

/**
 * Class EntityNotFoundException
 *
 * Exception thrown when an entity is not found.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Repository\Exceptions
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class EntityNotFoundException extends RepositoryException
{
    /**
     * @param string $model Model class name
     * @param int|string|array<int|string> $id Entity ID(s)
     */
    public function __construct(
        public readonly string $model,
        public readonly int|string|array $id
    ) {
        $ids = is_array($id) ? implode(', ', $id) : $id;
        parent::__construct("Entity [{$model}] not found for ID(s): {$ids}");
    }

    /**
     * Get the model class name.
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Get the missing ID(s).
     */
    public function getId(): int|string|array
    {
        return $this->id;
    }
}
