<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\ORM\Concerns;


/**
 * Trait HasUuid
 *
 * Trait providing reusable functionality for HasUuid in the Concerns layer
 * of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Concerns
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
trait HasUuid
{
    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public bool $incrementing = false;

    /**
     * The "type" of the primary key ID.
     *
     * @var string
     */
    protected string $keyType = 'string';

    /**
     * Boot the UUID trait.
     *
     * Automatically generates UUID for new instances.
     *
     * @return void
     */
    protected static function bootHasUuid(): void
    {
        // Generate UUID when creating new model
        static::creating(function ($model) {
            if (empty($model->getKey())) {
                $model->setKey(static::generateUuid());
            }
        });
    }

    /**
     * Generate a UUID v4.
     *
     * Performance: O(1) - Fast UUID generation
     *
     * @return string
     */
    public static function generateUuid(): string
    {
        // Use random_bytes for better randomness
        $data = random_bytes(16);

        // Set version (4) and variant bits
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // Version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // Variant bits

        // Format as UUID string
        return sprintf(
            '%08s-%04s-%04s-%04s-%12s',
            bin2hex(substr($data, 0, 4)),
            bin2hex(substr($data, 4, 2)),
            bin2hex(substr($data, 6, 2)),
            bin2hex(substr($data, 8, 2)),
            bin2hex(substr($data, 10, 6))
        );
    }

    /**
     * Get the primary key value.
     *
     * @return mixed
     */
    abstract public function getKey(): mixed;

    /**
     * Set the primary key value.
     *
     * @param mixed $value
     * @return void
     */
    abstract public function setKey(mixed $value): void;
}

