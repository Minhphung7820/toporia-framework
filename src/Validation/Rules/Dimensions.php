<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules;

use Toporia\Framework\Validation\Contracts\RuleInterface;

/**
 * Class Dimensions
 *
 * Validates image dimensions (width, height, min/max constraints, ratio).
 *
 * Usage:
 *   new Dimensions(['min_width' => 100, 'min_height' => 100])
 *   new Dimensions(['width' => 800, 'height' => 600])
 *   new Dimensions(['ratio' => '16/9'])
 *   new Dimensions(['max_width' => 1920, 'max_height' => 1080])
 *
 * Performance: O(1) - Image dimension check
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
final class Dimensions implements RuleInterface
{
    /**
     * @var array<string, int|float|string> Dimension constraints
     */
    private readonly array $constraints;

    /**
     * @var string Failed constraint for error message
     */
    private string $failedConstraint = '';

    /**
     * @param array<string, int|float|string> $constraints Dimension constraints
     */
    public function __construct(array $constraints)
    {
        $this->constraints = $constraints;
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

        $path = $this->getFilePath($value);

        if ($path === null || !is_file($path)) {
            return false;
        }

        $dimensions = @getimagesize($path);

        if ($dimensions === false) {
            return false;
        }

        [$width, $height] = $dimensions;

        return $this->validateDimensions($width, $height);
    }

    /**
     * Validate dimensions against constraints.
     *
     * @param int $width Image width
     * @param int $height Image height
     * @return bool
     */
    private function validateDimensions(int $width, int $height): bool
    {
        // Exact width
        if (isset($this->constraints['width'])) {
            if ($width !== (int) $this->constraints['width']) {
                $this->failedConstraint = "width must be {$this->constraints['width']}px";
                return false;
            }
        }

        // Exact height
        if (isset($this->constraints['height'])) {
            if ($height !== (int) $this->constraints['height']) {
                $this->failedConstraint = "height must be {$this->constraints['height']}px";
                return false;
            }
        }

        // Minimum width
        if (isset($this->constraints['min_width'])) {
            if ($width < (int) $this->constraints['min_width']) {
                $this->failedConstraint = "width must be at least {$this->constraints['min_width']}px";
                return false;
            }
        }

        // Minimum height
        if (isset($this->constraints['min_height'])) {
            if ($height < (int) $this->constraints['min_height']) {
                $this->failedConstraint = "height must be at least {$this->constraints['min_height']}px";
                return false;
            }
        }

        // Maximum width
        if (isset($this->constraints['max_width'])) {
            if ($width > (int) $this->constraints['max_width']) {
                $this->failedConstraint = "width must not exceed {$this->constraints['max_width']}px";
                return false;
            }
        }

        // Maximum height
        if (isset($this->constraints['max_height'])) {
            if ($height > (int) $this->constraints['max_height']) {
                $this->failedConstraint = "height must not exceed {$this->constraints['max_height']}px";
                return false;
            }
        }

        // Aspect ratio
        if (isset($this->constraints['ratio'])) {
            if (!$this->validateRatio($width, $height, $this->constraints['ratio'])) {
                $this->failedConstraint = "aspect ratio must be {$this->constraints['ratio']}";
                return false;
            }
        }

        return true;
    }

    /**
     * Validate aspect ratio.
     *
     * @param int $width Image width
     * @param int $height Image height
     * @param string|float $ratio Expected ratio (e.g., "16/9" or 1.78)
     * @return bool
     */
    private function validateRatio(int $width, int $height, string|float $ratio): bool
    {
        if ($height === 0) {
            return false;
        }

        $actualRatio = $width / $height;

        // Parse ratio string (e.g., "16/9")
        if (is_string($ratio) && str_contains($ratio, '/')) {
            [$numerator, $denominator] = explode('/', $ratio, 2);
            $expectedRatio = (float) $numerator / (float) $denominator;
        } else {
            $expectedRatio = (float) $ratio;
        }

        // Allow small tolerance for floating point comparison
        return abs($actualRatio - $expectedRatio) < 0.01;
    }

    /**
     * Get file path from various input types.
     *
     * @param mixed $file File value
     * @return string|null
     */
    private function getFilePath(mixed $file): ?string
    {
        // Array upload
        if (is_array($file)) {
            return $file['tmp_name'] ?? null;
        }

        // File object
        if (is_object($file)) {
            if (method_exists($file, 'getPathname')) {
                return $file->getPathname();
            }
            if (method_exists($file, 'getRealPath')) {
                return $file->getRealPath() ?: null;
            }
            if ($file instanceof \SplFileInfo) {
                return $file->getPathname();
            }
        }

        // String path
        if (is_string($file)) {
            return $file;
        }

        return null;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message(): string
    {
        if ($this->failedConstraint) {
            return "The :attribute has invalid dimensions: {$this->failedConstraint}.";
        }

        return "The :attribute has invalid image dimensions.";
    }
}
