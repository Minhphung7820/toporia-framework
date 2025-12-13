<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation;

use Toporia\Framework\Validation\Contracts\ValidatorInterface;
use Toporia\Framework\Validation\Contracts\RuleInterface;
use Toporia\Framework\Validation\Contracts\ImplicitRuleInterface;
use Toporia\Framework\Validation\Contracts\DataAwareRuleInterface;

/**
 * Class Validator
 *
 * Powerful validation engine with comprehensive rule support.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Validation
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class Validator implements ValidatorInterface
{
    /**
     * @var array<string, array<string>> Validation errors
     */
    private array $errors = [];

    /**
     * @var array Validated data
     */
    private array $validatedData = [];

    /**
     * @var bool Validation status
     */
    private bool $passes = false;

    /**
     * @var array<string, callable> Custom validation rules
     */
    private static array $customRules = [];

    /**
     * @var array<string, string> Custom rule messages
     */
    private static array $customMessages = [];

    /**
     * @var object|null Database connection for unique/exists rules
     */
    private static ?object $connection = null;

    /**
     * @var callable|null Connection resolver callback
     */
    private static $connectionResolver = null;

    /**
     * {@inheritdoc}
     */
    public function validate(array $data, array $rules, array $messages = []): bool
    {
        $this->errors = [];
        $this->validatedData = [];
        $this->passes = true;

        // Create ValidationData for data-aware rules
        $validationData = ValidationData::fromArray($data);

        foreach ($rules as $field => $fieldRules) {
            $value = $this->getValue($data, $field);
            $ruleList = is_string($fieldRules) ? explode('|', $fieldRules) : $fieldRules;

            // Check if this is an array field with wildcard/index notation
            if (ArrayValidator::isArrayField($field) && is_array($value)) {
                // Handle array validation with nested rules
                $arrayErrors = ArrayValidator::validateArrayField(
                    $field,
                    $value,
                    $ruleList,
                    $data,
                    $messages,
                    $this
                );
                $this->errors = array_merge($this->errors, $arrayErrors);
                continue;
            }

            // Separate implicit rules (run even when empty) from regular rules
            $implicitRules = [];
            $regularRules = [];
            $stringRules = [];

            foreach ($ruleList as $rule) {
                $resolvedRule = $this->resolveRule($rule);

                // Handle Rule objects
                if ($resolvedRule instanceof RuleInterface) {
                    // Set data for data-aware rules
                    if ($resolvedRule instanceof DataAwareRuleInterface) {
                        $resolvedRule->setData($validationData);
                    }

                    if ($resolvedRule instanceof ImplicitRuleInterface) {
                        $implicitRules[] = $resolvedRule;
                    } else {
                        $regularRules[] = $resolvedRule;
                    }
                } else {
                    // String rule (built-in or callable) - handle via old method
                    $stringRules[] = $resolvedRule;
                }
            }

            // Run implicit rules first (fail-fast optimization)
            foreach ($implicitRules as $rule) {
                $this->validateRuleObject($field, $value, $rule, $validationData, $messages);
            }

            // Run string rules (backward compatibility)
            foreach ($stringRules as $rule) {
                $this->validateRule($field, $value, $rule, $data, $messages);
            }

            // Skip regular rules if value is empty (unless implicit rule failed)
            if (($value === null || $value === '') && !isset($this->errors[$field])) {
                // Still need to check if any string rules require the field
                continue;
            }

            // Run regular rules
            foreach ($regularRules as $rule) {
                $this->validateRuleObject($field, $value, $rule, $validationData, $messages);
            }

            // Store validated data (only if field passed validation)
            if (!isset($this->errors[$field])) {
                $this->validatedData[$field] = $value;
            }
        }

        $this->passes = empty($this->errors);

        return $this->passes;
    }

    /**
     * {@inheritdoc}
     */
    public function fails(): bool
    {
        return !$this->passes;
    }

    /**
     * {@inheritdoc}
     */
    public function passes(): bool
    {
        return $this->passes;
    }

    /**
     * {@inheritdoc}
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * {@inheritdoc}
     */
    public function validated(): array
    {
        return $this->validatedData;
    }

    /**
     * {@inheritdoc}
     */
    public function extend(string $name, callable $callback, ?string $message = null): void
    {
        self::$customRules[$name] = $callback;

        if ($message !== null) {
            self::$customMessages[$name] = $message;
        }
    }

    /**
     * Set database connection for unique/exists validation.
     *
     * @param object|string $connection Database connection (PDO/QueryBuilder) or connection name
     * @return void
     *
     * @example
     * ```php
     * // Option 1: Pass connection object directly
     * Validator::setConnection($connection);
     *
     * // Option 2: Pass connection name (resolves from DatabaseManager)
     * Validator::setConnection('mysql');     // Use mysql connection
     * Validator::setConnection('pgsql');     // Use postgres connection
     * Validator::setConnection('default');   // Use default connection
     * ```
     */
    public static function setConnection(object|string $connection): void
    {
        if (is_string($connection)) {
            // Connection name - will be resolved lazily
            self::$connectionResolver = function () use ($connection) {
                // Try to get DatabaseManager from container
                if (function_exists('app') && app()->has('db.manager')) {
                    return app('db.manager')->connection($connection);
                }

                // Fallback: Try to get connection directly from container
                if (function_exists('app') && app()->has("db.{$connection}")) {
                    return app("db.{$connection}");
                }

                throw new \RuntimeException("Database connection '{$connection}' not found in container.");
            };
        } else {
            // Direct object
            self::$connection = $connection;
        }
    }

    /**
     * Set database connection resolver callback.
     *
     * The resolver will be called lazily when database connection is needed.
     * This allows auto-resolving from container without manual setup.
     *
     * Example:
     * ```php
     * Validator::setConnectionResolver(fn() => app('db'));
     * Validator::setConnectionResolver(fn() => app('db.manager')->connection('mysql'));
     * ```
     *
     * @param callable $resolver Callback that returns database connection
     * @return void
     */
    public static function setConnectionResolver(callable $resolver): void
    {
        self::$connectionResolver = $resolver;
    }

    /**
     * Get database connection (lazy loading).
     *
     * Tries in order:
     * 1. Static $connection if already set
     * 2. Resolver callback if configured
     * 3. Auto-resolve from global app() container
     * 4. Throw exception if none available
     *
     * Performance: O(1) after first call (cached in static $connection)
     *
     * @return object Database connection (PDO or QueryBuilder)
     * @throws \RuntimeException If no database available
     */
    private static function getConnection(): object
    {
        // Already set - return immediately (O(1))
        if (self::$connection !== null) {
            return self::$connection;
        }

        // Try resolver callback
        if (self::$connectionResolver !== null) {
            self::$connection = (self::$connectionResolver)();
            return self::$connection;
        }

        // Try auto-resolve from container
        if (function_exists('app')) {
            try {
                self::$connection = app('db');
                return self::$connection;
            } catch (\Throwable $e) {
                // Container doesn't have 'db' - continue to error
            }
        }

        throw new \RuntimeException(
            'Database connection not available. ' .
                'Please call Validator::setConnection($db) or Validator::setConnectionResolver(fn() => app(\'db\')) first.'
        );
    }

    /**
     * Set database connection (deprecated - use setConnection).
     *
     * @deprecated Use setConnection() instead
     * @param object|string $db Database connection or name
     * @return void
     */
    public static function setDatabase(object|string $db): void
    {
        self::setConnection($db);
    }

    /**
     * Set database resolver (deprecated - use setConnectionResolver).
     *
     * @deprecated Use setConnectionResolver() instead
     * @param callable $resolver Callback
     * @return void
     */
    public static function setDatabaseResolver(callable $resolver): void
    {
        self::setConnectionResolver($resolver);
    }

    /**
     * Resolve rule from string or object.
     *
     * @param string|RuleInterface $rule Rule string or Rule object
     * @return RuleInterface|string Rule object or string for built-in rules
     */
    private function resolveRule(string|RuleInterface $rule): RuleInterface|string
    {
        // Already a Rule object
        if ($rule instanceof RuleInterface) {
            return $rule;
        }

        // Check if it's a custom rule (callable)
        [$ruleName] = $this->parseRule($rule);
        if (isset(self::$customRules[$ruleName])) {
            // Return string for backward compatibility with callable rules
            return $rule;
        }

        // Try to resolve via RuleManager
        try {
            return RuleManager::resolve($rule);
        } catch (\Throwable $e) {
            // Fall back to built-in rule (string)
            return $rule;
        }
    }

    /**
     * Validate using Rule object.
     *
     * @param string $field Field name
     * @param mixed $value Field value
     * @param RuleInterface $rule Rule object
     * @param ValidationData $data All validation data
     * @param array $messages Custom messages
     * @return void
     */
    private function validateRuleObject(
        string $field,
        mixed $value,
        RuleInterface $rule,
        ValidationData $data,
        array $messages
    ): void {
        $attribute = ValidationAttribute::fromName($field);
        $passes = $rule->passes($attribute->getName(), $value);

        if (!$passes) {
            $ruleClass = get_class($rule);
            $message = $messages["{$field}.{$ruleClass}"]
                ?? $messages[$field]
                ?? $this->replaceAttributePlaceholder($rule->message(), $attribute->getDisplayName());

            $this->errors[$field][] = $message;
        }
    }

    /**
     * Validate a single rule (backward compatibility with string rules).
     *
     * @param string $field Field name
     * @param mixed $value Field value
     * @param string $rule Rule name with optional parameters
     * @param array $data All data (for dependent rules)
     * @param array $messages Custom messages
     * @return void
     */
    private function validateRule(string $field, mixed $value, string $rule, array $data, array $messages): void
    {
        // Parse rule and parameters (e.g., "max:255" => ['max', '255'])
        [$ruleName, $parameters] = $this->parseRule($rule);

        // Check if custom rule exists first (Open/Closed Principle)
        if (isset(self::$customRules[$ruleName])) {
            $passes = self::$customRules[$ruleName]($value, $parameters, $data);

            if (!$passes) {
                $this->addError($field, $ruleName, $parameters, $messages);
            }

            return;
        }

        // Check if built-in rule method exists
        $method = 'validate' . str_replace('_', '', ucwords($ruleName, '_'));

        if (!method_exists($this, $method)) {
            throw new \InvalidArgumentException("Validation rule '{$ruleName}' does not exist");
        }

        // Execute validation
        $passes = $this->{$method}($value, $parameters, $data);

        if (!$passes) {
            $this->addError($field, $ruleName, $parameters, $messages);
        }
    }

    /**
     * Replace :attribute placeholder in message.
     *
     * @param string $message Error message
     * @param string $attribute Attribute display name
     * @return string
     */
    private function replaceAttributePlaceholder(string $message, string $attribute): string
    {
        return str_replace(':attribute', $attribute, $message);
    }

    /**
     * Parse rule string into name and parameters.
     *
     * @param string $rule Rule string (e.g., "max:255")
     * @return array [ruleName, parameters]
     */
    private function parseRule(string $rule): array
    {
        if (!str_contains($rule, ':')) {
            return [$rule, []];
        }

        [$ruleName, $params] = explode(':', $rule, 2);
        return [$ruleName, explode(',', $params)];
    }

    /**
     * Get value from data using dot notation.
     *
     * @param array $data Data array
     * @param string $key Key (supports dot notation)
     * @return mixed
     */
    private function getValue(array $data, string $key): mixed
    {
        if (isset($data[$key])) {
            return $data[$key];
        }

        // Support dot notation (e.g., "user.email")
        $segments = explode('.', $key);
        $value = $data;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Add validation error.
     *
     * @param string $field Field name
     * @param string $rule Rule name
     * @param array $parameters Rule parameters
     * @param array $customMessages Custom messages
     * @return void
     */
    private function addError(string $field, string $rule, array $parameters, array $customMessages): void
    {
        $message = $customMessages["{$field}.{$rule}"]
            ?? $customMessages[$rule]
            ?? $this->getDefaultMessage($field, $rule, $parameters);

        $this->errors[$field][] = $message;
    }

    /**
     * Get default error message for a rule.
     *
     * @param string $field Field name
     * @param string $rule Rule name
     * @param array $parameters Rule parameters
     * @return string
     */
    private function getDefaultMessage(string $field, string $rule, array $parameters): string
    {
        $fieldName = str_replace('_', ' ', $field);

        // Check custom rule messages first
        if (isset(self::$customMessages[$rule])) {
            return str_replace(':field', $fieldName, self::$customMessages[$rule]);
        }

        return match ($rule) {
            'required' => "The {$fieldName} field is required.",
            'email' => "The {$fieldName} must be a valid email address.",
            'min' => "The {$fieldName} must be at least {$parameters[0]} characters.",
            'max' => "The {$fieldName} must not exceed {$parameters[0]} characters.",
            'numeric' => "The {$fieldName} must be a number.",
            'integer' => "The {$fieldName} must be an integer.",
            'string' => "The {$fieldName} must be a string.",
            'array' => "The {$fieldName} must be an array.",
            'distinct' => "The {$fieldName} must have unique values.",
            'array_distinct' => "The {$fieldName} must have unique values.",
            'boolean' => "The {$fieldName} must be true or false.",
            'url' => "The {$fieldName} must be a valid URL.",
            'ip' => "The {$fieldName} must be a valid IP address.",
            'alpha' => "The {$fieldName} may only contain letters.",
            'alpha_num' => "The {$fieldName} may only contain letters and numbers.",
            'alpha_dash' => "The {$fieldName} may only contain letters, numbers, dashes and underscores.",
            'in' => "The selected {$fieldName} is invalid.",
            'not_in' => "The selected {$fieldName} is invalid.",
            'same' => "The {$fieldName} and {$parameters[0]} must match.",
            'different' => "The {$fieldName} and {$parameters[0]} must be different.",
            'confirmed' => "The {$fieldName} confirmation does not match.",
            'unique' => "The {$fieldName} has already been taken.",
            'exists' => "The selected {$fieldName} is invalid.",
            'date' => "The {$fieldName} must be a valid date.",
            'after' => "The {$fieldName} must be a date after {$parameters[0]}.",
            'before' => "The {$fieldName} must be a date before {$parameters[0]}.",
            'between' => "The {$fieldName} must be between {$parameters[0]} and {$parameters[1]}.",
            'date_valid' => "The {$fieldName} must be a valid date.",
            'time' => "The {$fieldName} must be a valid time.",
            'date_time' => "The {$fieldName} must be a valid date and time.",
            'json' => "The {$fieldName} must be a valid JSON string.",
            'uuid' => "The {$fieldName} must be a valid UUID.",
            'mac_address' => "The {$fieldName} must be a valid MAC address.",
            'credit_card' => "The {$fieldName} must be a valid credit card number.",
            'base64' => "The {$fieldName} must be a valid base64 string.",
            'file' => "The {$fieldName} must be a valid file.",
            'image' => "The {$fieldName} must be an image.",
            'mime_type' => "The {$fieldName} must be a file of type: " . implode(', ', $parameters) . ".",
            'extension' => "The {$fieldName} must have one of the following extensions: " . implode(', ', $parameters) . ".",
            'size' => "The {$fieldName} must be {$parameters[0]}.",
            'phone' => "The {$fieldName} must be a valid phone number.",
            'postal_code' => "The {$fieldName} must be a valid postal code.",
            'color' => "The {$fieldName} must be a valid color.",
            'present' => "The {$fieldName} field must be present.",
            'filled' => "The {$fieldName} field must have a value.",
            'nullable' => "The {$fieldName} field may be null.",
            'sometimes' => "The {$fieldName} field is optional.",
            'required_if' => "The {$fieldName} field is required when {$parameters[0]} is {$parameters[1]}.",
            'required_unless' => "The {$fieldName} field is required unless {$parameters[0]} is {$parameters[1]}.",
            'required_with' => "The {$fieldName} field is required when {$parameters[0]} is present.",
            'required_without' => "The {$fieldName} field is required when {$parameters[0]} is not present.",
            default => "The {$fieldName} is invalid."
        };
    }

    // =========================================================================
    // Validation Rules
    // =========================================================================

    /**
     * Validate required field.
     */
    private function validateRequired(mixed $value, array $parameters, array $data): bool
    {
        if (is_null($value)) {
            return false;
        }

        if (is_string($value) && trim($value) === '') {
            return false;
        }

        if (is_array($value) && count($value) === 0) {
            return false;
        }

        return true;
    }

    /**
     * Validate email address.
     */
    private function validateEmail(mixed $value, array $parameters, array $data): bool
    {
        if (is_null($value) || $value === '') {
            return true; // Not required by default
        }

        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Validate minimum length/value.
     */
    private function validateMin(mixed $value, array $parameters, array $data): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        $min = (int) $parameters[0];

        if (is_numeric($value)) {
            return $value >= $min;
        }

        if (is_string($value)) {
            return mb_strlen($value) >= $min;
        }

        if (is_array($value)) {
            return count($value) >= $min;
        }

        return false;
    }

    /**
     * Validate maximum length/value.
     */
    private function validateMax(mixed $value, array $parameters, array $data): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        $max = (int) $parameters[0];

        if (is_numeric($value)) {
            return $value <= $max;
        }

        if (is_string($value)) {
            return mb_strlen($value) <= $max;
        }

        if (is_array($value)) {
            return count($value) <= $max;
        }

        return false;
    }

    /**
     * Validate numeric value.
     */
    private function validateNumeric(mixed $value, array $parameters, array $data): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        return is_numeric($value);
    }

    /**
     * Validate integer value.
     */
    private function validateInteger(mixed $value, array $parameters, array $data): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        return filter_var($value, FILTER_VALIDATE_INT) !== false;
    }

    /**
     * Validate string value.
     */
    private function validateString(mixed $value, array $parameters, array $data): bool
    {
        if (is_null($value)) {
            return true;
        }

        return is_string($value);
    }

    /**
     * Validate array value.
     */
    private function validateArray(mixed $value, array $parameters, array $data): bool
    {
        if (is_null($value)) {
            return true;
        }

        if (!is_array($value)) {
            return false;
        }

        // If parameters provided, validate array structure
        // array:min,max - validate array size
        if (!empty($parameters)) {
            $min = isset($parameters[0]) ? (int) $parameters[0] : null;
            $max = isset($parameters[1]) ? (int) $parameters[1] : null;
            $count = count($value);

            if ($min !== null && $count < $min) {
                return false;
            }

            if ($max !== null && $count > $max) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate boolean value.
     */
    private function validateBoolean(mixed $value, array $parameters, array $data): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        return in_array($value, [true, false, 0, 1, '0', '1'], true);
    }

    /**
     * Validate URL.
     */
    private function validateUrl(mixed $value, array $parameters, array $data): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Validate IP address.
     */
    private function validateIp(mixed $value, array $parameters, array $data): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        return filter_var($value, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Validate alpha (letters only).
     */
    private function validateAlpha(mixed $value, array $parameters, array $data): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        return preg_match('/^[a-zA-Z]+$/', $value) === 1;
    }

    /**
     * Validate alpha-numeric.
     */
    private function validateAlphaNum(mixed $value, array $parameters, array $data): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        return preg_match('/^[a-zA-Z0-9]+$/', $value) === 1;
    }

    /**
     * Validate alpha-dash (letters, numbers, dashes, underscores).
     */
    private function validateAlphaDash(mixed $value, array $parameters, array $data): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        return preg_match('/^[a-zA-Z0-9_-]+$/', $value) === 1;
    }

    /**
     * Validate value is in list.
     */
    private function validateIn(mixed $value, array $parameters, array $data): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        return in_array($value, $parameters, true);
    }

    /**
     * Validate value is not in list.
     */
    private function validateNotIn(mixed $value, array $parameters, array $data): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        return !in_array($value, $parameters, true);
    }

    /**
     * Validate field matches another field.
     */
    private function validateSame(mixed $value, array $parameters, array $data): bool
    {
        $other = $this->getValue($data, $parameters[0]);
        return $value === $other;
    }

    /**
     * Validate field is different from another field.
     */
    private function validateDifferent(mixed $value, array $parameters, array $data): bool
    {
        $other = $this->getValue($data, $parameters[0]);
        return $value !== $other;
    }

    /**
     * Validate confirmed field (e.g., password_confirmation).
     */
    private function validateConfirmed(mixed $value, array $parameters, array $data): bool
    {
        $field = $parameters[0] ?? null;

        if (!$field) {
            return false;
        }

        $confirmation = $this->getValue($data, "{$field}_confirmation");
        return $value === $confirmation;
    }

    /**
     * Validate regex pattern.
     */
    private function validateRegex(mixed $value, array $parameters, array $data): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        return preg_match($parameters[0], $value) === 1;
    }

    // =========================================================================
    // Database Validation Rules
    // =========================================================================

    /**
     * Validate unique value in database.
     *
     * Usage:
     * - unique:table,column
     * - unique:table,column,ignoreValue,ignoreColumn (single ignore)
     * - unique:table,column,ignoreColumn1:ignoreValue1,ignoreColumn2:ignoreValue2 (multiple ignores)
     *
     * Examples:
     * - 'email' => 'unique:users,email'
     * - 'email' => 'unique:users,email,1,id' (ignore id=1)
     * - 'email' => 'unique:users,email,id:1,status:deleted' (ignore id=1 AND status=deleted)
     * - 'email' => 'unique:users,email,id:1,name:John' (ignore id=1 AND name=John)
     *
     * Performance: O(1) - Single indexed query with prepared statement
     *
     * @param mixed $value Value to validate
     * @param array $parameters [table, column, ...ignoreConditions]
     * @param array $data All form data
     * @return bool
     * @throws \RuntimeException If database not available
     * @throws \InvalidArgumentException If parameters invalid
     */
    private function validateUnique(mixed $value, array $parameters, array $data): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        // Handle array values (for array validation)
        if (is_array($value)) {
            return $this->validateUniqueArray($value, $parameters, $data);
        }

        // Lazy load database connection (auto-resolve from container)
        $db = self::getConnection();

        $table = $parameters[0] ?? null;
        $column = $parameters[1] ?? null;

        if (!$table || !$column) {
            throw new \InvalidArgumentException('unique rule requires table and column parameters');
        }

        // Parse ignore conditions
        $ignoreConditions = $this->parseIgnoreConditions(array_slice($parameters, 2), $data);

        // Build query based on database type
        if (method_exists($db, 'table')) {
            // QueryBuilder
            $query = $db->table($table)->where($column, $value);

            // Apply ignore conditions
            foreach ($ignoreConditions as $ignoreColumn => $ignoreValue) {
                $query->where($ignoreColumn, '!=', $ignoreValue);
            }

            return !$query->exists();
        }

        if ($db instanceof \PDO) {
            // PDO
            $sql = "SELECT COUNT(*) FROM {$table} WHERE {$column} = ?";
            $params = [$value];

            foreach ($ignoreConditions as $ignoreColumn => $ignoreValue) {
                $sql .= " AND {$ignoreColumn} != ?";
                $params[] = $ignoreValue;
            }

            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            return (int) $stmt->fetchColumn() === 0;
        }

        throw new \RuntimeException('Database connection must be PDO or QueryBuilder instance');
    }

    /**
     * Parse ignore conditions from parameters.
     *
     * Supports formats:
     * - [value, column] -> single ignore (backward compatible)
     * - ['column:value', 'column2:value2'] -> multiple ignores
     * - ['column', 'value'] -> single ignore (backward compatible)
     *
     * @param array $parameters Ignore condition parameters
     * @param array $data All form data
     * @return array<string, mixed> ['column' => 'value', ...]
     */
    private function parseIgnoreConditions(array $parameters, array $data): array
    {
        if (empty($parameters)) {
            return [];
        }

        $conditions = [];

        // Check if first parameter contains ':' (new format: column:value)
        if (count($parameters) === 1 && str_contains($parameters[0], ':')) {
            // Multiple ignores in format: "column1:value1,column2:value2"
            $pairs = explode(',', $parameters[0]);
            foreach ($pairs as $pair) {
                if (str_contains($pair, ':')) {
                    [$ignoreColumn, $ignoreValue] = explode(':', $pair, 2);
                    $conditions[trim($ignoreColumn)] = $this->resolveIgnoreValue(trim($ignoreValue), $data);
                }
            }
        } elseif (count($parameters) >= 2) {
            // Backward compatible: [value, column] or [column, value]
            // Try to detect format
            if (is_numeric($parameters[0]) || !is_numeric($parameters[1])) {
                // Format: [value, column]
                $conditions[$parameters[1] ?? 'id'] = $this->resolveIgnoreValue($parameters[0], $data);
            } else {
                // Format: [column, value] or multiple [column:value, column2:value2]
                foreach ($parameters as $param) {
                    if (str_contains($param, ':')) {
                        [$ignoreColumn, $ignoreValue] = explode(':', $param, 2);
                        $conditions[trim($ignoreColumn)] = $this->resolveIgnoreValue(trim($ignoreValue), $data);
                    }
                }
            }
        }

        return $conditions;
    }

    /**
     * Resolve ignore value (supports field references from data).
     *
     * @param mixed $value Ignore value (can be field name or actual value)
     * @param array $data All form data
     * @return mixed
     */
    private function resolveIgnoreValue(mixed $value, array $data): mixed
    {
        // If value is a string and exists in data, use it (field reference)
        if (is_string($value) && isset($data[$value])) {
            return $data[$value];
        }

        return $value;
    }

    /**
     * Validate array of unique values.
     *
     * @param array<mixed> $values Array of values
     * @param array $parameters Rule parameters
     * @param array $data All form data
     * @return bool
     */
    private function validateUniqueArray(array $values, array $parameters, array $data): bool
    {
        // First check if all values are unique in the array itself
        $uniqueValues = array_unique($values);
        if (count($uniqueValues) !== count($values)) {
            return false;
        }

        // Then check each value against database
        foreach ($values as $value) {
            if (!$this->validateUnique($value, $parameters, $data)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate value exists in database.
     *
     * Usage:
     * - exists:table,column
     * - exists:table,column,column1:value1,column2:value2 (with additional conditions)
     *
     * Examples:
     * - 'category_id' => 'exists:categories,id'
     * - 'category_id' => 'exists:categories,id,status:active' (must exist AND status=active)
     * - 'user_id' => 'exists:users,id,status:active,deleted_at:null' (must exist AND status=active AND deleted_at IS NULL)
     *
     * Performance: O(1) - Single indexed query with prepared statement
     *
     * @param mixed $value Value to validate
     * @param array $parameters [table, column, ...conditions]
     * @param array $data All form data
     * @return bool
     * @throws \RuntimeException If database not available
     * @throws \InvalidArgumentException If parameters invalid
     */
    private function validateExists(mixed $value, array $parameters, array $data): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        // Handle array values (for array validation)
        if (is_array($value)) {
            return $this->validateExistsArray($value, $parameters, $data);
        }

        // Lazy load database connection (auto-resolve from container)
        $db = self::getConnection();

        $table = $parameters[0] ?? null;
        $column = $parameters[1] ?? 'id';

        if (!$table) {
            throw new \InvalidArgumentException('exists rule requires table parameter');
        }

        // Parse additional conditions
        $conditions = $this->parseExistsConditions(array_slice($parameters, 2), $data);

        // Build query based on database type
        if (method_exists($db, 'table')) {
            // QueryBuilder
            $query = $db->table($table)->where($column, $value);

            // Apply additional conditions
            foreach ($conditions as $conditionColumn => $conditionValue) {
                if ($conditionValue === null || $conditionValue === 'null') {
                    $query->whereNull($conditionColumn);
                } else {
                    $query->where($conditionColumn, $conditionValue);
                }
            }

            return $query->exists();
        }

        if ($db instanceof \PDO) {
            // PDO
            $sql = "SELECT COUNT(*) FROM {$table} WHERE {$column} = ?";
            $params = [$value];

            foreach ($conditions as $conditionColumn => $conditionValue) {
                if ($conditionValue === null || $conditionValue === 'null') {
                    $sql .= " AND {$conditionColumn} IS NULL";
                } else {
                    $sql .= " AND {$conditionColumn} = ?";
                    $params[] = $conditionValue;
                }
            }

            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            return (int) $stmt->fetchColumn() > 0;
        }

        throw new \RuntimeException('Database connection must be PDO or QueryBuilder instance');
    }

    /**
     * Parse additional conditions for exists rule.
     *
     * Supports format: ['column:value', 'column2:value2']
     *
     * @param array $parameters Condition parameters
     * @param array $data All form data
     * @return array<string, mixed> ['column' => 'value', ...]
     */
    private function parseExistsConditions(array $parameters, array $data): array
    {
        if (empty($parameters)) {
            return [];
        }

        $conditions = [];

        foreach ($parameters as $param) {
            if (str_contains($param, ':')) {
                [$column, $value] = explode(':', $param, 2);
                $column = trim($column);
                $value = trim($value);

                // Resolve value (can be field reference or actual value)
                $actualValue = $this->resolveIgnoreValue($value, $data);

                // Handle null values
                if ($actualValue === 'null' || $actualValue === null) {
                    $conditions[$column] = null;
                } else {
                    $conditions[$column] = $actualValue;
                }
            }
        }

        return $conditions;
    }

    /**
     * Validate array of exists values.
     *
     * @param array<mixed> $values Array of values
     * @param array $parameters Rule parameters
     * @param array $data All form data
     * @return bool
     */
    private function validateExistsArray(array $values, array $parameters, array $data): bool
    {
        // Check each value against database
        foreach ($values as $value) {
            if (!$this->validateExists($value, $parameters, $data)) {
                return false;
            }
        }

        return true;
    }

    // =========================================================================
    // Additional Validation Rules (Modern ORM)
    // =========================================================================

    /**
     * Validate date format.
     */
    private function validateDate(mixed $value, array $parameters, array $data): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        $format = $parameters[0] ?? 'Y-m-d';
        $date = \DateTime::createFromFormat($format, $value);
        return $date !== false && $date->format($format) === $value;
    }

    /**
     * Validate date is after another date.
     */
    private function validateAfter(mixed $value, array $parameters, array $data): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        $afterDate = $parameters[0] ?? null;
        if ($afterDate === null) {
            return false;
        }

        // Try to get from data array if it's a field name
        if (isset($data[$afterDate])) {
            $afterDate = $data[$afterDate];
        }

        try {
            $valueTime = \Toporia\Framework\DateTime\Chronos::parse($value)->getTimestamp();
            $afterTime = \Toporia\Framework\DateTime\Chronos::parse($afterDate)->getTimestamp();
            return $valueTime > $afterTime;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Validate date is before another date.
     */
    private function validateBefore(mixed $value, array $parameters, array $data): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        $beforeDate = $parameters[0] ?? null;
        if ($beforeDate === null) {
            return false;
        }

        if (isset($data[$beforeDate])) {
            $beforeDate = $data[$beforeDate];
        }

        try {
            $valueTime = \Toporia\Framework\DateTime\Chronos::parse($value)->getTimestamp();
            $beforeTime = \Toporia\Framework\DateTime\Chronos::parse($beforeDate)->getTimestamp();
            return $valueTime < $beforeTime;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Validate value is between min and max.
     */
    private function validateBetween(mixed $value, array $parameters, array $data): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        if (count($parameters) < 2) {
            return false;
        }

        $min = (int) $parameters[0];
        $max = (int) $parameters[1];

        if (is_numeric($value)) {
            return $value >= $min && $value <= $max;
        }

        if (is_string($value)) {
            $length = mb_strlen($value);
            return $length >= $min && $length <= $max;
        }

        if (is_array($value)) {
            $count = count($value);
            return $count >= $min && $count <= $max;
        }

        return false;
    }

    /**
     * Validate value is a valid date.
     */
    private function validateDateValid(mixed $value, array $parameters, array $data): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        try {
            \Toporia\Framework\DateTime\Chronos::parse($value);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Validate value is a valid time.
     */
    private function validateTime(mixed $value, array $parameters, array $data): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        return preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/', $value) === 1;
    }

    /**
     * Validate value is a valid date-time.
     */
    private function validateDateTime(mixed $value, array $parameters, array $data): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        try {
            \Toporia\Framework\DateTime\Chronos::parse($value);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Validate value is a valid JSON string.
     */
    private function validateJson(mixed $value, array $parameters, array $data): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        if (!is_string($value)) {
            return false;
        }

        json_decode($value);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Validate value is a valid UUID.
     */
    private function validateUuid(mixed $value, array $parameters, array $data): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
        return preg_match($pattern, $value) === 1;
    }

    /**
     * Validate value is a valid MAC address.
     */
    private function validateMacAddress(mixed $value, array $parameters, array $data): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        return filter_var($value, FILTER_VALIDATE_MAC) !== false;
    }

    /**
     * Validate value is a valid credit card number (Luhn algorithm).
     */
    private function validateCreditCard(mixed $value, array $parameters, array $data): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        $value = preg_replace('/\D/', '', $value);
        if (strlen($value) < 13 || strlen($value) > 19) {
            return false;
        }

        // Luhn algorithm
        $sum = 0;
        $alternate = false;
        for ($i = strlen($value) - 1; $i >= 0; $i--) {
            $n = (int) $value[$i];
            if ($alternate) {
                $n *= 2;
                if ($n > 9) {
                    $n = ($n % 10) + 1;
                }
            }
            $sum += $n;
            $alternate = !$alternate;
        }

        return $sum % 10 === 0;
    }

    /**
     * Validate value is a valid base64 string.
     */
    private function validateBase64(mixed $value, array $parameters, array $data): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        if (!is_string($value)) {
            return false;
        }

        $decoded = base64_decode($value, true);
        return $decoded !== false && base64_encode($decoded) === $value;
    }

    /**
     * Validate value is a valid file (for file uploads).
     */
    private function validateFile(mixed $value, array $parameters, array $data): bool
    {
        if (is_null($value)) {
            return true;
        }

        return is_uploaded_file($value) || (is_object($value) && method_exists($value, 'isValid') && $value->isValid());
    }

    /**
     * Validate value is a valid image file.
     */
    private function validateImage(mixed $value, array $parameters, array $data): bool
    {
        if (is_null($value)) {
            return true;
        }

        if (is_string($value) && file_exists($value)) {
            $imageInfo = @getimagesize($value);
            return $imageInfo !== false;
        }

        if (is_object($value) && method_exists($value, 'getMimeType')) {
            $mimeType = $value->getMimeType();
            return str_starts_with($mimeType, 'image/');
        }

        return false;
    }

    /**
     * Validate value matches a specific MIME type.
     */
    private function validateMimeType(mixed $value, array $parameters, array $data): bool
    {
        if (is_null($value)) {
            return true;
        }

        if (empty($parameters)) {
            return false;
        }

        $mimeType = null;
        if (is_string($value) && file_exists($value)) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $value);
            finfo_close($finfo);
        } elseif (is_object($value) && method_exists($value, 'getMimeType')) {
            $mimeType = $value->getMimeType();
        }

        if ($mimeType === null) {
            return false;
        }

        return in_array($mimeType, $parameters, true);
    }

    /**
     * Validate value is a valid file extension.
     */
    private function validateExtension(mixed $value, array $parameters, array $data): bool
    {
        if (is_null($value)) {
            return true;
        }

        if (empty($parameters)) {
            return false;
        }

        $extension = null;
        if (is_string($value)) {
            $extension = strtolower(pathinfo($value, PATHINFO_EXTENSION));
        } elseif (is_object($value) && method_exists($value, 'getClientOriginalExtension')) {
            $extension = strtolower($value->getClientOriginalExtension());
        }

        if ($extension === null) {
            return false;
        }

        return in_array($extension, array_map('strtolower', $parameters), true);
    }

    /**
     * Validate value size (for files, strings, arrays, numbers).
     */
    private function validateSize(mixed $value, array $parameters, array $data): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        if (empty($parameters)) {
            return false;
        }

        $size = (int) $parameters[0];

        if (is_numeric($value)) {
            return $value == $size;
        }

        if (is_string($value)) {
            return mb_strlen($value) == $size;
        }

        if (is_array($value)) {
            return count($value) == $size;
        }

        if (is_object($value) && method_exists($value, 'getSize')) {
            return $value->getSize() == $size;
        }

        return false;
    }

    /**
     * Validate value is a valid phone number (basic validation).
     */
    private function validatePhone(mixed $value, array $parameters, array $data): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        // Basic phone validation - allows digits, spaces, dashes, parentheses, plus
        return preg_match('/^[\d\s\-\+\(\)]+$/', $value) === 1 && strlen(preg_replace('/\D/', '', $value)) >= 10;
    }

    /**
     * Validate value is a valid postal code (basic validation).
     */
    private function validatePostalCode(mixed $value, array $parameters, array $data): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        // Basic postal code validation - alphanumeric, 4-10 characters
        return preg_match('/^[A-Z0-9\s\-]{4,10}$/i', $value) === 1;
    }

    /**
     * Validate value is a valid color (hex or rgb).
     */
    private function validateColor(mixed $value, array $parameters, array $data): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        // Hex color
        if (preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $value) === 1) {
            return true;
        }

        // RGB color
        if (preg_match('/^rgb\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*\)$/', $value) === 1) {
            return true;
        }

        return false;
    }

    /**
     * Validate value is present (not null, not empty).
     */
    private function validatePresent(mixed $value, array $parameters, array $data): bool
    {
        return array_key_exists('', $data) || isset($data['']);
    }

    /**
     * Validate value is filled (not empty if present).
     */
    private function validateFilled(mixed $value, array $parameters, array $data): bool
    {
        if (!isset($data[''])) {
            return true; // Field not present, so it's "filled"
        }

        if (is_null($value)) {
            return false;
        }

        if (is_string($value) && trim($value) === '') {
            return false;
        }

        if (is_array($value) && count($value) === 0) {
            return false;
        }

        return true;
    }

    /**
     * Validate value is nullable (always passes if null).
     */
    private function validateNullable(mixed $value, array $parameters, array $data): bool
    {
        // This rule is typically used with other rules to allow null
        // If value is null, validation passes
        return true;
    }

    /**
     * Validate value is sometimes (only validate if present).
     */
    private function validateSometimes(mixed $value, array $parameters, array $data): bool
    {
        // This is a modifier rule - doesn't validate, just marks field as optional
        return true;
    }

    /**
     * Validate value is required if another field has a value.
     */
    private function validateRequiredIf(mixed $value, array $parameters, array $data): bool
    {
        if (count($parameters) < 2) {
            return false;
        }

        $otherField = $parameters[0];
        $otherValue = $parameters[1];

        $otherFieldValue = $this->getValue($data, $otherField);

        if ($otherFieldValue == $otherValue) {
            return $this->validateRequired($value, [], $data);
        }

        return true;
    }

    /**
     * Validate value is required unless another field has a value.
     */
    private function validateRequiredUnless(mixed $value, array $parameters, array $data): bool
    {
        if (count($parameters) < 2) {
            return false;
        }

        $otherField = $parameters[0];
        $otherValue = $parameters[1];

        $otherFieldValue = $this->getValue($data, $otherField);

        if ($otherFieldValue != $otherValue) {
            return $this->validateRequired($value, [], $data);
        }

        return true;
    }

    /**
     * Validate value is required with another field.
     */
    private function validateRequiredWith(mixed $value, array $parameters, array $data): bool
    {
        if (empty($parameters)) {
            return false;
        }

        $otherField = $parameters[0];
        $otherValue = $this->getValue($data, $otherField);

        if ($otherValue !== null) {
            return $this->validateRequired($value, [], $data);
        }

        return true;
    }

    /**
     * Validate value is required without another field.
     */
    private function validateRequiredWithout(mixed $value, array $parameters, array $data): bool
    {
        if (empty($parameters)) {
            return false;
        }

        $otherField = $parameters[0];
        $otherValue = $this->getValue($data, $otherField);

        if ($otherValue === null) {
            return $this->validateRequired($value, [], $data);
        }

        return true;
    }

    /**
     * Validate array has distinct values.
     *
     * Performance: O(n) where n = array size
     *
     * @param mixed $value Value to validate
     * @param array $parameters Rule parameters
     * @param array $data All data
     * @return bool
     */
    private function validateDistinct(mixed $value, array $parameters, array $data): bool
    {
        if (!is_array($value)) {
            return false;
        }

        // Check for duplicates
        $unique = array_unique($value, SORT_REGULAR);
        return count($unique) === count($value);
    }

    /**
     * Validate array distinct (alias for distinct).
     *
     * @param mixed $value Value to validate
     * @param array $parameters Rule parameters
     * @param array $data All data
     * @return bool
     */
    private function validateArrayDistinct(mixed $value, array $parameters, array $data): bool
    {
        return $this->validateDistinct($value, $parameters, $data);
    }
}
