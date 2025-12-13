<?php

declare(strict_types=1);

namespace Toporia\Framework\Http\Exceptions;

/**
 * Class ValidationException
 *
 * 422 Validation Exception. Exception thrown when validation fails.
 * Contains validation errors in a structured format compatible with Toporia's validation responses.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Http\Exceptions
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class ValidationException extends HttpException
{
    /**
     * @var array<string, array<string>> Validation errors by field
     */
    protected array $errors;

    /**
     * @var string|null Redirect URL on failure
     */
    protected ?string $redirectTo = null;

    /**
     * Create a new validation exception.
     *
     * @param array<string, array<string>|string> $errors Validation errors
     * @param string $message Error message
     */
    public function __construct(array $errors = [], string $message = 'The given data was invalid.')
    {
        $this->errors = $this->normalizeErrors($errors);
        parent::__construct(422, $message);
    }

    /**
     * Get the validation errors.
     *
     * @return array<string, array<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Get all error messages as a flat array.
     *
     * @return array<string>
     */
    public function messages(): array
    {
        $messages = [];
        foreach ($this->errors as $field => $fieldErrors) {
            foreach ($fieldErrors as $error) {
                $messages[] = $error;
            }
        }
        return $messages;
    }

    /**
     * Get the first error message for a field.
     *
     * @param string|null $field Field name (null for first error overall)
     * @return string|null
     */
    public function first(?string $field = null): ?string
    {
        if ($field !== null) {
            return $this->errors[$field][0] ?? null;
        }

        foreach ($this->errors as $fieldErrors) {
            if (!empty($fieldErrors)) {
                return $fieldErrors[0];
            }
        }

        return null;
    }

    /**
     * Check if a field has errors.
     *
     * @param string $field Field name
     * @return bool
     */
    public function has(string $field): bool
    {
        return isset($this->errors[$field]) && !empty($this->errors[$field]);
    }

    /**
     * Set the URL to redirect to on failure.
     *
     * @param string $url
     * @return static
     */
    public function redirectTo(string $url): static
    {
        $this->redirectTo = $url;
        return $this;
    }

    /**
     * Get the redirect URL.
     *
     * @return string|null
     */
    public function getRedirectTo(): ?string
    {
        return $this->redirectTo;
    }

    /**
     * Convert to array format for JSON responses.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'errors' => $this->errors,
        ];
    }

    /**
     * Create exception from a validator instance.
     *
     * @param object $validator Validator with errors() method
     * @return static
     */
    public static function withMessages(array $messages): static
    {
        return new static($messages);
    }

    /**
     * Normalize errors to array format.
     *
     * @param array<string, array<string>|string> $errors
     * @return array<string, array<string>>
     */
    private function normalizeErrors(array $errors): array
    {
        $normalized = [];
        foreach ($errors as $field => $messages) {
            $normalized[$field] = is_array($messages) ? $messages : [$messages];
        }
        return $normalized;
    }
}
