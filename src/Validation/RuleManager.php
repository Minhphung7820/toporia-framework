<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation;

use Toporia\Framework\Validation\Contracts\RuleInterface;
use Toporia\Framework\Validation\Contracts\DataAwareRuleInterface;
use Toporia\Framework\Validation\Contracts\ImplicitRuleInterface;

/**
 * Class RuleManager
 *
 * Manages rule registration, resolution, and caching for optimal performance.
 * Handles both built-in Rule classes and custom rules.
 *
 * Performance Optimizations:
 *   - Rule instance caching (singleton pattern for stateless rules)
 *   - Lazy loading of Rule classes
 *   - Efficient rule string parsing
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     2.0.0
 * @package     toporia/framework
 * @subpackage  Validation
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class RuleManager
{
    /**
     * @var array<string, RuleInterface> Cached rule instances (singleton pattern)
     */
    private static array $ruleCache = [];

    /**
     * @var array<string, callable> Custom rule callables
     */
    private static array $customRules = [];

    /**
     * @var array<string, string> Custom rule messages
     */
    private static array $customMessages = [];

    /**
     * Rule name to class mapping for built-in rules.
     * Format: 'rule_name' => 'ClassName'
     *
     * @var array<string, string>
     */
    private const RULE_CLASSES = [
        // Basic Type Rules
        'required' => Rules\Required::class,
        'email' => Rules\Email::class,
        'string' => Rules\Str::class,
        'numeric' => Rules\Numeric::class,
        'integer' => Rules\Integer::class,
        'boolean' => Rules\Boolean::class,
        'array' => Rules\Arr::class,

        // Size/Length Rules
        'min' => Rules\Min::class,
        'max' => Rules\Max::class,
        'between' => Rules\Between::class,
        'size' => Rules\Size::class,
        'digits' => Rules\Digits::class,
        'digits_between' => Rules\DigitsBetween::class,

        // Format Rules
        'url' => Rules\Url::class,
        'active_url' => Rules\ActiveUrl::class,
        'ip' => Rules\Ip::class,
        'alpha' => Rules\Alpha::class,
        'alpha_num' => Rules\AlphaNum::class,
        'alpha_dash' => Rules\AlphaDash::class,
        'regex' => Rules\Regex::class,
        'not_regex' => Rules\NotRegex::class,
        'json' => Rules\Json::class,
        'uuid' => Rules\Uuid::class,
        'ulid' => Rules\Ulid::class,
        'mac_address' => Rules\MacAddress::class,
        'timezone' => Rules\Timezone::class,
        'lowercase' => Rules\Lowercase::class,
        'uppercase' => Rules\Uppercase::class,

        // List Rules
        'in' => Rules\In::class,
        'not_in' => Rules\NotIn::class,

        // Comparison Rules
        'same' => Rules\Same::class,
        'different' => Rules\Different::class,
        'confirmed' => Rules\Confirmed::class,
        'gt' => Rules\Gt::class,
        'gte' => Rules\Gte::class,
        'lt' => Rules\Lt::class,
        'lte' => Rules\Lte::class,

        // Date Rules
        'date' => Rules\Date::class,
        'date_format' => Rules\DateFormat::class,
        'date_equals' => Rules\DateEquals::class,
        'after' => Rules\After::class,
        'after_or_equal' => Rules\AfterOrEqual::class,
        'before' => Rules\Before::class,
        'before_or_equal' => Rules\BeforeOrEqual::class,

        // File Rules
        'file' => Rules\File::class,
        'image' => Rules\Image::class,
        'mimes' => Rules\Mimes::class,
        'mimetypes' => Rules\Mimes::class, // Alias
        'extensions' => Rules\Extensions::class,
        'dimensions' => Rules\Dimensions::class,

        // Conditional/Required Rules
        'required_if' => Rules\RequiredIf::class,
        'required_unless' => Rules\RequiredUnless::class,
        'required_with' => Rules\RequiredWith::class,
        'required_with_all' => Rules\RequiredWithAll::class,
        'required_without' => Rules\RequiredWithout::class,
        'required_without_all' => Rules\RequiredWithoutAll::class,

        // State Rules
        'nullable' => Rules\Nullable::class,
        'present' => Rules\Present::class,
        'filled' => Rules\Filled::class,
        'sometimes' => Rules\Sometimes::class,
        'prohibited' => Rules\Prohibited::class,
        'prohibited_if' => Rules\ProhibitedIf::class,
        'prohibited_unless' => Rules\ProhibitedUnless::class,

        // Acceptance Rules
        'accepted' => Rules\Accepted::class,
        'accepted_if' => Rules\AcceptedIf::class,
        'declined' => Rules\Declined::class,
        'declined_if' => Rules\DeclinedIf::class,

        // String Pattern Rules
        'starts_with' => Rules\StartsWith::class,
        'ends_with' => Rules\EndsWith::class,
        'doesnt_start_with' => Rules\DoesntStartWith::class,
        'doesnt_end_with' => Rules\DoesntEndWith::class,

        // Array Rules
        'distinct' => Rules\Distinct::class,
        'array_min' => Rules\ArrayMin::class,
        'array_max' => Rules\ArrayMax::class,
        'array_distinct' => Rules\ArrayDistinct::class,

        // Database Rules
        'exists' => Rules\Exists::class,
        'unique' => Rules\Unique::class,

        // Password Rule
        'password' => Rules\Password::class,

        // Additional Format Rules
        'time' => Rules\Time::class,
        'date_time' => Rules\DateTime::class,
        'datetime' => Rules\DateTime::class, // Alias
        'credit_card' => Rules\CreditCard::class,
        'base64' => Rules\Base64::class,
        'phone' => Rules\Phone::class,
        'postal_code' => Rules\PostalCode::class,
        'color' => Rules\Color::class,
    ];

    /**
     * Rules that are implicit (run even on empty values).
     *
     * @var array<string>
     */
    private const IMPLICIT_RULES = [
        'required',
        'required_if',
        'required_unless',
        'required_with',
        'required_with_all',
        'required_without',
        'required_without_all',
        'present',
        'filled',
        'accepted',
        'accepted_if',
        'declined',
        'declined_if',
        'prohibited',
        'prohibited_if',
        'prohibited_unless',
    ];

    /**
     * Resolve rule from string or object.
     *
     * Performance: O(1) for cached rules, O(n) for string parsing
     *
     * @param string|RuleInterface $rule Rule string (e.g., "max:255") or Rule object
     * @return RuleInterface
     */
    public static function resolve(string|RuleInterface $rule): RuleInterface
    {
        // Already a Rule object - return directly (O(1))
        if ($rule instanceof RuleInterface) {
            return $rule;
        }

        // Check cache first (O(1))
        $cacheKey = self::getCacheKey($rule);
        if (isset(self::$ruleCache[$cacheKey])) {
            return clone self::$ruleCache[$cacheKey];
        }

        // Parse and resolve rule
        [$ruleName, $parameters] = self::parseRule($rule);

        // Normalize rule name (convert hyphens to underscores)
        $normalizedName = str_replace('-', '_', strtolower($ruleName));

        // Check custom rules first
        if (isset(self::$customRules[$normalizedName])) {
            $resolved = self::resolveCustomRule($normalizedName, $parameters);
            self::$ruleCache[$cacheKey] = $resolved;
            return clone $resolved;
        }

        // Check custom rules with original name
        if (isset(self::$customRules[$ruleName])) {
            $resolved = self::resolveCustomRule($ruleName, $parameters);
            self::$ruleCache[$cacheKey] = $resolved;
            return clone $resolved;
        }

        // Resolve built-in rule class
        $resolved = self::resolveBuiltInRule($normalizedName, $parameters);
        self::$ruleCache[$cacheKey] = $resolved;
        return clone $resolved;
    }

    /**
     * Check if a rule is implicit (runs on empty values).
     *
     * @param string|RuleInterface $rule Rule to check
     * @return bool
     */
    public static function isImplicit(string|RuleInterface $rule): bool
    {
        if ($rule instanceof ImplicitRuleInterface) {
            return true;
        }

        if (is_string($rule)) {
            [$ruleName, ] = self::parseRule($rule);
            $normalizedName = str_replace('-', '_', strtolower($ruleName));
            return in_array($normalizedName, self::IMPLICIT_RULES, true);
        }

        return false;
    }

    /**
     * Check if a rule class exists.
     *
     * @param string $ruleName Rule name
     * @return bool
     */
    public static function hasRule(string $ruleName): bool
    {
        $normalizedName = str_replace('-', '_', strtolower($ruleName));
        return isset(self::RULE_CLASSES[$normalizedName])
            || isset(self::$customRules[$normalizedName])
            || isset(self::$customRules[$ruleName]);
    }

    /**
     * Register custom rule.
     *
     * @param string $name Rule name
     * @param callable|RuleInterface $rule Rule callback or Rule object
     * @param string|null $message Custom error message
     * @return void
     */
    public static function register(string $name, callable|RuleInterface $rule, ?string $message = null): void
    {
        self::$customRules[$name] = $rule;

        if ($message !== null) {
            self::$customMessages[$name] = $message;
        }

        // Clear cache for this rule name
        self::clearCache($name);
    }

    /**
     * Get custom message for rule.
     *
     * @param string $ruleName Rule name
     * @return string|null
     */
    public static function getCustomMessage(string $ruleName): ?string
    {
        return self::$customMessages[$ruleName] ?? null;
    }

    /**
     * Clear rule cache.
     *
     * @param string|null $ruleName Specific rule name, or null to clear all
     * @return void
     */
    public static function clearCache(?string $ruleName = null): void
    {
        if ($ruleName === null) {
            self::$ruleCache = [];
            return;
        }

        // Remove all cached rules matching this name
        foreach (array_keys(self::$ruleCache) as $key) {
            if (str_starts_with($key, $ruleName . ':') || $key === $ruleName) {
                unset(self::$ruleCache[$key]);
            }
        }
    }

    /**
     * Parse rule string into name and parameters.
     *
     * @param string $rule Rule string (e.g., "max:255" or "in:a,b,c")
     * @return array{string, array<string>}
     */
    private static function parseRule(string $rule): array
    {
        if (!str_contains($rule, ':')) {
            return [$rule, []];
        }

        [$ruleName, $params] = explode(':', $rule, 2);

        // Special handling for regex patterns that may contain colons
        if (strtolower($ruleName) === 'regex' || strtolower($ruleName) === 'not_regex') {
            return [$ruleName, [$params]];
        }

        return [$ruleName, explode(',', $params)];
    }

    /**
     * Get cache key for rule.
     *
     * @param string $rule Rule string
     * @return string
     */
    private static function getCacheKey(string $rule): string
    {
        return $rule;
    }

    /**
     * Resolve custom rule.
     *
     * @param string $ruleName Rule name
     * @param array<string> $parameters Rule parameters
     * @return RuleInterface
     */
    private static function resolveCustomRule(string $ruleName, array $parameters): RuleInterface
    {
        $rule = self::$customRules[$ruleName];

        // Already a Rule object
        if ($rule instanceof RuleInterface) {
            return $rule;
        }

        // Wrap callable in Rule object
        return new class($rule, $parameters, $ruleName) implements RuleInterface {
            /**
             * @var callable Validation callback
             */
            private readonly mixed $callback;

            /**
             * @param callable $callback Validation callback
             * @param array<string> $parameters Rule parameters
             * @param string $ruleName Rule name
             */
            public function __construct(
                mixed $callback,
                private readonly array $parameters,
                private readonly string $ruleName
            ) {
                $this->callback = $callback;
            }

            public function passes(string $attribute, mixed $value): bool
            {
                return (bool) ($this->callback)($value, $this->parameters, []);
            }

            public function message(): string
            {
                $customMessage = RuleManager::getCustomMessage($this->ruleName);
                return $customMessage ?? "The :attribute is invalid.";
            }
        };
    }

    /**
     * Resolve built-in rule.
     *
     * @param string $ruleName Rule name
     * @param array<string> $parameters Rule parameters
     * @return RuleInterface
     */
    private static function resolveBuiltInRule(string $ruleName, array $parameters): RuleInterface
    {
        // Check if we have a Rule class for this rule
        if (!isset(self::RULE_CLASSES[$ruleName])) {
            // Return fallback rule for unknown rules (will be handled by Validator)
            return new class($ruleName, $parameters) implements RuleInterface {
                public function __construct(
                    public readonly string $ruleName,
                    public readonly array $parameters
                ) {}

                public function passes(string $attribute, mixed $value): bool
                {
                    // Unknown rules are handled by Validator fallback
                    return true;
                }

                public function message(): string
                {
                    return "The :attribute is invalid.";
                }
            };
        }

        $ruleClass = self::RULE_CLASSES[$ruleName];

        // Instantiate rule with appropriate parameters
        return self::instantiateRule($ruleClass, $ruleName, $parameters);
    }

    /**
     * Instantiate a rule class with parameters.
     *
     * @param string $ruleClass Fully qualified class name
     * @param string $ruleName Original rule name
     * @param array<string> $parameters Rule parameters
     * @return RuleInterface
     */
    private static function instantiateRule(string $ruleClass, string $ruleName, array $parameters): RuleInterface
    {
        // Rules without parameters
        $noParamRules = [
            'required', 'email', 'string', 'numeric', 'integer', 'boolean',
            'url', 'active_url', 'alpha', 'alpha_num', 'alpha_dash', 'json',
            'uuid', 'ulid', 'mac_address', 'timezone', 'lowercase', 'uppercase',
            'file', 'image', 'nullable', 'sometimes', 'confirmed', 'present',
            'filled', 'accepted', 'declined', 'prohibited', 'date',
        ];

        if (in_array($ruleName, $noParamRules, true) || empty($parameters)) {
            return new $ruleClass();
        }

        // Rules with single parameter
        $singleParamRules = [
            'min' => fn($p) => new $ruleClass((float) $p[0]),
            'max' => fn($p) => new $ruleClass((float) $p[0]),
            'size' => fn($p) => new $ruleClass((float) $p[0]),
            'digits' => fn($p) => new $ruleClass((int) $p[0]),
            'same' => fn($p) => new $ruleClass($p[0]),
            'different' => fn($p) => new $ruleClass($p[0]),
            'gt' => fn($p) => new $ruleClass($p[0]),
            'gte' => fn($p) => new $ruleClass($p[0]),
            'lt' => fn($p) => new $ruleClass($p[0]),
            'lte' => fn($p) => new $ruleClass($p[0]),
            'after' => fn($p) => new $ruleClass($p[0]),
            'after_or_equal' => fn($p) => new $ruleClass($p[0]),
            'before' => fn($p) => new $ruleClass($p[0]),
            'before_or_equal' => fn($p) => new $ruleClass($p[0]),
            'date_equals' => fn($p) => new $ruleClass($p[0]),
            'regex' => fn($p) => new $ruleClass($p[0]),
            'not_regex' => fn($p) => new $ruleClass($p[0]),
            'array_min' => fn($p) => new $ruleClass((int) $p[0]),
            'array_max' => fn($p) => new $ruleClass((int) $p[0]),
            'distinct' => fn($p) => new $ruleClass($p[0] ?? null),
        ];

        if (isset($singleParamRules[$ruleName])) {
            return $singleParamRules[$ruleName]($parameters);
        }

        // Rules with two parameters
        $twoParamRules = [
            'between' => fn($p) => new $ruleClass((float) $p[0], (float) $p[1]),
            'digits_between' => fn($p) => new $ruleClass((int) $p[0], (int) $p[1]),
        ];

        if (isset($twoParamRules[$ruleName])) {
            return $twoParamRules[$ruleName]($parameters);
        }

        // Rules with multiple parameters (as array)
        $multiParamRules = [
            'in' => fn($p) => new $ruleClass($p),
            'not_in' => fn($p) => new $ruleClass($p),
            'mimes' => fn($p) => new $ruleClass($p),
            'mimetypes' => fn($p) => new $ruleClass($p),
            'extensions' => fn($p) => new $ruleClass($p),
            'starts_with' => fn($p) => new $ruleClass($p),
            'ends_with' => fn($p) => new $ruleClass($p),
            'doesnt_start_with' => fn($p) => new $ruleClass($p),
            'doesnt_end_with' => fn($p) => new $ruleClass($p),
            'required_with' => fn($p) => new $ruleClass($p),
            'required_with_all' => fn($p) => new $ruleClass($p),
            'required_without' => fn($p) => new $ruleClass($p),
            'required_without_all' => fn($p) => new $ruleClass($p),
            'date_format' => fn($p) => new $ruleClass($p),
        ];

        if (isset($multiParamRules[$ruleName])) {
            return $multiParamRules[$ruleName]($parameters);
        }

        // Conditional rules with field and value(s)
        $conditionalRules = [
            'required_if' => fn($p) => new $ruleClass($p[0], array_slice($p, 1)),
            'required_unless' => fn($p) => new $ruleClass($p[0], array_slice($p, 1)),
            'prohibited_if' => fn($p) => new $ruleClass($p[0], array_slice($p, 1)),
            'prohibited_unless' => fn($p) => new $ruleClass($p[0], array_slice($p, 1)),
            'accepted_if' => fn($p) => new $ruleClass($p[0], $p[1] ?? true),
            'declined_if' => fn($p) => new $ruleClass($p[0], $p[1] ?? true),
        ];

        if (isset($conditionalRules[$ruleName])) {
            return $conditionalRules[$ruleName]($parameters);
        }

        // Special handling for 'ip' rule with optional version
        if ($ruleName === 'ip') {
            return new $ruleClass($parameters[0] ?? null);
        }

        // Special handling for 'array' rule with optional key constraints
        if ($ruleName === 'array') {
            return empty($parameters) ? new $ruleClass() : new $ruleClass(null, null, $parameters);
        }

        // Special handling for 'dimensions' rule
        if ($ruleName === 'dimensions') {
            $constraints = self::parseDimensionsParameters($parameters);
            return new $ruleClass($constraints);
        }

        // Special handling for 'exists' and 'unique' database rules
        if ($ruleName === 'exists') {
            return new $ruleClass(
                $parameters[0] ?? '',
                $parameters[1] ?? null,
                $parameters[2] ?? null
            );
        }

        if ($ruleName === 'unique') {
            return new $ruleClass(
                $parameters[0] ?? '',
                $parameters[1] ?? null,
                $parameters[2] ?? null,
                $parameters[3] ?? null
            );
        }

        // Default fallback - instantiate with all parameters
        return new $ruleClass(...$parameters);
    }

    /**
     * Parse dimensions rule parameters.
     *
     * @param array<string> $parameters Raw parameters like ['min_width=100', 'max_height=500']
     * @return array<string, int|float|string>
     */
    private static function parseDimensionsParameters(array $parameters): array
    {
        $constraints = [];

        foreach ($parameters as $param) {
            if (str_contains($param, '=')) {
                [$key, $value] = explode('=', $param, 2);
                $constraints[$key] = is_numeric($value) ? (int) $value : $value;
            }
        }

        return $constraints;
    }

    /**
     * Get all registered rule names.
     *
     * @return array<string>
     */
    public static function getRuleNames(): array
    {
        return array_merge(
            array_keys(self::RULE_CLASSES),
            array_keys(self::$customRules)
        );
    }
}
