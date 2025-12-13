<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\Access;

use Stringable;
use Toporia\Framework\Auth\AuthorizationException;

/**
 * Class Response
 *
 * Represents the result of an authorization check with optional message/code.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Auth\Access
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class Response implements Stringable
{
    /**
     * Create authorization response.
     *
     * @param bool $allowed Whether action is allowed
     * @param string|null $message Optional message explaining decision
     * @param mixed $code Optional error code
     */
    public function __construct(
        private readonly bool $allowed,
        private readonly ?string $message = null,
        private readonly mixed $code = null
    ) {
    }

    /**
     * Create an "allowed" response.
     *
     * @param string|null $message Optional success message
     * @return self Allowed response
     */
    public static function allow(?string $message = null): self
    {
        return new self(true, $message);
    }

    /**
     * Create a "denied" response.
     *
     * @param string|null $message Optional denial reason
     * @param mixed $code Optional error code
     * @return self Denied response
     */
    public static function deny(?string $message = null, mixed $code = null): self
    {
        return new self(false, $message, $code);
    }

    /**
     * Determine if the response was allowed.
     *
     * @return bool True if allowed
     */
    public function allowed(): bool
    {
        return $this->allowed;
    }

    /**
     * Determine if the response was denied.
     *
     * @return bool True if denied
     */
    public function denied(): bool
    {
        return !$this->allowed;
    }

    /**
     * Get the response message.
     *
     * @return string|null Message or null
     */
    public function message(): ?string
    {
        return $this->message;
    }

    /**
     * Get the response code.
     *
     * @return mixed Code or null
     */
    public function code(): mixed
    {
        return $this->code;
    }

    /**
     * Authorize the response or throw exception.
     *
     * @return self
     * @throws \Toporia\Framework\Auth\AuthorizationException If denied
     */
    public function authorize(): self
    {
        if ($this->denied()) {
            throw new AuthorizationException(
                $this->message ?? 'This action is unauthorized.',
                $this->code ?? 403
            );
        }

        return $this;
    }

    /**
     * Get string representation of response.
     *
     * @return string Message or empty string
     */
    public function __toString(): string
    {
        return $this->message ?? '';
    }

    /**
     * Convert to array.
     *
     * @return array{allowed: bool, message: string|null, code: mixed}
     */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'message' => $this->message,
            'code' => $this->code,
        ];
    }
}
