<?php

declare(strict_types=1);

namespace Toporia\Framework\Database;

use Toporia\Framework\Support\Collection\Collection;

/**
 * Abstract DatabaseCollection
 *
 * Common base class for RowCollection and ModelCollection.
 * Provides shared functionality and ensures compatible return types for database queries.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Database
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
abstract class DatabaseCollection extends Collection implements \JsonSerializable
{
    // This class serves as a common base for RowCollection and ModelCollection
    // to ensure compatible return types in QueryBuilder::get() and ModelQueryBuilder::get()

    // All methods are inherited from Collection parent class
    // No need to override unless adding specific functionality

    /**
     * Default JSON serialization - delegates to toArray().
     * Child classes can override this for custom serialization.
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
