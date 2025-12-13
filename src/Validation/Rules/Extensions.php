<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules;

use Toporia\Framework\Validation\Contracts\RuleInterface;

/**
 * Class Extensions
 *
 * Validates that the file has one of the allowed extensions.
 *
 * Performance: O(1) - Extension comparison
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
final class Extensions implements RuleInterface
{
    /**
     * @var array<string> Allowed extensions (lowercase, without dot)
     */
    private readonly array $allowedExtensions;

    /**
     * @param string|array<string> $extensions Allowed file extensions
     */
    public function __construct(string|array $extensions)
    {
        $parsed = is_string($extensions)
            ? array_map('trim', explode(',', $extensions))
            : $extensions;

        // Normalize: lowercase, remove leading dots
        $this->allowedExtensions = array_map(
            fn($ext) => strtolower(ltrim(trim($ext), '.')),
            $parsed
        );
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

        $extension = $this->getExtension($value);

        if ($extension === null) {
            return false;
        }

        return in_array(strtolower($extension), $this->allowedExtensions, true);
    }

    /**
     * Get file extension.
     *
     * @param mixed $file File value
     * @return string|null
     */
    private function getExtension(mixed $file): ?string
    {
        // Array upload
        if (is_array($file)) {
            if (!isset($file['name'])) {
                return null;
            }
            return pathinfo($file['name'], PATHINFO_EXTENSION) ?: null;
        }

        // File object
        if (is_object($file)) {
            // PSR-7 UploadedFileInterface
            if (method_exists($file, 'getClientFilename')) {
                $filename = $file->getClientFilename();
                if ($filename) {
                    return pathinfo($filename, PATHINFO_EXTENSION) ?: null;
                }
            }

            // Symfony-style
            if (method_exists($file, 'getClientOriginalExtension')) {
                return $file->getClientOriginalExtension() ?: null;
            }

            // SplFileInfo
            if ($file instanceof \SplFileInfo) {
                return $file->getExtension() ?: null;
            }
        }

        // String path
        if (is_string($file)) {
            return pathinfo($file, PATHINFO_EXTENSION) ?: null;
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
        return "The :attribute must have one of the following extensions: " . implode(', ', $this->allowedExtensions) . ".";
    }
}
