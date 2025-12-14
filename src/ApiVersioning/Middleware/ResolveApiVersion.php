<?php

declare(strict_types=1);

namespace Toporia\Framework\ApiVersioning\Middleware;

use Toporia\Framework\Http\Middleware\AbstractMiddleware;
use Toporia\Framework\Http\Request;
use Toporia\Framework\Http\Response;
use Toporia\Framework\ApiVersioning\ApiVersion;

/**
 * Class ResolveApiVersion
 *
 * Middleware to resolve and set API version from request.
 *
 * Usage in routes:
 *   $router->group(['middleware' => ['api.version']], function ($router) {
 *       $router->get('/users', [UserController::class, 'index']);
 *   });
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 */
class ResolveApiVersion extends AbstractMiddleware
{
    /**
     * @param bool $addResponseHeaders Add version headers to response
     */
    public function __construct(
        private readonly bool $addResponseHeaders = true
    ) {}

    /**
     * {@inheritdoc}
     */
    protected function process(Request $request, Response $response): ?Response
    {
        // Resolve and set version
        $version = ApiVersion::initialize($request);

        // Store in request attributes
        $request->setAttribute('api_version', $version);

        return null;
    }

    /**
     * {@inheritdoc}
     */
    protected function after(Request $request, Response $response, mixed $result): void
    {
        if (!$this->addResponseHeaders) {
            return;
        }

        // Add version headers to response
        if ($result instanceof Response) {
            $result->header('X-API-Version', ApiVersion::current());

            // Add deprecation headers if applicable
            foreach (ApiVersion::getDeprecationHeaders() as $name => $value) {
                $result->header($name, $value);
            }
        }
    }
}
