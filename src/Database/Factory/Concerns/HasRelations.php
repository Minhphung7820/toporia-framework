<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\Factory\Concerns;

use Toporia\Framework\Database\Factory;
use Toporia\Framework\Database\ORM\Model;
use Closure;


/**
 * Trait HasRelations
 *
 * Trait providing reusable functionality for HasRelations in the Concerns
 * layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Concerns
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
trait HasRelations
{
    /**
     * Create related models after creating the parent model.
     *
     * @param Factory|Closure $factoryOrCallback Factory instance or closure
     * @param string|null $relationship Relationship name (optional)
     * @return static
     */
    public function has(Factory|Closure $factoryOrCallback, ?string $relationship = null): static
    {
        return $this->afterCreating(function (Model $parent) use ($factoryOrCallback, $relationship) {
            if ($factoryOrCallback instanceof Factory) {
                $factoryOrCallback->create();
            } elseif ($factoryOrCallback instanceof Closure) {
                $factoryOrCallback($parent);
            }
        });
    }

    /**
     * Create related models with count.
     *
     * @param Factory $factory Factory instance
     * @param int $count Number of related models to create
     * @param string|null $relationship Relationship name (optional)
     * @return static
     */
    public function hasMany(Factory $factory, int $count, ?string $relationship = null): static
    {
        return $this->afterCreating(function (Model $parent) use ($factory, $count, $relationship) {
            $factory->count($count)->create();
        });
    }

    /**
     * Create related model for belongsTo relationship.
     *
     * @param Factory $factory Factory instance
     * @param string $foreignKey Foreign key attribute name
     * @return static
     */
    public function belongsTo(Factory $factory, string $foreignKey = 'id'): static
    {
        return $this->state(function (array $attributes) use ($factory, $foreignKey) {
            $related = $factory->create();
            $attributes[$foreignKey] = $related->getAttribute('id');
            return $attributes;
        });
    }

    /**
     * Create related models and attach to many-to-many relationship.
     *
     * @param Factory $factory Factory instance
     * @param int $count Number of related models
     * @param string $relationship Relationship method name
     * @return static
     */
    public function hasAttached(Factory $factory, int $count, string $relationship): static
    {
        return $this->afterCreating(function (Model $parent) use ($factory, $count, $relationship) {
            // createMany returns array of Models when count > 1
            $related = $factory->createMany($count);

            if (method_exists($parent, $relationship)) {
                $relation = $parent->$relationship();
                if (method_exists($relation, 'attach')) {
                    // Extract IDs from array of models
                    $ids = is_array($related)
                        ? array_map(fn(Model $model) => $model->getAttribute('id'), $related)
                        : [$related->getAttribute('id')];
                    $relation->attach($ids);
                }
            }
        });
    }
}

