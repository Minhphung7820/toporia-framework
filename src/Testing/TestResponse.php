<?php

declare(strict_types=1);

namespace Toporia\Framework\Testing;

/**
 * Class TestResponse
 *
 * Represents an HTTP response for testing purposes with lazy content parsing and O(1) property access.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Testing
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class TestResponse
{
    private int $statusCode = 200;
    private array $headers = [];
    private string $content = '';
    private ?array $json = null;

    public function __construct(
        private string $method,
        private string $uri,
        private array $data = [],
        array $headers = []
    ) {
        $this->headers = $headers;
    }

    /**
     * Get response status code.
     *
     * Performance: O(1)
     */
    public function status(): int
    {
        return $this->statusCode;
    }

    /**
     * Set response status code.
     */
    public function setStatus(int $statusCode): self
    {
        $this->statusCode = $statusCode;
        return $this;
    }

    /**
     * Get response content.
     *
     * Performance: O(1)
     */
    public function content(): string
    {
        return $this->content;
    }

    /**
     * Set response content.
     */
    public function setContent(string $content): self
    {
        $this->content = $content;
        $this->json = null; // Reset JSON cache
        return $this;
    }

    /**
     * Get response as JSON array.
     *
     * Performance: O(N) where N = JSON size (cached after first call)
     */
    public function json(): array
    {
        if ($this->json === null) {
            $this->json = json_decode($this->content, true) ?? [];
        }

        return $this->json;
    }

    /**
     * Get response headers.
     *
     * Performance: O(1)
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Get a specific header.
     *
     * Performance: O(1)
     */
    public function header(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }
}

