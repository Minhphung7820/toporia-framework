<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\Query;

use Toporia\Framework\Database\DatabaseCollection;


/**
 * Class RowCollection
 *
 * Core class for the Query layer providing essential functionality for the
 * Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Query
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class RowCollection extends DatabaseCollection implements \JsonSerializable
{
  /**
   * Return a collection of a given column's values (like pluck).
   *
   * @param string $key Column name to extract from each row.
   * @return static New collection with values or null where missing.
   */
  public function pluckCol(string $key): static
  {
    return $this->map(fn(array $row) => $row[$key] ?? null);
  }

  /**
   * Find first row where column matches value.
   *
   * Supports three forms:
   * - firstWhere('status', 'active')  // key = value
   * - firstWhere('price', '>', 100)   // key operator value
   * - firstWhere(fn($row) => $row['active'])  // callback
   *
   * @param string|callable $key
   * @param mixed $operator
   * @param mixed $value
   * @return mixed
   */
  public function firstWhere(string|callable $key, mixed $operator = null, mixed $value = null): mixed
  {
    // Callback form
    if (is_callable($key)) {
      foreach ($this->all() as $k => $row) {
        if ($key($row, $k)) return $row;
      }
      return null;
    }

    // Two-argument form: firstWhere('status', 'active')
    if ($value === null) {
      $value = $operator;
      $operator = '=';
    }

    // Three-argument form: firstWhere('price', '>', 100)
    foreach ($this->all() as $row) {
      $actual = $row[$key] ?? null;

      $matches = match ($operator) {
        '=' => $actual === $value,
        '==' => $actual == $value,
        '!=' => $actual != $value,
        '!==' => $actual !== $value,
        '<' => $actual < $value,
        '>' => $actual > $value,
        '<=' => $actual <= $value,
        '>=' => $actual >= $value,
        default => $actual === $value,
      };

      if ($matches) return $row;
    }
    return null;
  }

  /**
   * Convert to a plain array of rows.
   *
   * Re-indexes the array to ensure sequential numeric keys (0, 1, 2...)
   * This prevents JSON from encoding as object with string keys.
   *
   * @return array<int, array<string,mixed>>
   */
  public function toArray(): array
  {
    /** @var array<int, array<string,mixed>> $items */
    $items = parent::values()->all();
    return $items;
  }

  /**
   * Specify data which should be serialized to JSON.
   *
   * @return array<int, array<string,mixed>>
   */
  public function jsonSerialize(): array
  {
    return $this->toArray();
  }
}
