<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation;

/**
 * Class ValidationAttribute
 *
 * Value object representing an attribute being validated.
 * Provides metadata about the attribute (name, display name, etc.).
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
final readonly class ValidationAttribute
{
    /**
     * @param string $name Attribute name (e.g., "email", "user.email")
     * @param string $displayName Human-readable display name (e.g., "Email Address")
     */
    public function __construct(
        public string $name,
        public string $displayName
    ) {
    }

    /**
     * Create from attribute name.
     *
     * Automatically generates display name from attribute name.
     *
     * @param string $name Attribute name
     * @return self
     */
    public static function fromName(string $name): self
    {
        $displayName = str_replace(['_', '.'], ' ', $name);
        $displayName = ucwords($displayName);
        return new self($name, $displayName);
    }

    /**
     * Get attribute name.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get display name.
     *
     * @return string
     */
    public function getDisplayName(): string
    {
        return $this->displayName;
    }
}

