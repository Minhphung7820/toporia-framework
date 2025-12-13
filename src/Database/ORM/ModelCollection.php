<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\ORM;

use Toporia\Framework\Database\DatabaseCollection;


/**
 * Class ModelCollection
 *
 * Core class for the ORM layer providing essential functionality for the
 * Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  ORM
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class ModelCollection extends DatabaseCollection implements \JsonSerializable
{
  /**
   * Return the array of primary keys for all models in the collection.
   *
   * @return array<int, int|string>
   */
  public function modelKeys(): array
  {
    return $this->map(fn(Model $m) => $m->getKey())->values()->all();
  }

  /**
   * Find the first model with a matching primary key.
   *
   * PERFORMANCE: Early return on first match. Type check ensures Model instance.
   *
   * @param int|string $key
   * @return Model|null
   */
  public function find(int|string $key): ?Model
  {
    foreach ($this->all() as $m) {
      // Type check ensures Model instance (ModelCollection should only contain Models)
      if ($m instanceof Model && $m->getKey() === $key) {
        return $m;
      }
    }
    return null;
  }

  /**
   * Save all models in the collection (if they implement ->save()).
   *
   * PERFORMANCE: Removed method_exists check since all Model instances have save() method.
   *
   * @return int Number of successful saves.
   */
  public function save(): int
  {
    $ok = 0;
    foreach ($this->all() as $m) {
      // PERFORMANCE: All Model instances have save() method, so method_exists check is redundant
      if ($m->save()) {
        $ok++;
      }
    }
    return $ok;
  }

  /**
   * Convert the collection to an array of model arrays.
   *
   * PERFORMANCE: Removed method_exists check since all Model instances have toArray() method.
   *
   * @return array<int, array<string,mixed>>
   */
  public function toArray(): array
  {
    // PERFORMANCE: All Model instances have toArray() method, so method_exists check is redundant
    return $this->map(fn(Model $m) => $m->toArray())->values()->all();
  }

  /**
   * Convert the collection to an array for JSON serialization.
   *
   * Toporia compatibility: implements JsonSerializable interface.
   *
   * @return array
   */
  public function jsonSerialize(): array
  {
    return $this->toArray();
  }
}
