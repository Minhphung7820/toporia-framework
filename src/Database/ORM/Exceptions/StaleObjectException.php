<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\ORM\Exceptions;

use Toporia\Framework\Database\ORM\Model;
use RuntimeException;

/**
 * Exception thrown when optimistic locking detects a version mismatch.
 *
 * This occurs when a model was modified by another transaction between
 * the time it was read and when it was saved.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Database\ORM\Exceptions
 * @since       2025-01-22
 *
 * @link        https://github.com/Minhphung485/toporia
 */
class StaleObjectException extends RuntimeException
{
    /**
     * The model instance that caused the exception.
     *
     * @var Model|null
     */
    private ?Model $model = null;

    /**
     * Create a new StaleObjectException instance.
     *
     * @param string $message Exception message
     * @param Model|null $model The model instance
     * @param int $code Exception code
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(
        string $message = '',
        ?Model $model = null,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->model = $model;
    }

    /**
     * Get the model instance that caused the exception.
     *
     * @return Model|null
     */
    public function getModel(): ?Model
    {
        return $this->model;
    }
}

