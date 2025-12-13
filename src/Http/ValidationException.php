<?php

declare(strict_types=1);

namespace Toporia\Framework\Http;

use RuntimeException;

/**
 * Class ValidationException
 *
 * Thrown when validation fails.
 * Contains validation errors for all fields.
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
 */
final class ValidationException extends RuntimeException
{
    /**
     * @param array<string, array<string>> $errors Validation errors
     * @param int $code HTTP status code (default: 422 Unprocessable Entity)
     */
    public function __construct(
        private array $errors,
        int $code = 422
    ) {
        $message = 'The given data was invalid.';
        parent::__construct($message, $code);
    }

    /**
     * Get validation errors.
     *
     * @return array<string, array<string>>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get first error message.
     *
     * @return string|null
     */
    public function getFirstError(): ?string
    {
        foreach ($this->errors as $fieldErrors) {
            return $fieldErrors[0] ?? null;
        }

        return null;
    }

    /**
     * Convert to array (for JSON responses).
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'errors' => $this->errors
        ];
    }
}
