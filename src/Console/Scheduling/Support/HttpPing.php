<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Scheduling\Support;

use Toporia\Framework\Http\Contracts\HttpClientInterface;

/**
 * Class HttpPing
 *
 * Utility class for sending HTTP pings. Provides O(1) per ping
 * performance with non-blocking execution and lightweight cURL implementation.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Console\Scheduling
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class HttpPing
{
    /**
     * Send HTTP ping request.
     *
     * Non-blocking: Does not wait for response, fire-and-forget.
     * Performance: O(1) - Single HTTP request
     *
     * @param string $url URL to ping
     * @param array $data Optional data to send
     * @param HttpClientInterface|null $client Optional HTTP client
     * @return void
     */
    public static function send(string $url, array $data = [], ?HttpClientInterface $client = null): void
    {
        if ($client !== null) {
            // Use provided client (for dependency injection)
            try {
                $client->post($url, $data);
            } catch (\Throwable $e) {
                // Silent fail - ping should not break task execution
                error_log("Schedule ping failed: {$e->getMessage()}");
            }
            return;
        }

        // Fallback: Use cURL directly (fire-and-forget)
        self::sendAsync($url, $data);
    }

    /**
     * Send async HTTP ping (fire-and-forget).
     *
     * Uses background cURL execution to avoid blocking.
     * Includes proper error handling for cURL operations.
     *
     * @param string $url
     * @param array $data
     * @return void
     */
    private static function sendAsync(string $url, array $data): void
    {
        // Check if cURL extension is available
        if (!function_exists('curl_init')) {
            error_log("Schedule ping skipped: cURL extension not available");
            return;
        }

        $ch = curl_init();

        // curl_init can return false on failure
        if ($ch === false) {
            error_log("Schedule ping failed: curl_init() returned false");
            return;
        }

        $jsonData = json_encode($data);
        if ($jsonData === false) {
            error_log("Schedule ping failed: JSON encode error - " . json_last_error_msg());
            curl_close($ch);
            return;
        }

        $optionsSet = curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5, // Short timeout
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'User-Agent: Toporia-Schedule/1.0'
            ],
        ]);

        if (!$optionsSet) {
            error_log("Schedule ping failed: curl_setopt_array() failed");
            curl_close($ch);
            return;
        }

        // Execute and check for errors
        $result = curl_exec($ch);

        if ($result === false) {
            $error = curl_error($ch);
            error_log("Schedule ping failed for {$url}: {$error}");
        }

        curl_close($ch);
    }
}
