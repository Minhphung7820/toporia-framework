<?php

declare(strict_types=1);

namespace Toporia\Framework\Http\Contracts;

/**
 * Streamed Response Interface
 *
 * Interface for streaming HTTP responses with performance optimizations.
 *
 * @author      Toporia Framework Team
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Http\Contracts
 */
interface StreamedResponseInterface extends ResponseInterface
{
    /**
     * Set the stream callback.
     *
     * @param callable $callback Stream callback
     * @return $this
     */
    public function setCallback(callable $callback): static;

    /**
     * Get the stream callback.
     *
     * @return callable
     */
    public function getCallback(): callable;

    /**
     * Send the streamed response.
     *
     * @return void
     */
    public function sendContent(): void;
}
