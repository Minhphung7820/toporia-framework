<?php

declare(strict_types=1);

use Toporia\Framework\Foundation\Application;
use Toporia\Framework\Http\Contracts\{JsonResponseInterface, RedirectResponseInterface, ResponseFactoryInterface};
use Toporia\Framework\Http\Exceptions\HttpException;
use Toporia\Framework\Http\Request;
use Toporia\Framework\Support\HigherOrderTapProxy;
use Toporia\Framework\Support\Optional;

/**
 * Toporia Framework Helper Functions
 *
 * Collection of global helper functions for common operations
 * including application access, responses, and utility functions.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Support
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */

if (!function_exists('app')) {
    /**
     * Get the application instance or resolve a service from the container.
     *
     * @param string|null $abstract Service identifier to resolve
     * @param \Toporia\Framework\Foundation\Application|null $instance Set application instance
     * @return mixed Application instance or resolved service
     */
    function app(?string $abstract = null, ?Application $instance = null): mixed
    {
        static $application = null;

        // Set application instance if provided
        if ($instance !== null) {
            $application = $instance;
        }

        // Try to get from static variable first
        if ($application === null) {
            // Try to get from global variable as fallback
            $application = $GLOBALS['app'] ?? null;

            if ($application === null) {
                throw new \RuntimeException('Application instance not found. Make sure the application is properly bootstrapped.');
            }
        }

        if ($abstract === null) {
            return $application;
        }

        return $application->make($abstract);
    }
}

if (!function_exists('container')) {
    /**
     * Get a service from the container.
     *
     * @param string $abstract Service identifier
     * @return mixed Resolved service
     */
    function container(string $abstract): mixed
    {
        return app($abstract);
    }
}


if (!function_exists('response')) {
    /**
     * Get the response factory instance or create a response.
     *
     * @param mixed $content Response content
     * @param int $status HTTP status code
     * @param array<string, string> $headers Response headers
     * @return \Toporia\Framework\Http\Contracts\ResponseFactoryInterface|\Toporia\Framework\Http\Contracts\ResponseInterface
     */
    function response(mixed $content = null, int $status = 200, array $headers = []): mixed
    {
        $factory = app()->make(ResponseFactoryInterface::class);

        if (func_num_args() === 0) {
            return $factory;
        }

        return $factory->make($content, $status, $headers);
    }
}

if (!function_exists('json_response')) {
    /**
     * Create a JSON response.
     *
     * @param mixed $data Response data
     * @param int $status HTTP status code
     * @param array<string, string> $headers Response headers
     * @return \Toporia\Framework\Http\Contracts\JsonResponseInterface
     */
    function json_response(mixed $data = null, int $status = 200, array $headers = []): JsonResponseInterface
    {
        return response()->json($data, $status, $headers);
    }
}

if (!function_exists('redirect')) {
    /**
     * Create a redirect response.
     *
     * @param string $to Target URL
     * @param int $status HTTP status code
     * @param array<string, string> $headers Response headers
     * @return \Toporia\Framework\Http\Contracts\RedirectResponseInterface
     */
    function redirect(string $to, int $status = 302, array $headers = []): RedirectResponseInterface
    {
        return response()->redirectTo($to, $status, $headers);
    }
}

if (!function_exists('request')) {
    /**
     * Get the current request instance.
     *
     * @return \Toporia\Framework\Http\Request
     */
    function request(): Request
    {
        return app()->make(Request::class);
    }
}

if (!function_exists('abort')) {
    /**
     * Throw an HTTP exception with the given status code.
     *
     * @param int $code HTTP status code
     * @param string $message Error message
     * @param array<string, string> $headers Response headers
     * @return never
     * @throws \Toporia\Framework\Http\Exceptions\HttpException
     */
    function abort(int $code, string $message = '', array $headers = []): never
    {
        throw new HttpException(
            $code,
            $message ?: "HTTP {$code} Error",
            $headers
        );
    }
}

if (!function_exists('abort_if')) {
    /**
     * Throw an HTTP exception if the given condition is true.
     *
     * @param bool $condition Condition to check
     * @param int $code HTTP status code
     * @param string $message Error message
     * @param array<string, string> $headers Response headers
     * @return void
     * @throws \Toporia\Framework\Http\Exceptions\HttpException
     */
    function abort_if(bool $condition, int $code, string $message = '', array $headers = []): void
    {
        if ($condition) {
            abort($code, $message, $headers);
        }
    }
}

if (!function_exists('abort_unless')) {
    /**
     * Throw an HTTP exception unless the given condition is true.
     *
     * @param bool $condition Condition to check
     * @param int $code HTTP status code
     * @param string $message Error message
     * @param array<string, string> $headers Response headers
     * @return void
     * @throws \Toporia\Framework\Http\Exceptions\HttpException
     */
    function abort_unless(bool $condition, int $code, string $message = '', array $headers = []): void
    {
        if (!$condition) {
            abort($code, $message, $headers);
        }
    }
}

if (!function_exists('tap')) {
    /**
     * Call the given Closure with the given value then return the value.
     *
     * @template TValue
     * @param TValue $value
     * @param callable(TValue): void|null $callback
     * @return TValue
     */
    function tap(mixed $value, ?callable $callback = null): mixed
    {
        if ($callback === null) {
            return new HigherOrderTapProxy($value);
        }

        $callback($value);

        return $value;
    }
}

if (!function_exists('value')) {
    /**
     * Return the value or execute the closure.
     *
     * @template TValue
     * @param TValue|(\Closure(): TValue) $value
     * @param mixed ...$args
     * @return TValue
     */
    function value(mixed $value, mixed ...$args): mixed
    {
        return $value instanceof \Closure ? $value(...$args) : $value;
    }
}

if (!function_exists('with')) {
    /**
     * Return the given value, optionally passed through the given callback.
     *
     * @template TValue
     * @template TReturn
     * @param TValue $value
     * @param callable(TValue): TReturn|null $callback
     * @return TValue|TReturn
     */
    function with(mixed $value, ?callable $callback = null): mixed
    {
        return $callback === null ? $value : $callback($value);
    }
}

if (!function_exists('transform')) {
    /**
     * Transform the given value if it is present.
     *
     * @template TValue
     * @template TReturn
     * @template TDefault
     * @param TValue $value
     * @param callable(TValue): TReturn $callback
     * @param TDefault|callable(): TDefault $default
     * @return TReturn|TDefault
     */
    function transform(mixed $value, callable $callback, mixed $default = null): mixed
    {
        if (filled($value)) {
            return $callback($value);
        }

        return value($default);
    }
}

if (!function_exists('blank')) {
    /**
     * Determine if the given value is "blank".
     *
     * @param mixed $value
     * @return bool
     */
    function blank(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_numeric($value) || is_bool($value)) {
            return false;
        }

        if ($value instanceof \Countable) {
            return count($value) === 0;
        }

        if (is_array($value)) {
            return empty($value);
        }

        return empty($value);
    }
}

if (!function_exists('filled')) {
    /**
     * Determine if the given value is "filled".
     *
     * @param mixed $value
     * @return bool
     */
    function filled(mixed $value): bool
    {
        return !blank($value);
    }
}

if (!function_exists('optional')) {
    /**
     * Provide access to optional objects.
     *
     * @template TValue
     * @param TValue|null $value
     * @param callable(TValue): mixed|null $callback
     * @return mixed
     */
    function optional(mixed $value = null, ?callable $callback = null): mixed
    {
        if ($callback === null) {
            return new Optional($value);
        }

        if ($value !== null) {
            return $callback($value);
        }

        return null;
    }
}

if (!function_exists('retry')) {
    /**
     * Retry an operation a given number of times.
     *
     * @template TReturn
     * @param int $times
     * @param callable(): TReturn $callback
     * @param int $sleepMilliseconds
     * @param callable(\Throwable): bool|null $when
     * @return TReturn
     * @throws \Throwable
     */
    function retry(int $times, callable $callback, int $sleepMilliseconds = 0, ?callable $when = null): mixed
    {
        $attempts = 0;
        $lastException = null;

        while ($attempts < $times) {
            $attempts++;

            try {
                return $callback();
            } catch (\Throwable $e) {
                $lastException = $e;

                if ($when !== null && !$when($e)) {
                    throw $e;
                }

                if ($attempts < $times && $sleepMilliseconds > 0) {
                    usleep($sleepMilliseconds * 1000);
                }
            }
        }

        throw $lastException;
    }
}

if (!function_exists('rescue')) {
    /**
     * Catch a potential exception and return a default value.
     *
     * @template TReturn
     * @template TDefault
     * @param callable(): TReturn $callback
     * @param TDefault|callable(\Throwable): TDefault $rescue
     * @param bool $report
     * @return TReturn|TDefault
     */
    function rescue(callable $callback, mixed $rescue = null, bool $report = true): mixed
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            if ($report) {
                error_log("Rescued exception: " . $e->getMessage());
            }

            return value($rescue, $e);
        }
    }
}

if (!function_exists('throw_if')) {
    /**
     * Throw the given exception if the given condition is true.
     *
     * @template TValue
     * @param TValue $condition
     * @param \Throwable|class-string<\Throwable>|string $exception
     * @param mixed ...$parameters
     * @return TValue
     * @throws \Throwable
     */
    function throw_if(mixed $condition, \Throwable|string $exception = 'RuntimeException', mixed ...$parameters): mixed
    {
        if ($condition) {
            if (is_string($exception) && class_exists($exception)) {
                $exception = new $exception(...$parameters);
            } elseif (is_string($exception)) {
                $exception = new \RuntimeException($exception);
            }

            throw $exception;
        }

        return $condition;
    }
}

if (!function_exists('throw_unless')) {
    /**
     * Throw the given exception unless the given condition is true.
     *
     * @template TValue
     * @param TValue $condition
     * @param \Throwable|class-string<\Throwable>|string $exception
     * @param mixed ...$parameters
     * @return TValue
     * @throws \Throwable
     */
    function throw_unless(mixed $condition, \Throwable|string $exception = 'RuntimeException', mixed ...$parameters): mixed
    {
        throw_if(!$condition, $exception, ...$parameters);

        return $condition;
    }
}
