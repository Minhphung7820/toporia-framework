<?php

declare(strict_types=1);

namespace Toporia\Framework\Http\Contracts;

/**
 * Redirect Response Interface
 *
 * Interface for HTTP redirect responses with Toporia-style functionality.
 *
 * @author      Toporia Framework Team
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Http\Contracts
 */
interface RedirectResponseInterface extends ResponseInterface
{
    /**
     * Get the target URL.
     *
     * @return string
     */
    public function getTargetUrl(): string;

    /**
     * Set the target URL.
     *
     * @param string $url Target URL
     * @return $this
     */
    public function setTargetUrl(string $url): static;

    /**
     * Add flash data to the session.
     *
     * @param string $key Flash data key
     * @param mixed $value Flash data value
     * @return $this
     */
    public function with(string $key, mixed $value): static;

    /**
     * Flash input data to the session.
     *
     * @param array<string, mixed>|null $input Input data
     * @return $this
     */
    public function withInput(?array $input = null): static;

    /**
     * Flash errors to the session.
     *
     * @param array<string, mixed>|string $errors Error messages
     * @return $this
     */
    public function withErrors(array|string $errors): static;

    /**
     * Send the redirect response.
     *
     * @return void
     */
    public function sendResponse(): void;
}
