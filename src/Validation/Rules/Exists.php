<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules;

use Toporia\Framework\Container\Container;
use Toporia\Framework\Database\Contracts\ConnectionInterface;
use Toporia\Framework\Validation\Contracts\DataAwareRuleInterface;
use Toporia\Framework\Validation\Contracts\RuleInterface;
use Toporia\Framework\Validation\ValidationAttribute;
use Toporia\Framework\Validation\ValidationData;
use Toporia\Framework\Validation\Validator;

/**
 * Class Exists
 *
 * Validates that a value exists in a database table.
 * Supports multiple additional conditions for complex validation.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Validation\Rules
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class Exists implements RuleInterface, DataAwareRuleInterface
{
    /**
     * Database table name.
     *
     * @var string
     */
    private string $table;

    /**
     * Column name to check.
     *
     * @var string
     */
    private string $column;

    /**
     * Additional conditions: ['column' => 'value', ...]
     *
     * @var array<string, mixed>
     */
    private array $conditions;

    /**
     * Validation data (for accessing other fields).
     *
     * @var ValidationData|null
     */
    private ?ValidationData $data = null;

    /**
     * Create a new exists rule instance.
     *
     * @param string $table Database table name
     * @param string $column Column name to check
     * @param array<string, mixed> $conditions Additional conditions ['column' => 'value', ...]
     */
    public function __construct(string $table, string $column, array $conditions = [])
    {
        $this->table = $table;
        $this->column = $column;
        $this->conditions = $conditions;
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
     * @throws \RuntimeException If database not available
     * @throws \InvalidArgumentException If parameters invalid
     */
    public function passes(string $attribute, mixed $value): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        // Handle array values (for array validation)
        if (is_array($value)) {
            return $this->validateArray($value);
        }

        return $this->validateValue($value);
    }

    /**
     * Validate a single value.
     *
     * @param mixed $value Value to validate
     * @return bool
     */
    private function validateValue(mixed $value): bool
    {
        $db = $this->getConnection();

        if (method_exists($db, 'table')) {
            // QueryBuilder
            $query = $db->table($this->table)->where($this->column, $value);

            // Apply additional conditions
            foreach ($this->conditions as $column => $conditionValue) {
                $actualValue = $this->resolveConditionValue($column, $conditionValue);

                if ($actualValue === null) {
                    $query->whereNull($column);
                } else {
                    $query->where($column, $actualValue);
                }
            }

            return $query->exists();
        }

        if (method_exists($db, 'prepare') && method_exists($db, 'execute')) {
            // PDO
            $sql = "SELECT COUNT(*) FROM {$this->table} WHERE {$this->column} = ?";
            $params = [$value];

            foreach ($this->conditions as $column => $conditionValue) {
                $actualValue = $this->resolveConditionValue($column, $conditionValue);

                if ($actualValue === null) {
                    $sql .= " AND {$column} IS NULL";
                } else {
                    $sql .= " AND {$column} = ?";
                    $params[] = $actualValue;
                }
            }

            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            return (int) $stmt->fetchColumn() > 0;
        }

        throw new \RuntimeException('Database connection must be PDO or QueryBuilder instance');
    }

    /**
     * Validate array of values.
     *
     * @param array<mixed> $values Array of values
     * @return bool
     */
    private function validateArray(array $values): bool
    {
        // Check each value against database
        foreach ($values as $value) {
            if (!$this->validateValue($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve condition value (supports dynamic values from validation data).
     *
     * @param string $column Column name
     * @param mixed $value Condition value (can be a field reference or actual value)
     * @return mixed
     */
    private function resolveConditionValue(string $column, mixed $value): mixed
    {
        // If value is a string and exists in validation data, use it
        if (is_string($value) && $this->data !== null && $this->data->has($value)) {
            return $this->data->get($value);
        }

        return $value;
    }

    /**
     * Get the validation error message.
     *
     * @param ValidationAttribute|null $attribute Attribute metadata
     * @return string
     */
    public function message(?ValidationAttribute $attribute = null): string
    {
        $name = $attribute?->getDisplayName() ?? 'field';
        return "The selected {$name} is invalid.";
    }

    /**
     * Get database connection.
     *
     * @return object
     * @throws \RuntimeException If database not available
     */
    private function getConnection(): object
    {
        // Try to get from container or global
        if (class_exists(Container::class)) {
            $container = Container::getInstance();
            if ($container->has(ConnectionInterface::class)) {
                return $container->get(ConnectionInterface::class);
            }
        }

        // Fallback: use reflection to access Validator's protected static method
        try {
            $method = new \ReflectionMethod(Validator::class, 'getConnection');
            return $method->invoke(null);
        } catch (\ReflectionException $e) {
            throw new \RuntimeException('Database connection not available: ' . $e->getMessage());
        }
    }
}

