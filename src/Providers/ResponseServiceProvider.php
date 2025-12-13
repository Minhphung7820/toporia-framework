<?php

declare(strict_types=1);

namespace Toporia\Framework\Providers;

use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Foundation\ServiceProvider;
use Toporia\Framework\Http\Contracts\ResponseFactoryInterface;
use Toporia\Framework\Http\ResponseFactory;
use Toporia\Framework\Http\Serialization\JsonSerializer;
use Toporia\Framework\Http\Serialization\Contracts\SerializerInterface;

/**
 * Response Service Provider
 *
 * Registers enterprise-grade response services with Toporia compatibility.
 *
 * Services Registered:
 * - ResponseFactory (singleton)
 * - JsonSerializer (singleton)
 * - Response macros and helpers
 *
 * @author      Toporia Framework Team
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Providers
 */
final class ResponseServiceProvider extends ServiceProvider
{
    /**
     * Register response services.
     *
     * @param ContainerInterface $container
     * @return void
     */
    public function register(ContainerInterface $container): void
    {
        // Register JSON Serializer
        $container->singleton(SerializerInterface::class, function () {
            return new JsonSerializer();
        });

        $container->singleton(JsonSerializer::class, function ($c) {
            return $c->get(SerializerInterface::class);
        });

        // Register Response Factory
        $container->singleton(ResponseFactoryInterface::class, function () {
            $config = [
                'json_options' => JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
                'default_headers' => [
                    'X-Powered-By' => 'Toporia Framework',
                    'X-Frame-Options' => 'SAMEORIGIN',
                    'X-Content-Type-Options' => 'nosniff',
                    'X-XSS-Protection' => '1; mode=block'
                ]
            ];

            return new ResponseFactory($config);
        });

        $container->singleton(ResponseFactory::class, function ($c) {
            return $c->get(ResponseFactoryInterface::class);
        });

        // Register response helper alias
        $container->bind('response', function ($c) {
            return $c->get(ResponseFactoryInterface::class);
        });
    }

    /**
     * Boot response services.
     *
     * @param ContainerInterface $container
     * @return void
     */
    public function boot(ContainerInterface $container): void
    {
        // Register response macros
        $this->registerResponseMacros();

        // Register global response helpers
        $this->registerGlobalHelpers($container);
    }

    /**
     * Register response macros for extensibility.
     *
     * @return void
     */
    private function registerResponseMacros(): void
    {
        // Add Toporia-style response macros
        ResponseFactory::macro('api', function (mixed $data = null, string $message = 'Success', int $status = 200) {
            return $this->json([
                'success' => $status < 400,
                'message' => $message,
                'data' => $data,
                'timestamp' => now()->toIso8601String() // ISO 8601 format
            ], $status);
        });

        ResponseFactory::macro('created', function (mixed $data = null, string $message = 'Resource created successfully') {
            return $this->success($data, $message, 201);
        });

        ResponseFactory::macro('accepted', function (mixed $data = null, string $message = 'Request accepted') {
            return $this->success($data, $message, 202);
        });

        ResponseFactory::macro('unauthorized', function (string $message = 'Unauthorized') {
            return $this->error($message, null, 401);
        });

        ResponseFactory::macro('forbidden', function (string $message = 'Forbidden') {
            return $this->error($message, null, 403);
        });

        ResponseFactory::macro('notFound', function (string $message = 'Resource not found') {
            return $this->error($message, null, 404);
        });

        ResponseFactory::macro('validationError', function (array $errors, string $message = 'Validation failed') {
            return $this->error($message, $errors, 422);
        });

        ResponseFactory::macro('serverError', function (string $message = 'Internal server error') {
            return $this->error($message, null, 500);
        });
    }

    /**
     * Register global response helpers.
     *
     * @param ContainerInterface $container
     * @return void
     */
    private function registerGlobalHelpers(ContainerInterface $container): void
    {
        // This would register global helper functions
        // For now, we'll just ensure the response factory is accessible

        if (!function_exists('response')) {
            /**
             * Get the response factory instance.
             *
             * @return ResponseFactoryInterface
             */
            function response(): ResponseFactoryInterface {
                return app()->make(ResponseFactoryInterface::class);
            }
        }
    }
}
