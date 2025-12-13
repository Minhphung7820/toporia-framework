<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules;

use Toporia\Framework\Validation\Contracts\RuleInterface;

/**
 * Class File
 *
 * Validates that the input is a valid uploaded file.
 *
 * Performance: O(1) - Simple file check
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
final class File implements RuleInterface
{
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

        // Check for uploaded file array structure
        if (is_array($value)) {
            return $this->isValidUploadedFile($value);
        }

        // Check for file object with required methods
        if (is_object($value)) {
            return $this->isValidFileObject($value);
        }

        return false;
    }

    /**
     * Check if array represents a valid uploaded file.
     *
     * @param array $file File array
     * @return bool
     */
    private function isValidUploadedFile(array $file): bool
    {
        // Standard PHP upload structure
        if (!isset($file['tmp_name'], $file['error'])) {
            return false;
        }

        // Check for upload error
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return false;
        }

        // Verify file exists and is uploaded
        return is_uploaded_file($file['tmp_name']) || is_file($file['tmp_name']);
    }

    /**
     * Check if object is a valid file object.
     *
     * @param object $file File object
     * @return bool
     */
    private function isValidFileObject(object $file): bool
    {
        // PSR-7 UploadedFileInterface or similar
        if (method_exists($file, 'getError')) {
            $error = $file->getError();
            return $error === UPLOAD_ERR_OK;
        }

        // Symfony-style file object
        if (method_exists($file, 'isValid')) {
            return $file->isValid();
        }

        // SplFileInfo
        if ($file instanceof \SplFileInfo) {
            return $file->isFile();
        }

        return false;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message(): string
    {
        return "The :attribute must be a valid file.";
    }
}
