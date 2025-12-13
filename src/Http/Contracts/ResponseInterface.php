<?php

declare(strict_types=1);

namespace Toporia\Framework\Http\Contracts;


/**
 * Interface ResponseInterface
 *
 * Contract defining the interface for ResponseInterface implementations in
 * the HTTP request and response handling layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Http\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @internal    This class is a core component and should not be extended
 *              directly unless you know what you're doing.
 */
interface ResponseInterface
{
    /**
     * Set the HTTP status code.
     *
     * @param int $code HTTP status code.
     * @return self
     */
    public function setStatus(int $code): self;

    /**
     * Set a response header.
     *
     * @param string $name Header name.
     * @param string $value Header value.
     * @return self
     */
    public function header(string $name, string $value): self;

    /**
     * Send an HTML response.
     *
     * @param string $content HTML content.
     * @param int $status HTTP status code.
     * @return void
     */
    public function html(string $content, int $status = 200): void;

    /**
     * Send a JSON response.
     *
     * @param mixed $data Data to encode as JSON.
     * @param int $status HTTP status code.
     * @return void
     */
    public function json(mixed $data, int $status = 200): void;

    /**
     * Send a redirect response.
     *
     * @param string $url Target URL.
     * @param int $status HTTP status code (default 302).
     * @return void
     */
    public function redirect(string $url, int $status = 302): void;

    /**
     * Send the response output.
     *
     * @param string $content Response body.
     * @return void
     */
    public function send(string $content): void;

    /**
     * Get the response content.
     *
     * @return string Response body content.
     */
    public function getContent(): string;

    /**
     * Get all response headers.
     *
     * @return array<string, string> Headers as name => value pairs.
     */
    public function getHeaders(): array;

    /**
     * Get the HTTP status code.
     *
     * @return int HTTP status code.
     */
    public function getStatusCode(): int;
}
