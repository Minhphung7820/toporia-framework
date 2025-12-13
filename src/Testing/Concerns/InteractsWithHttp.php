<?php

declare(strict_types=1);

namespace Toporia\Framework\Testing\Concerns;

use Toporia\Framework\Foundation\Application;
use Toporia\Framework\Foundation\Bootstrap\{BootProviders, HandleExceptions, RegisterFacades, RegisterProviders};
use Toporia\Framework\Foundation\{LoadConfiguration, LoadEnvironmentVariables};
use Toporia\Framework\Http\Request;
use Toporia\Framework\Routing\Router;
use Toporia\Framework\Testing\TestResponse;


/**
 * Trait InteractsWithHttp
 *
 * Trait providing reusable functionality for InteractsWithHttp in the
 * Concerns layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Concerns
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
trait InteractsWithHttp
{
    /**
     * Cached application instance to avoid re-bootstrapping
     *
     * @var Application|null
     */
    private static ?Application $cachedApp = null;

    /**
     * Make a GET request.
     *
     * Performance: O(1) - Request creation
     */
    protected function getRequest(string $uri, array $headers = []): TestResponse
    {
        return $this->call('GET', $uri, [], $headers);
    }

    /**
     * Make a POST request.
     *
     * Performance: O(1) - Request creation
     */
    protected function post(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->call('POST', $uri, $data, $headers);
    }

    /**
     * Make a PUT request.
     *
     * Performance: O(1) - Request creation
     */
    protected function put(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->call('PUT', $uri, $data, $headers);
    }

    /**
     * Make a PATCH request.
     *
     * Performance: O(1) - Request creation
     */
    protected function patch(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->call('PATCH', $uri, $data, $headers);
    }

    /**
     * Make a DELETE request.
     *
     * Performance: O(1) - Request creation
     */
    protected function delete(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->call('DELETE', $uri, $data, $headers);
    }

    /**
     * Make an HTTP request.
     *
     * Performance: O(1) - Request creation
     */
    protected function call(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        // Use cached app instance if available (performance optimization)
        if (self::$cachedApp === null) {
            // Get or create app instance
            $app = $this->getContainer();

            // If container is not Application instance, bootstrap
            if (!$app instanceof Application) {
                // Create app instance manually to set global before bootstrap
                $basePath = dirname(__DIR__, 4);
                $app = new Application($basePath);

                // Set global app FIRST (before any helpers are called)
                $GLOBALS['app'] = $app;

                // Now bootstrap (this will load helpers, config, etc.)
                // Load environment
                LoadEnvironmentVariables::bootstrap($basePath);

                // Handle exceptions
                HandleExceptions::bootstrap();

                // Load helpers (now $GLOBALS['app'] is set)
                require $basePath . '/bootstrap/helpers.php';

                // Load configuration
                LoadConfiguration::bootstrap($app);

                // Register facades
                RegisterFacades::bootstrap($app);

                // Register providers
                RegisterProviders::bootstrap($app);

                // Boot providers (loads routes)
                BootProviders::bootstrap($app);
            }

            // Cache app instance for performance
            self::$cachedApp = $app;

            // Set container (Application has a container inside)
            $this->setContainer($app->getContainer());
        } else {
            $app = self::$cachedApp;
            // Ensure container is set
            if ($this->getContainer() !== $app->getContainer()) {
                $this->setContainer($app->getContainer());
            }
        }

        // Parse URI
        $parsedUri = parse_url($uri);
        $path = $parsedUri['path'] ?? '/';
        $queryString = $parsedUri['query'] ?? '';

        // Set up $_SERVER for Request::capture()
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['SERVER_NAME'] = 'localhost';
        $_SERVER['SERVER_PORT'] = '8000';
        $_SERVER['HTTPS'] = 'off';

        // Set up $_GET
        $_GET = [];
        if ($queryString) {
            parse_str($queryString, $_GET);
        }

        // Set up $_POST for POST/PUT/PATCH
        $_POST = [];
        if (in_array($method, ['POST', 'PUT', 'PATCH']) && !empty($data)) {
            $_POST = $data;
            // Also set as JSON body
            $_SERVER['CONTENT_TYPE'] = 'application/json';
            $GLOBALS['HTTP_RAW_POST_DATA'] = json_encode($data);
        }

        // Set up headers
        foreach ($headers as $key => $value) {
            $headerKey = 'HTTP_' . strtoupper(str_replace('-', '_', $key));
            $_SERVER[$headerKey] = $value;
        }

        // Capture request
        $request = Request::capture();

        // Get container from app
        $container = $app->getContainer();

        // Bind request to container so Router can access it
        $container->instance('request', $request);
        $container->instance(Request::class, $request);

        // Get router (it's singleton, created during route loading)
        $router = $container->make(Router::class);

        // Update router's request using reflection (router is singleton with old Request)
        $routerReflection = new \ReflectionClass($router);
        $requestProperty = $routerReflection->getProperty('request');
        $requestProperty->setAccessible(true);
        $requestProperty->setValue($router, $request);

        // Get response object from router via reflection (needed to access response after dispatch)
        $responseReflection = new \ReflectionClass($router);
        $responseProperty = $responseReflection->getProperty('response');
        $responseProperty->setAccessible(true);
        $responseObj = $responseProperty->getValue($router);

        // Variable to capture JsonResponse content if it's returned from controller
        $capturedJsonResponse = null;

        // Start output buffering BEFORE dispatch to capture all echo calls
        // Response::send(), JsonResponse::sendResponse(), etc. all use echo
        $initialBufferLevel = ob_get_level();
        ob_start();
        $testBufferLevel = ob_get_level();

        try {
            $router->dispatch();

            // Get content from output buffer
            // JsonResponse::sendResponse() -> send() -> echo, so it should be captured
            // Use ob_get_clean() to get content and clean buffer in one call
            $content = '';
            if (ob_get_level() >= $testBufferLevel) {
                $bufferContent = ob_get_clean();
                if ($bufferContent !== false && $bufferContent !== '') {
                    $content = $bufferContent;
                }
            }

            // Clean up any remaining nested buffers (but not initial ones)
            // These might be created by framework or middleware
            while (ob_get_level() > $initialBufferLevel) {
                $nestedContent = ob_get_clean();
                if ($nestedContent !== false && $nestedContent !== '') {
                    $content .= $nestedContent;
                }
            }

            $content = trim($content);

            // If still empty, JsonResponse might have been sent but not captured in buffer
            // This can happen if output buffering was disabled or if JsonResponse was sent differently
            // Try to get from router's response object as fallback
            if (empty($content) && $responseObj) {
                if (method_exists($responseObj, 'getContent')) {
                    $responseContent = $responseObj->getContent();
                    if (!empty($responseContent)) {
                        $content = $responseContent;
                    }
                }
            }

            // Get status code from Response object
            $statusCode = 200;
            if ($responseObj && method_exists($responseObj, 'getStatusCode')) {
                $statusCode = $responseObj->getStatusCode() ?: 200;
            }

            // Create TestResponse
            $testResponse = new TestResponse($method, $uri, $data, $headers);
            $testResponse->setStatus($statusCode);
            $testResponse->setContent($content);

            return $testResponse;
        } catch (\Throwable $e) {
            // Clean up only our test buffer
            if (ob_get_level() >= $testBufferLevel) {
                ob_end_clean();
            }

            // Return error response
            $testResponse = new TestResponse($method, $uri, $data, $headers);
            $testResponse->setStatus(500);
            $testResponse->setContent(json_encode([
                'success' => false,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]));
            return $testResponse;
        }
    }

    /**
     * Assert that the response has a given status code.
     *
     * Performance: O(1) - Direct comparison
     */
    protected function assertStatus(TestResponse $response, int $expected): void
    {
        $this->assertEquals($expected, $response->status(), "Expected status code {$expected}, got {$response->status()}");
    }

    /**
     * Assert that the response is successful (2xx).
     *
     * Performance: O(1) - Range check
     */
    protected function assertSuccessful(TestResponse $response): void
    {
        $status = $response->status();
        $this->assertGreaterThanOrEqual(200, $status, "Expected successful status, got {$status}");
        $this->assertLessThan(300, $status, "Expected successful status, got {$status}");
    }

    /**
     * Assert that the response contains JSON.
     *
     * Performance: O(N) where N = JSON size
     */
    protected function assertJsonResponse(TestResponse $response, array $data = null): void
    {
        $this->assertJsonStructure($response);

        if ($data !== null) {
            $this->assertJsonContains($response, $data);
        }
    }

    /**
     * Assert JSON structure.
     */
    protected function assertJsonStructure(TestResponse $response): void
    {
        $content = $response->content();
        $decoded = json_decode($content, true);
        $this->assertNotNull($decoded, "Response is not valid JSON");
    }

    /**
     * Assert JSON contains data.
     */
    protected function assertJsonContains(TestResponse $response, array $data): void
    {
        $content = $response->content();
        $decoded = json_decode($content, true);

        foreach ($data as $key => $value) {
            $this->assertArrayHasKey($key, $decoded, "JSON does not contain key: {$key}");
            $this->assertEquals($value, $decoded[$key], "JSON value mismatch for key: {$key}");
        }
    }
}
