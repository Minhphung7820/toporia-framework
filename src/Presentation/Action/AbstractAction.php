<?php

declare(strict_types=1);

namespace Toporia\Framework\Presentation\Action;

use Toporia\Framework\Presentation\Contracts\ActionInterface;
use Toporia\Framework\Http\{Request, Response};


/**
 * Abstract Class AbstractAction
 *
 * Abstract base class for AbstractAction implementations in the Action
 * layer providing common functionality and contracts.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Action
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
abstract class AbstractAction implements ActionInterface
{
    /**
     * {@inheritdoc}
     */
    final public function __invoke(Request $request, Response $response, mixed ...$vars): mixed
    {
        $this->before($request, $response);
        $result = $this->handle($request, $response, ...$vars);
        $this->after($request, $response, $result);
        return $result;
    }

    /**
     * Handle the main action logic.
     *
     * This is where you:
     * - Extract data from Request
     * - Call Use Case Handlers
     * - Use Responders to format output
     *
     * @param Request $request HTTP request.
     * @param Response $response HTTP response.
     * @param mixed ...$vars Route parameters.
     * @return mixed Response result.
     */
    abstract protected function handle(Request $request, Response $response, mixed ...$vars): mixed;

    /**
     * Execute logic before handling the request.
     *
     * Use this for:
     * - Input validation
     * - Authorization checks
     * - Setting up context
     * - Rate limiting
     *
     * @param Request $request HTTP request.
     * @param Response $response HTTP response.
     * @return void
     * @throws \Exception To stop execution and return error response.
     */
    protected function before(Request $request, Response $response): void
    {
        // Override in child classes if needed
    }

    /**
     * Execute logic after handling the request.
     *
     * Use this for:
     * - Logging
     * - Metrics collection
     * - Cleanup
     * - Cache invalidation
     *
     * @param Request $request HTTP request.
     * @param Response $response HTTP response.
     * @param mixed $result Result from handle().
     * @return void
     */
    protected function after(Request $request, Response $response, mixed $result): void
    {
        // Override in child classes if needed
    }
}
