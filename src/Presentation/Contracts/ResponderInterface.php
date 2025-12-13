<?php

declare(strict_types=1);

namespace Toporia\Framework\Presentation\Contracts;

use Toporia\Framework\Http\Response;


/**
 * Interface ResponderInterface
 *
 * Contract defining the interface for ResponderInterface implementations
 * in the Presentation layer (ADR pattern) layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Presentation\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface ResponderInterface
{
    /**
     * Send a successful response.
     *
     * @param Response $response HTTP response object.
     * @param mixed $data Data to send.
     * @param int $status HTTP status code.
     * @return void
     */
    public function success(Response $response, mixed $data, int $status = 200): void;

    /**
     * Send an error response.
     *
     * @param Response $response HTTP response object.
     * @param string $message Error message.
     * @param int $status HTTP status code.
     * @param array $details Additional error details.
     * @return void
     */
    public function error(Response $response, string $message, int $status = 400, array $details = []): void;
}
