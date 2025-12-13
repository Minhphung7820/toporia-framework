<?php

declare(strict_types=1);

namespace Toporia\Framework\Presentation\Responder;

use Toporia\Framework\Presentation\Contracts\ResponderInterface;
use Toporia\Framework\Http\Response;


/**
 * Abstract Class AbstractResponder
 *
 * Abstract base class for AbstractResponder implementations in the
 * Responder layer providing common functionality and contracts.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Responder
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
abstract class AbstractResponder implements ResponderInterface
{
    /**
     * {@inheritdoc}
     */
    public function success(Response $response, mixed $data, int $status = 200): void
    {
        $this->json($response, $data, $status);
    }

    /**
     * {@inheritdoc}
     */
    public function error(Response $response, string $message, int $status = 400, array $details = []): void
    {
        $payload = array_merge([
            'error' => $message,
            'status' => $status
        ], $details);

        $this->json($response, $payload, $status);
    }

    /**
     * Send a JSON response.
     *
     * @param Response $response HTTP response object.
     * @param array|object $data Data to encode.
     * @param int $status HTTP status code.
     * @return void
     */
    protected function json(Response $response, array|object $data, int $status = 200): void
    {
        $response->json(is_array($data) ? $data : (array) $data, $status);
    }

    /**
     * Send an HTML response.
     *
     * @param Response $response HTTP response object.
     * @param string $html HTML content.
     * @param int $status HTTP status code.
     * @return void
     */
    protected function html(Response $response, string $html, int $status = 200): void
    {
        $response->html($html, $status);
    }

    /**
     * Send a 201 Created response.
     *
     * @param Response $response HTTP response object.
     * @param mixed $data Created resource data.
     * @return void
     */
    public function created(Response $response, mixed $data): void
    {
        $this->json($response, is_array($data) ? $data : (array) $data, 201);
    }

    /**
     * Send a 204 No Content response.
     *
     * @param Response $response HTTP response object.
     * @return void
     */
    public function noContent(Response $response): void
    {
        $response->noContent();
    }

    /**
     * Send a 404 Not Found response.
     *
     * @param Response $response HTTP response object.
     * @param string $message Error message.
     * @return void
     */
    public function notFound(Response $response, string $message = 'Resource not found'): void
    {
        $this->error($response, $message, 404);
    }

    /**
     * Send a 403 Forbidden response.
     *
     * @param Response $response HTTP response object.
     * @param string $message Error message.
     * @return void
     */
    public function forbidden(Response $response, string $message = 'Forbidden'): void
    {
        $this->error($response, $message, 403);
    }

    /**
     * Send a 401 Unauthorized response.
     *
     * @param Response $response HTTP response object.
     * @param string $message Error message.
     * @return void
     */
    public function unauthorized(Response $response, string $message = 'Unauthorized'): void
    {
        $this->error($response, $message, 401);
    }

    /**
     * Send a 422 Unprocessable Entity response (validation errors).
     *
     * @param Response $response HTTP response object.
     * @param array $errors Validation errors.
     * @return void
     */
    public function validationError(Response $response, array $errors): void
    {
        $this->error($response, 'Validation failed', 422, ['errors' => $errors]);
    }

    /**
     * Send an RFC 7807 Problem Details response.
     *
     * @param Response $response HTTP response object.
     * @param int $status HTTP status code.
     * @param string $title Short description of the problem.
     * @param string|null $detail Detailed explanation.
     * @param array $extra Additional problem-specific fields.
     * @return void
     */
    public function problem(
        Response $response,
        int $status,
        string $title,
        ?string $detail = null,
        array $extra = []
    ): void {
        $payload = array_merge([
            'type' => 'about:blank',
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
        ], $extra);

        $response->header('Content-Type', 'application/problem+json');
        $this->json($response, $payload, $status);
    }

    /**
     * Send a redirect response.
     *
     * @param Response $response HTTP response object.
     * @param string $url Target URL.
     * @param int $status HTTP status code (302 or 301).
     * @return void
     */
    protected function redirect(Response $response, string $url, int $status = 302): void
    {
        $response->redirect($url, $status);
    }
}
