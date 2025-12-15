<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules;

use Toporia\Framework\Validation\Contracts\RuleInterface;
use Toporia\Framework\Validation\Rules\Concerns\ReplacesAttributes;

/**
 * Class Color
 *
 * Validates that the value is a valid color format.
 *
 * Supports:
 * - Hex: #RGB, #RRGGBB, #RRGGBBAA
 * - RGB: rgb(r, g, b)
 * - RGBA: rgba(r, g, b, a)
 * - HSL: hsl(h, s%, l%)
 * - HSLA: hsla(h, s%, l%, a)
 * - Named colors (optional)
 *
 * Performance: O(1) - Single regex match per format
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Validation\Rules
 * @since       2025-01-10
 */
final class Color implements RuleInterface
{
    use ReplacesAttributes;

    /**
     * CSS named colors (subset of most common).
     */
    private const NAMED_COLORS = [
        'black', 'white', 'red', 'green', 'blue', 'yellow', 'cyan', 'magenta',
        'gray', 'grey', 'orange', 'pink', 'purple', 'brown', 'navy', 'teal',
        'olive', 'maroon', 'aqua', 'fuchsia', 'lime', 'silver', 'transparent',
        'inherit', 'initial', 'currentcolor',
    ];

    /**
     * @param string|null $format Specific format to validate ('hex', 'rgb', 'rgba', 'hsl', 'hsla', 'named')
     * @param bool $allowNamed Whether to allow named colors
     */
    public function __construct(
        private readonly ?string $format = null,
        private readonly bool $allowNamed = true
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

        $color = trim($value);

        // If specific format requested, validate only that format
        if ($this->format !== null) {
            return match (strtolower($this->format)) {
                'hex' => $this->isHex($color),
                'rgb' => $this->isRgb($color),
                'rgba' => $this->isRgba($color),
                'hsl' => $this->isHsl($color),
                'hsla' => $this->isHsla($color),
                'named' => $this->isNamed($color),
                default => false,
            };
        }

        // Validate any supported format
        return $this->isHex($color)
            || $this->isRgb($color)
            || $this->isRgba($color)
            || $this->isHsl($color)
            || $this->isHsla($color)
            || ($this->allowNamed && $this->isNamed($color));
    }

    /**
     * Check if value is a valid hex color.
     *
     * @param string $value
     * @return bool
     */
    private function isHex(string $value): bool
    {
        // #RGB, #RRGGBB, #RRGGBBAA
        return preg_match('/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6}|[A-Fa-f0-9]{8})$/', $value) === 1;
    }

    /**
     * Check if value is a valid rgb() color.
     *
     * @param string $value
     * @return bool
     */
    private function isRgb(string $value): bool
    {
        // rgb(0, 0, 0) or rgb(0 0 0) or rgb(0%, 0%, 0%)
        $pattern = '/^rgb\(\s*(\d{1,3}%?\s*,\s*){2}\d{1,3}%?\s*\)$/i';
        $patternSpace = '/^rgb\(\s*\d{1,3}%?\s+\d{1,3}%?\s+\d{1,3}%?\s*\)$/i';

        return preg_match($pattern, $value) === 1 || preg_match($patternSpace, $value) === 1;
    }

    /**
     * Check if value is a valid rgba() color.
     *
     * @param string $value
     * @return bool
     */
    private function isRgba(string $value): bool
    {
        // rgba(0, 0, 0, 0.5) or rgba(0 0 0 / 0.5)
        $pattern = '/^rgba\(\s*(\d{1,3}%?\s*,\s*){3}(0|1|0?\.\d+)\s*\)$/i';
        $patternSlash = '/^rgba\(\s*\d{1,3}%?\s+\d{1,3}%?\s+\d{1,3}%?\s*\/\s*(0|1|0?\.\d+|[\d.]+%)\s*\)$/i';

        return preg_match($pattern, $value) === 1 || preg_match($patternSlash, $value) === 1;
    }

    /**
     * Check if value is a valid hsl() color.
     *
     * @param string $value
     * @return bool
     */
    private function isHsl(string $value): bool
    {
        // hsl(0, 0%, 0%) or hsl(0deg 0% 0%)
        $pattern = '/^hsl\(\s*\d{1,3}(deg|rad|grad|turn)?\s*,\s*\d{1,3}%\s*,\s*\d{1,3}%\s*\)$/i';
        $patternSpace = '/^hsl\(\s*\d{1,3}(deg|rad|grad|turn)?\s+\d{1,3}%\s+\d{1,3}%\s*\)$/i';

        return preg_match($pattern, $value) === 1 || preg_match($patternSpace, $value) === 1;
    }

    /**
     * Check if value is a valid hsla() color.
     *
     * @param string $value
     * @return bool
     */
    private function isHsla(string $value): bool
    {
        // hsla(0, 0%, 0%, 0.5) or hsla(0deg 0% 0% / 0.5)
        $pattern = '/^hsla\(\s*\d{1,3}(deg|rad|grad|turn)?\s*,\s*\d{1,3}%\s*,\s*\d{1,3}%\s*,\s*(0|1|0?\.\d+)\s*\)$/i';
        $patternSlash = '/^hsla\(\s*\d{1,3}(deg|rad|grad|turn)?\s+\d{1,3}%\s+\d{1,3}%\s*\/\s*(0|1|0?\.\d+|[\d.]+%)\s*\)$/i';

        return preg_match($pattern, $value) === 1 || preg_match($patternSlash, $value) === 1;
    }

    /**
     * Check if value is a named color.
     *
     * @param string $value
     * @return bool
     */
    private function isNamed(string $value): bool
    {
        return in_array(strtolower($value), self::NAMED_COLORS, true);
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message(): string
    {
        if ($this->format !== null) {
            return "The :attribute must be a valid {$this->format} color.";
        }

        return 'The :attribute must be a valid color.';
    }
}
