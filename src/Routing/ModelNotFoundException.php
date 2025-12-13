<?php

declare(strict_types=1);

namespace Toporia\Framework\Routing;

use RuntimeException;

/**
 * Class ModelNotFoundException
 *
 * Thrown when a model cannot be found during route model binding.
 * Can be caught by exception handler to return a 404 response.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Routing
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class ModelNotFoundException extends RuntimeException
{
    /**
     * The model class.
     *
     * @var class-string|null
     */
    protected ?string $model = null;

    /**
     * The value searched for.
     *
     * @var array<mixed>
     */
    protected array $ids = [];

    /**
     * Set the model and IDs.
     *
     * @param class-string $model
     * @param array<mixed>|mixed $ids
     * @return static
     */
    public function setModel(string $model, array|int|string $ids = []): static
    {
        $this->model = $model;
        $this->ids = is_array($ids) ? $ids : [$ids];

        $this->message = sprintf(
            'No query results for model [%s] %s.',
            $model,
            count($this->ids) > 0 ? implode(', ', $this->ids) : ''
        );

        return $this;
    }

    /**
     * Get the model class.
     *
     * @return class-string|null
     */
    public function getModel(): ?string
    {
        return $this->model;
    }

    /**
     * Get the IDs.
     *
     * @return array<mixed>
     */
    public function getIds(): array
    {
        return $this->ids;
    }

    /**
     * Get the HTTP status code.
     *
     * @return int
     */
    public function getStatusCode(): int
    {
        return 404;
    }
}
