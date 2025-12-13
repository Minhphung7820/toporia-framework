<?php

declare(strict_types=1);

namespace Toporia\Framework\Presentation\Contracts;

use Toporia\Framework\Http\{Request, Response};


/**
 * Interface ActionInterface
 *
 * Contract defining the interface for ActionInterface implementations in
 * the Presentation layer (ADR pattern) layer of the Toporia Framework.
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
interface ActionInterface
{
    /**
     * Handle the HTTP request.
     *
     * @param Request $request HTTP request.
     * @param Response $response HTTP response.
     * @param mixed ...$vars Route parameters (e.g., ID from /products/{id}).
     * @return mixed Response result.
     */
    public function __invoke(Request $request, Response $response, mixed ...$vars): mixed;
}
