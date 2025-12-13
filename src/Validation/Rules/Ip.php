<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules;

use Toporia\Framework\Validation\Contracts\RuleInterface;

/**
 * Class Ip
 *
 * Validates that the value is a valid IP address.
 * Supports both IPv4 and IPv6 formats.
 *
 * Performance: O(1) - Single filter_var call
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
final class Ip implements RuleInterface
{
    public const V4 = FILTER_FLAG_IPV4;
    public const V6 = FILTER_FLAG_IPV6;

    /**
     * @param int|null $version IP version filter (V4, V6, or null for both)
     */
    public function __construct(
        private readonly ?int $version = null
    ) {}

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

        $flags = $this->version ?? 0;

        return filter_var($value, FILTER_VALIDATE_IP, $flags) !== false;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message(): string
    {
        return 'The :attribute must be a valid IP address.';
    }
}
