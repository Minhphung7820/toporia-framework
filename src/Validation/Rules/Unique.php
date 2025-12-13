<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules;

use Toporia\Framework\Container\Container;
use Toporia\Framework\Database\Contracts\ConnectionInterface;
use Toporia\Framework\Validation\Contracts\DataAwareRuleInterface;
use Toporia\Framework\Validation\Contracts\RuleInterface;
use Toporia\Framework\Validation\ValidationData;

/**
 * Class Unique
 *
 * Validates that a value is unique in a database table.
 * Supports ignore conditions for update scenarios.
 *
 * Usage:
 *   // Basic: check unique email in users table
 *   Rule::unique('users', 'email')
 *
 *   // Ignore specific ID (for update)
 *   Rule::unique('users', 'email')->ignore($userId)
 *
 *   // Ignore with custom column
 *   Rule::unique('users', 'email')->ignore($userId, 'user_id')
 *
 *   // With additional where conditions
 *   Rule::unique('users', 'email')->where('tenant_id', $tenantId)
 *
 *   // Complex example
 *   Rule::unique('products', 'sku')
 *       ->ignore($product->id)
 *       ->where('store_id', $storeId)
 *       ->whereNull('deleted_at')
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     2.0.0
 * @package     toporia/framework
 * @subpackage  Validation\Rules
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class Unique implements RuleInterface, DataAwareRuleInterface
{
    /**
     * Database table name.
     */
    private string $table;

    /**
     * Column name to check.
     */
    private string $column;

    /**
     * ID to ignore (for updates).
     */
    private mixed $ignoreId = null;

    /**
     * Column name for ignore ID.
     */
    private string $ignoreIdColumn = 'id';

    /**
     * Additional where conditions: [['column', 'operator', 'value'], ...]
     *
     * @var array<array{string, string, mixed}>
     */
    private array $whereConditions = [];

    /**
     * Where null conditions.
     *
     * @var array<string>
     */
    private array $whereNullConditions = [];

    /**
     * Where not null conditions.
     *
     * @var array<string>
     */
    private array $whereNotNullConditions = [];

    /**
     * Validation data (for accessing other fields).
     */
    private ?ValidationData $data = null;

    /**
     * Create a new unique rule instance.
     *
     * @param string $table Database table name
     * @param string|null $column Column name to check (defaults to attribute name)
     * @param mixed $ignoreId ID to ignore (for backward compatibility)
     * @param string|null $ignoreIdColumn Column for ignore ID
     */
    public function __construct(
        string $table,
        ?string $column = null,
        mixed $ignoreId = null,
        ?string $ignoreIdColumn = null
    ) {
        $this->table = $table;
        $this->column = $column ?? '';

        if ($ignoreId !== null) {
            $this->ignoreId = $ignoreId;
        }

        if ($ignoreIdColumn !== null) {
            $this->ignoreIdColumn = $ignoreIdColumn;
        }
    }

    /**
     * Set the ID to ignore during uniqueness check.
     * Useful for update operations where the current record should be excluded.
     *
     * @param mixed $id The ID to ignore
     * @param string $column The column name for the ID (default: 'id')
     * @return self
     */
    public function ignore(mixed $id, string $column = 'id'): self
    {
        $this->ignoreId = $id;
        $this->ignoreIdColumn = $column;
        return $this;
    }

    /**
     * Add a where condition.
     *
     * @param string $column Column name
     * @param mixed $operatorOrValue Operator or value (if value is omitted)
     * @param mixed $value Value (optional if operator is '=')
     * @return self
     */
    public function where(string $column, mixed $operatorOrValue, mixed $value = null): self
    {
        if ($value === null) {
            // Two arguments: column, value (operator is '=')
            $this->whereConditions[] = [$column, '=', $operatorOrValue];
        } else {
            // Three arguments: column, operator, value
            $this->whereConditions[] = [$column, $operatorOrValue, $value];
        }
        return $this;
    }

    /**
     * Add a where not equal condition.
     *
     * @param string $column Column name
     * @param mixed $value Value
     * @return self
     */
    public function whereNot(string $column, mixed $value): self
    {
        $this->whereConditions[] = [$column, '!=', $value];
        return $this;
    }

    /**
     * Add a where null condition.
     *
     * @param string $column Column name
     * @return self
     */
    public function whereNull(string $column): self
    {
        $this->whereNullConditions[] = $column;
        return $this;
    }

    /**
     * Add a where not null condition.
     *
     * @param string $column Column name
     * @return self
     */
    public function whereNotNull(string $column): self
    {
        $this->whereNotNullConditions[] = $column;
        return $this;
    }

    /**
     * Set validation data.
     *
     * @param ValidationData $data Validation data
     * @return void
     */
    public function setData(ValidationData $data): void
    {
        $this->data = $data;
    }

    /**
     * Determine if the validation rule passes.
     *
     * @param string $attribute Attribute name
     * @param mixed $value Attribute value
     * @return bool
     */
    public function passes(string $attribute, mixed $value): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        // Use attribute name as column if not specified
        $column = $this->column ?: $attribute;

        // Handle array values
        if (is_array($value)) {
            return $this->validateArray($value, $column);
        }

        return $this->validateValue($value, $column);
    }

    /**
     * Validate a single value.
     *
     * @param mixed $value Value to validate
     * @param string $column Column name
     * @return bool
     */
    private function validateValue(mixed $value, string $column): bool
    {
        $db = $this->getConnection();

        if (method_exists($db, 'table')) {
            return $this->validateWithQueryBuilder($db, $value, $column);
        }

        if (method_exists($db, 'prepare')) {
            return $this->validateWithPdo($db, $value, $column);
        }

        throw new \RuntimeException('Database connection must be PDO or QueryBuilder instance');
    }

    /**
     * Validate using QueryBuilder.
     *
     * @param object $db Database connection
     * @param mixed $value Value to check
     * @param string $column Column name
     * @return bool
     */
    private function validateWithQueryBuilder(object $db, mixed $value, string $column): bool
    {
        $query = $db->table($this->table)->where($column, $value);

        // Apply ignore ID
        if ($this->ignoreId !== null) {
            $ignoreValue = $this->resolveValue($this->ignoreId);
            if ($ignoreValue !== null) {
                $query->where($this->ignoreIdColumn, '!=', $ignoreValue);
            }
        }

        // Apply where conditions
        foreach ($this->whereConditions as [$col, $operator, $val]) {
            $resolvedValue = $this->resolveValue($val);
            $query->where($col, $operator, $resolvedValue);
        }

        // Apply where null
        foreach ($this->whereNullConditions as $col) {
            $query->whereNull($col);
        }

        // Apply where not null
        foreach ($this->whereNotNullConditions as $col) {
            $query->whereNotNull($col);
        }

        return !$query->exists();
    }

    /**
     * Validate using PDO.
     *
     * @param object $db PDO connection
     * @param mixed $value Value to check
     * @param string $column Column name
     * @return bool
     */
    private function validateWithPdo(object $db, mixed $value, string $column): bool
    {
        $sql = "SELECT COUNT(*) FROM {$this->table} WHERE {$column} = ?";
        $params = [$value];

        // Apply ignore ID
        if ($this->ignoreId !== null) {
            $ignoreValue = $this->resolveValue($this->ignoreId);
            if ($ignoreValue !== null) {
                $sql .= " AND {$this->ignoreIdColumn} != ?";
                $params[] = $ignoreValue;
            }
        }

        // Apply where conditions
        foreach ($this->whereConditions as [$col, $operator, $val]) {
            $resolvedValue = $this->resolveValue($val);
            $sql .= " AND {$col} {$operator} ?";
            $params[] = $resolvedValue;
        }

        // Apply where null
        foreach ($this->whereNullConditions as $col) {
            $sql .= " AND {$col} IS NULL";
        }

        // Apply where not null
        foreach ($this->whereNotNullConditions as $col) {
            $sql .= " AND {$col} IS NOT NULL";
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() === 0;
    }

    /**
     * Validate array of values.
     *
     * @param array<mixed> $values Array of values
     * @param string $column Column name
     * @return bool
     */
    private function validateArray(array $values, string $column): bool
    {
        // Check if all values are unique in the array itself
        $uniqueValues = array_unique($values);
        if (count($uniqueValues) !== count($values)) {
            return false;
        }

        // Check each value against database
        foreach ($values as $singleValue) {
            if (!$this->validateValue($singleValue, $column)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve value (supports dynamic values from validation data).
     *
     * @param mixed $value Value (can be a field reference)
     * @return mixed
     */
    private function resolveValue(mixed $value): mixed
    {
        // If value is a string starting with ':' treat as field reference
        if (is_string($value) && str_starts_with($value, ':')) {
            $fieldName = substr($value, 1);
            if ($this->data !== null && $this->data->has($fieldName)) {
                return $this->data->get($fieldName);
            }
        }

        return $value;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message(): string
    {
        return "The :attribute has already been taken.";
    }

    /**
     * Get database connection.
     *
     * @return object
     * @throws \RuntimeException If database not available
     */
    private function getConnection(): object
    {
        if (class_exists(Container::class)) {
            $container = Container::getInstance();
            if ($container->has(ConnectionInterface::class)) {
                return $container->get(ConnectionInterface::class);
            }
            if ($container->has('db')) {
                return $container->get('db');
            }
        }

        // Try global function
        if (function_exists('db')) {
            return db();
        }

        throw new \RuntimeException('Database connection not available');
    }
}
