<?php

declare(strict_types=1);

namespace Toporia\Framework\Http;

use Toporia\Framework\Validation\Validator;
use Toporia\Framework\Validation\Contracts\ValidatorInterface;
use Toporia\Framework\Container\Contracts\ContainerInterface;


/**
 * Abstract Class FormRequest
 *
 * HTTP request abstraction providing access to request data, headers,
 * files, cookies, and server variables with built-in security features and
 * validation support.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Http
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @internal    This class is a core component and should not be extended
 *              directly unless you know what you're doing.
 */
abstract class FormRequest
{
    /**
     * @var Request The HTTP request
     */
    protected Request $request;

    /**
     * @var ValidatorInterface The validator instance
     */
    protected ValidatorInterface $validator;

    /**
     * @var array Validated data (cached)
     */
    protected array $validatedData = [];

    /**
     * @var bool Validation status (cached)
     */
    protected bool $isValidated = false;

    /**
     * @var array|null Cached rules
     */
    protected ?array $cachedRules = null;

    /**
     * @var array|null Cached messages
     */
    protected ?array $cachedMessages = null;

    /**
     * @var ContainerInterface|null Container for dependency injection
     */
    protected ?ContainerInterface $container = null;

    /**
     * Create a new form request instance.
     *
     * Performance: O(1) - Simple property assignment
     *
     * @param Request $request HTTP request
     * @param ContainerInterface|null $container DI container (optional)
     */
    public function __construct(Request $request, ?ContainerInterface $container = null)
    {
        $this->request = $request;
        $this->container = $container;
        $this->validator = new Validator();
    }

    /**
     * Get validation rules.
     *
     * Override this method to define validation rules.
     * Rules are cached for performance.
     *
     * Performance: O(1) after first call (cached)
     *
     * @return array<string, string|array>
     */
    abstract public function rules(): array;

    /**
     * Get custom error messages.
     *
     * Override to customize error messages.
     * Messages are cached for performance.
     *
     * Performance: O(1) after first call (cached)
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [];
    }

    /**
     * Get custom attribute names for validation errors.
     *
     * Override to customize field names in error messages.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [];
    }

    /**
     * Determine if the user is authorized to make this request.
     *
     * Override to add authorization logic.
     * This is checked BEFORE validation for performance.
     *
     * Performance: O(1) - Early exit if unauthorized
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare the data for validation.
     *
     * Override to modify data before validation.
     * Useful for merging computed fields, sanitizing, etc.
     *
     * Performance: O(N) where N = fields to prepare
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        // Override in child classes
    }

    /**
     * Configure the validator instance.
     *
     * Override to customize validator behavior.
     * Useful for adding custom rules, modifying messages, etc.
     *
     * @param ValidatorInterface $validator
     * @return void
     */
    public function withValidator(ValidatorInterface $validator): void
    {
        // Override in child classes
    }

    /**
     * Get validation rules that apply to the request.
     *
     * Supports conditional rules based on request state.
     * Rules are cached for performance.
     *
     * Performance: O(N) first call, O(1) subsequent (cached)
     *
     * @return array<string, string|array>
     */
    public function getRules(): array
    {
        if ($this->cachedRules !== null) {
            return $this->cachedRules;
        }

        $rules = $this->rules();

        // Support conditional rules
        $rules = $this->resolveConditionalRules($rules);

        $this->cachedRules = $rules;

        return $rules;
    }

    /**
     * Get custom error messages.
     *
     * Messages are cached for performance.
     *
     * Performance: O(1) after first call (cached)
     *
     * @return array<string, string>
     */
    public function getMessages(): array
    {
        if ($this->cachedMessages !== null) {
            return $this->cachedMessages;
        }

        $this->cachedMessages = $this->messages();

        return $this->cachedMessages;
    }

    /**
     * Validate the request.
     *
     * This method:
     * 1. Checks authorization (early exit if fails)
     * 2. Prepares data for validation
     * 3. Validates data
     * 4. Stores validated data
     *
     * Performance: O(N*R) where N = fields, R = rules
     *
     * @return void
     * @throws ValidationException if validation fails
     * @throws \RuntimeException if authorization fails
     */
    public function validate(): void
    {
        // Early exit if already validated
        if ($this->isValidated) {
            return;
        }

        // Check authorization first (performance: early exit)
        if (!$this->authorize()) {
            throw new \RuntimeException('This action is unauthorized.', 403);
        }

        // Prepare data for validation
        $this->prepareForValidation();

        // Get all input data (after preparation)
        // Use merged data if available, otherwise use request data
        $data = $this->mergedData ?? $this->request->all();

        // Get rules and messages
        $rules = $this->getRules();
        $messages = $this->getMessages();

        // Configure validator
        $this->withValidator($this->validator);

        // Validate
        $passes = $this->validator->validate($data, $rules, $messages);

        if (!$passes) {
            throw new ValidationException($this->validator->errors());
        }

        // Store validated data
        $this->validatedData = $this->validator->validated();
        $this->isValidated = true;
    }

    /**
     * Get validated data.
     *
     * Returns only fields that passed validation.
     *
     * Performance: O(1) - Direct array access
     *
     * @return array
     */
    public function validated(): array
    {
        if (!$this->isValidated) {
            $this->validate();
        }

        return $this->validatedData;
    }

    /**
     * Get a specific validated field.
     *
     * Performance: O(1) - Direct array access
     *
     * @param string $key Field name
     * @param mixed $default Default value
     * @return mixed
     */
    public function input(string $key, mixed $default = null): mixed
    {
        if (!$this->isValidated) {
            $this->validate();
        }

        return $this->validatedData[$key] ?? $default;
    }

    /**
     * Get only specific validated fields.
     *
     * Performance: O(K) where K = number of keys
     *
     * @param array $keys Field names
     * @return array
     */
    public function only(array $keys): array
    {
        if (!$this->isValidated) {
            $this->validate();
        }

        return array_intersect_key($this->validatedData, array_flip($keys));
    }

    /**
     * Get all validated fields except specific ones.
     *
     * Performance: O(V) where V = validated fields
     *
     * @param array $keys Fields to exclude
     * @return array
     */
    public function except(array $keys): array
    {
        if (!$this->isValidated) {
            $this->validate();
        }

        return array_diff_key($this->validatedData, array_flip($keys));
    }

    /**
     * Check if a field exists in validated data.
     *
     * Performance: O(1) - array_key_exists
     *
     * @param string $key Field name
     * @return bool
     */
    public function has(string $key): bool
    {
        if (!$this->isValidated) {
            $this->validate();
        }

        return array_key_exists($key, $this->validatedData);
    }

    /**
     * Get the underlying request instance.
     *
     * Performance: O(1) - Direct property access
     *
     * @return Request
     */
    public function getRequest(): Request
    {
        return $this->request;
    }

    /**
     * Get a route parameter.
     *
     * Route parameters are stored in request attributes by Router.
     *
     * Performance: O(1) - Direct access
     *
     * @param string|null $key Parameter name (null = get all parameters)
     * @param mixed $default Default value if parameter not found
     * @return mixed
     */
    public function route(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            // Return all route parameters
            $allAttributes = $this->request->getAttributes();
            $routeParams = [];
            foreach ($allAttributes as $attrKey => $value) {
                if (str_starts_with($attrKey, 'route.')) {
                    $paramKey = substr($attrKey, 6); // Remove 'route.' prefix
                    $routeParams[$paramKey] = $value;
                }
            }
            return $routeParams;
        }

        // Get specific route parameter
        return $this->request->getAttribute("route.{$key}", $default);
    }

    /**
     * Get the authenticated user.
     *
     * Performance: O(1) - Direct access
     *
     * @return mixed|null
     */
    public function user(): mixed
    {
        // User would be resolved from container/auth
        // This is a placeholder for future implementation
        if ($this->container && $this->container->has('auth')) {
            return $this->container->get('auth')->user();
        }

        return null;
    }

    /**
     * Merge additional data into the request.
     *
     * Useful in prepareForValidation() to add computed fields.
     *
     * Performance: O(N) where N = fields to merge
     *
     * @param array $data Data to merge
     * @return void
     */
    protected function merge(array $data): void
    {
        // Merge data into request body
        $current = $this->request->all();
        $merged = array_merge($current, $data);

        // Use reflection or create a new request with merged data
        // For now, we'll store merged data separately and use it during validation
        $this->mergedData = $merged;
    }

    /**
     * @var array|null Merged data for validation
     */
    protected ?array $mergedData = null;

    /**
     * Resolve conditional rules.
     *
     * Supports rules that depend on request state.
     *
     * Performance: O(R) where R = number of rules
     *
     * @param array $rules
     * @return array
     */
    protected function resolveConditionalRules(array $rules): array
    {
        // Support for sometimes() rules
        // Example: 'email' => $this->sometimes('required|email', fn() => $this->has('email'))
        foreach ($rules as $field => $rule) {
            if (is_callable($rule)) {
                $rules[$field] = $rule($this) ? $this->getDefaultRule($field) : 'nullable';
            }
        }

        return $rules;
    }

    /**
     * Get default rule for field.
     *
     * @param string $field
     * @return string
     */
    protected function getDefaultRule(string $field): string
    {
        return 'nullable';
    }

    /**
     * Reset validation state.
     *
     * Useful for testing or re-validation.
     *
     * Performance: O(1)
     *
     * @return void
     */
    public function resetValidation(): void
    {
        $this->isValidated = false;
        $this->validatedData = [];
        $this->cachedRules = null;
        $this->cachedMessages = null;
    }
}
