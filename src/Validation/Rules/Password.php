<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules;

use Toporia\Framework\Validation\Contracts\RuleInterface;

/**
 * Class Password
 *
 * Validates password strength with configurable requirements.
 *
 * Configuration options:
 *   - min: Minimum length (default: 8)
 *   - letters: Require at least one letter
 *   - mixedCase: Require both uppercase and lowercase letters
 *   - numbers: Require at least one number
 *   - symbols: Require at least one special character
 *   - uncompromised: Check against compromised password databases (optional)
 *
 * Performance: O(n) where n = password length
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
final class Password implements RuleInterface
{
    private int $min = 8;
    private bool $letters = false;
    private bool $mixedCase = false;
    private bool $numbers = false;
    private bool $symbols = false;
    private bool $uncompromised = false;

    /**
     * @var array<string> Failed requirements for error message
     */
    private array $failedRequirements = [];

    /**
     * Set minimum length requirement.
     *
     * @param int $length Minimum length
     * @return self
     */
    public function min(int $length): self
    {
        $clone = clone $this;
        $clone->min = $length;
        return $clone;
    }

    /**
     * Require at least one letter.
     *
     * @return self
     */
    public function letters(): self
    {
        $clone = clone $this;
        $clone->letters = true;
        return $clone;
    }

    /**
     * Require both uppercase and lowercase letters.
     *
     * @return self
     */
    public function mixedCase(): self
    {
        $clone = clone $this;
        $clone->mixedCase = true;
        return $clone;
    }

    /**
     * Require at least one number.
     *
     * @return self
     */
    public function numbers(): self
    {
        $clone = clone $this;
        $clone->numbers = true;
        return $clone;
    }

    /**
     * Require at least one special character.
     *
     * @return self
     */
    public function symbols(): self
    {
        $clone = clone $this;
        $clone->symbols = true;
        return $clone;
    }

    /**
     * Apply default rules for a strong password.
     *
     * @return self
     */
    public static function defaults(): self
    {
        return (new self())
            ->min(8)
            ->letters()
            ->mixedCase()
            ->numbers()
            ->symbols();
    }

    /**
     * Determine if the validation rule passes.
     *
     * @param string $attribute The attribute name being validated
     * @param mixed $value The value being validated
     * @return bool
     */
    public function passes(string $attribute, mixed $value): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        if (!is_string($value)) {
            return false;
        }

        $this->failedRequirements = [];

        // Check minimum length
        if (mb_strlen($value) < $this->min) {
            $this->failedRequirements[] = "at least {$this->min} characters";
        }

        // Check for letters
        if ($this->letters && !preg_match('/[a-zA-Z]/', $value)) {
            $this->failedRequirements[] = "at least one letter";
        }

        // Check for mixed case
        if ($this->mixedCase) {
            if (!preg_match('/[a-z]/', $value)) {
                $this->failedRequirements[] = "at least one lowercase letter";
            }
            if (!preg_match('/[A-Z]/', $value)) {
                $this->failedRequirements[] = "at least one uppercase letter";
            }
        }

        // Check for numbers
        if ($this->numbers && !preg_match('/[0-9]/', $value)) {
            $this->failedRequirements[] = "at least one number";
        }

        // Check for symbols
        if ($this->symbols && !preg_match('/[^a-zA-Z0-9]/', $value)) {
            $this->failedRequirements[] = "at least one special character";
        }

        return empty($this->failedRequirements);
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message(): string
    {
        if (empty($this->failedRequirements)) {
            return "The :attribute does not meet the password requirements.";
        }

        return "The :attribute must contain " . implode(', ', $this->failedRequirements) . ".";
    }
}
