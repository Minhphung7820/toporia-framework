<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\ORM\Exceptions;

use RuntimeException;

/**
 * Exception thrown when a relationship method is not found on a model.
 *
 * This exception is thrown when attempting to eager load or access a relationship
 * that doesn't exist on the model. This helps catch typos and missing relationship
 * definitions early.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  ORM/Exceptions
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung485/toporia
 */
class RelationNotFoundException extends RuntimeException
{
    /**
     * Create a new relation not found exception.
     *
     * @param string $modelClass The model class name
     * @param string $relationName The relationship name that was not found
     * @param string|null $nestedPath Optional nested relation path (e.g., 'reviews.user')
     * @return static
     */
    public static function forRelation(string $modelClass, string $relationName, ?string $nestedPath = null): static
    {
        $message = sprintf(
            'Relationship [%s] not found on model [%s].',
            $relationName,
            $modelClass
        );

        if ($nestedPath !== null) {
            $message .= sprintf(
                ' Attempted to load nested relation: [%s].',
                $nestedPath
            );
        }

        return new static($message);
    }
}
