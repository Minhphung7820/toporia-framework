<?php

declare(strict_types=1);

namespace Toporia\Framework\Http\Middleware;

use Toporia\Framework\Http\Contracts\MiddlewareInterface;
use Toporia\Framework\Http\{Request, Response};
use Toporia\Framework\Routing\Contracts\UrlGeneratorInterface;

/**
 * Class ValidateSignature
 *
 * Validates signed URL signatures. Ensures that URLs with signatures are valid and not expired.
 * Use this on routes that should only be accessible via signed URLs.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Http\Middleware
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class ValidateSignature implements MiddlewareInterface
{
    /**
     * @param UrlGeneratorInterface $url URL generator
     */
    public function __construct(
        private UrlGeneratorInterface $url
    ) {}

    /**
     * Handle the incoming request.
     *
     * @param Request $request
     * @param Response $response
     * @param callable $next
     * @return mixed
     */
    public function handle(Request $request, Response $response, callable $next): mixed
    {
        // Get the full URL with query string
        $fullUrl = $this->url->full();

        // Validate signature
        if (!$this->url->hasValidSignature($fullUrl)) {
            $response->setStatus(403);
            $response->json([
                'error' => 'Invalid or expired signature',
                'message' => 'This URL has an invalid or expired signature.'
            ], 403);
            return null;
        }

        return $next($request, $response);
    }

    /**
     * Create middleware instance for use in routes.
     *
     * @return callable Middleware factory
     */
    public static function middleware(): callable
    {
        return fn($container) => new self($container->get('url'));
    }
}
