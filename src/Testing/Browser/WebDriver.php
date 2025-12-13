<?php

declare(strict_types=1);

namespace Toporia\Framework\Testing\Browser;

/**
 * Class WebDriver
 *
 * WebDriver protocol implementation for browser automation with support for ChromeDriver, GeckoDriver, and other WebDriver-compatible drivers.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Testing\Browser
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class WebDriver
{
    /**
     * The WebDriver server URL.
     *
     * @var string
     */
    protected string $serverUrl;

    /**
     * The session ID.
     *
     * @var string|null
     */
    protected ?string $sessionId = null;

    /**
     * HTTP client timeout.
     *
     * @var int
     */
    protected int $timeout = 30;

    /**
     * Implicit wait timeout in milliseconds.
     *
     * @var int
     */
    protected int $implicitWait = 0;

    /**
     * Create a new WebDriver instance.
     *
     * @param string $serverUrl WebDriver server URL (e.g., http://localhost:9515)
     */
    public function __construct(string $serverUrl = 'http://localhost:9515')
    {
        $this->serverUrl = rtrim($serverUrl, '/');
    }

    /**
     * Create a new browser session.
     *
     * @param array<string, mixed> $capabilities
     * @return static
     */
    public function createSession(array $capabilities = []): static
    {
        $defaultCapabilities = [
            'capabilities' => [
                'alwaysMatch' => [
                    'browserName' => 'chrome',
                    'goog:chromeOptions' => [
                        'args' => ['--disable-gpu', '--no-sandbox'],
                    ],
                ],
            ],
        ];

        $capabilities = array_merge_recursive($defaultCapabilities, $capabilities);

        $response = $this->sendCommand('POST', '/session', $capabilities);

        $this->sessionId = $response['value']['sessionId'] ?? $response['sessionId'] ?? null;

        if ($this->sessionId === null) {
            throw new \RuntimeException('Failed to create WebDriver session');
        }

        return $this;
    }

    /**
     * Navigate to a URL.
     *
     * @param string $url
     * @return static
     */
    public function navigate(string $url): static
    {
        $this->sendSessionCommand('POST', '/url', ['url' => $url]);

        return $this;
    }

    /**
     * Get the current URL.
     *
     * @return string
     */
    public function getCurrentUrl(): string
    {
        $response = $this->sendSessionCommand('GET', '/url');

        return $response['value'] ?? '';
    }

    /**
     * Refresh the page.
     *
     * @return static
     */
    public function refresh(): static
    {
        $this->sendSessionCommand('POST', '/refresh');

        return $this;
    }

    /**
     * Navigate back.
     *
     * @return static
     */
    public function back(): static
    {
        $this->sendSessionCommand('POST', '/back');

        return $this;
    }

    /**
     * Navigate forward.
     *
     * @return static
     */
    public function forward(): static
    {
        $this->sendSessionCommand('POST', '/forward');

        return $this;
    }

    /**
     * Get page title.
     *
     * @return string
     */
    public function getTitle(): string
    {
        $response = $this->sendSessionCommand('GET', '/title');

        return $response['value'] ?? '';
    }

    /**
     * Get page source.
     *
     * @return string
     */
    public function getPageSource(): string
    {
        $response = $this->sendSessionCommand('GET', '/source');

        return $response['value'] ?? '';
    }

    /**
     * Find an element.
     *
     * @param string $strategy Location strategy (css selector, xpath, id, etc.)
     * @param string $selector Selector value
     * @return string Element ID
     */
    public function findElement(string $strategy, string $selector): string
    {
        $response = $this->sendSessionCommand('POST', '/element', [
            'using' => $strategy,
            'value' => $selector,
        ]);

        $element = $response['value'] ?? [];

        // WebDriver returns element ID in different formats
        return $element['element-6066-11e4-a52e-4f735466cecf']
            ?? $element['ELEMENT']
            ?? '';
    }

    /**
     * Find multiple elements.
     *
     * @param string $strategy
     * @param string $selector
     * @return array<string>
     */
    public function findElements(string $strategy, string $selector): array
    {
        $response = $this->sendSessionCommand('POST', '/elements', [
            'using' => $strategy,
            'value' => $selector,
        ]);

        $elements = $response['value'] ?? [];
        $ids = [];

        foreach ($elements as $element) {
            $ids[] = $element['element-6066-11e4-a52e-4f735466cecf']
                ?? $element['ELEMENT']
                ?? '';
        }

        return array_filter($ids);
    }

    /**
     * Click an element.
     *
     * @param string $elementId
     * @return static
     */
    public function click(string $elementId): static
    {
        $this->sendSessionCommand('POST', "/element/{$elementId}/click");

        return $this;
    }

    /**
     * Type text into an element.
     *
     * @param string $elementId
     * @param string $text
     * @return static
     */
    public function type(string $elementId, string $text): static
    {
        $this->sendSessionCommand('POST', "/element/{$elementId}/value", [
            'text' => $text,
        ]);

        return $this;
    }

    /**
     * Clear an element.
     *
     * @param string $elementId
     * @return static
     */
    public function clear(string $elementId): static
    {
        $this->sendSessionCommand('POST', "/element/{$elementId}/clear");

        return $this;
    }

    /**
     * Get element text.
     *
     * @param string $elementId
     * @return string
     */
    public function getText(string $elementId): string
    {
        $response = $this->sendSessionCommand('GET', "/element/{$elementId}/text");

        return $response['value'] ?? '';
    }

    /**
     * Get element attribute.
     *
     * @param string $elementId
     * @param string $attribute
     * @return string|null
     */
    public function getAttribute(string $elementId, string $attribute): ?string
    {
        $response = $this->sendSessionCommand(
            'GET',
            "/element/{$elementId}/attribute/{$attribute}"
        );

        return $response['value'] ?? null;
    }

    /**
     * Get element property.
     *
     * @param string $elementId
     * @param string $property
     * @return mixed
     */
    public function getProperty(string $elementId, string $property): mixed
    {
        $response = $this->sendSessionCommand(
            'GET',
            "/element/{$elementId}/property/{$property}"
        );

        return $response['value'] ?? null;
    }

    /**
     * Check if element is displayed.
     *
     * @param string $elementId
     * @return bool
     */
    public function isDisplayed(string $elementId): bool
    {
        $response = $this->sendSessionCommand('GET', "/element/{$elementId}/displayed");

        return $response['value'] ?? false;
    }

    /**
     * Check if element is enabled.
     *
     * @param string $elementId
     * @return bool
     */
    public function isEnabled(string $elementId): bool
    {
        $response = $this->sendSessionCommand('GET', "/element/{$elementId}/enabled");

        return $response['value'] ?? false;
    }

    /**
     * Check if element is selected.
     *
     * @param string $elementId
     * @return bool
     */
    public function isSelected(string $elementId): bool
    {
        $response = $this->sendSessionCommand('GET', "/element/{$elementId}/selected");

        return $response['value'] ?? false;
    }

    /**
     * Take a screenshot.
     *
     * @param string $path
     * @return static
     */
    public function takeScreenshot(string $path): static
    {
        $response = $this->sendSessionCommand('GET', '/screenshot');
        $data = $response['value'] ?? '';

        file_put_contents($path, base64_decode($data));

        return $this;
    }

    /**
     * Execute JavaScript.
     *
     * @param string $script
     * @param array<mixed> $args
     * @return mixed
     */
    public function executeScript(string $script, array $args = []): mixed
    {
        $response = $this->sendSessionCommand('POST', '/execute/sync', [
            'script' => $script,
            'args' => $args,
        ]);

        return $response['value'] ?? null;
    }

    /**
     * Execute async JavaScript.
     *
     * @param string $script
     * @param array<mixed> $args
     * @return mixed
     */
    public function executeAsyncScript(string $script, array $args = []): mixed
    {
        $response = $this->sendSessionCommand('POST', '/execute/async', [
            'script' => $script,
            'args' => $args,
        ]);

        return $response['value'] ?? null;
    }

    /**
     * Set window size.
     *
     * @param int $width
     * @param int $height
     * @return static
     */
    public function setWindowSize(int $width, int $height): static
    {
        $this->sendSessionCommand('POST', '/window/rect', [
            'width' => $width,
            'height' => $height,
        ]);

        return $this;
    }

    /**
     * Maximize window.
     *
     * @return static
     */
    public function maximizeWindow(): static
    {
        $this->sendSessionCommand('POST', '/window/maximize');

        return $this;
    }

    /**
     * Set cookie.
     *
     * @param string $name
     * @param string $value
     * @param array<string, mixed> $options
     * @return static
     */
    public function setCookie(string $name, string $value, array $options = []): static
    {
        $cookie = array_merge(['name' => $name, 'value' => $value], $options);

        $this->sendSessionCommand('POST', '/cookie', ['cookie' => $cookie]);

        return $this;
    }

    /**
     * Get cookie.
     *
     * @param string $name
     * @return string|null
     */
    public function getCookie(string $name): ?string
    {
        $response = $this->sendSessionCommand('GET', "/cookie/{$name}");

        return $response['value']['value'] ?? null;
    }

    /**
     * Delete cookie.
     *
     * @param string $name
     * @return static
     */
    public function deleteCookie(string $name): static
    {
        $this->sendSessionCommand('DELETE', "/cookie/{$name}");

        return $this;
    }

    /**
     * Delete all cookies.
     *
     * @return static
     */
    public function deleteAllCookies(): static
    {
        $this->sendSessionCommand('DELETE', '/cookie');

        return $this;
    }

    /**
     * Switch to frame.
     *
     * @param string|int|null $frame
     * @return static
     */
    public function switchToFrame(string|int|null $frame): static
    {
        $id = $frame;

        if (is_string($frame)) {
            $id = ['element-6066-11e4-a52e-4f735466cecf' => $frame];
        }

        $this->sendSessionCommand('POST', '/frame', ['id' => $id]);

        return $this;
    }

    /**
     * Switch to default content.
     *
     * @return static
     */
    public function switchToDefaultContent(): static
    {
        return $this->switchToFrame(null);
    }

    /**
     * Accept alert.
     *
     * @return static
     */
    public function acceptAlert(): static
    {
        $this->sendSessionCommand('POST', '/alert/accept');

        return $this;
    }

    /**
     * Dismiss alert.
     *
     * @return static
     */
    public function dismissAlert(): static
    {
        $this->sendSessionCommand('POST', '/alert/dismiss');

        return $this;
    }

    /**
     * Send text to alert.
     *
     * @param string $text
     * @return static
     */
    public function sendAlertText(string $text): static
    {
        $this->sendSessionCommand('POST', '/alert/text', ['text' => $text]);

        return $this;
    }

    /**
     * Get console logs.
     *
     * @return array<string>
     */
    public function getConsoleLogs(): array
    {
        try {
            $response = $this->sendSessionCommand('POST', '/log', ['type' => 'browser']);

            return array_map(
                fn($entry) => $entry['message'] ?? '',
                $response['value'] ?? []
            );
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Set implicit wait timeout.
     *
     * @param int $milliseconds
     * @return static
     */
    public function setImplicitWait(int $milliseconds): static
    {
        $this->implicitWait = $milliseconds;

        $this->sendSessionCommand('POST', '/timeouts', [
            'implicit' => $milliseconds,
        ]);

        return $this;
    }

    /**
     * Quit the session.
     *
     * @return void
     */
    public function quit(): void
    {
        if ($this->sessionId !== null) {
            $this->sendSessionCommand('DELETE', '');
            $this->sessionId = null;
        }
    }

    /**
     * Send a command to the WebDriver server.
     *
     * @param string $method
     * @param string $endpoint
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function sendCommand(string $method, string $endpoint, array $data = []): array
    {
        $url = $this->serverUrl . $endpoint;

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);

        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException("WebDriver request failed: {$error}");
        }

        $decoded = json_decode($response, true);

        if ($httpCode >= 400) {
            $message = $decoded['value']['message'] ?? 'Unknown WebDriver error';
            throw new \RuntimeException("WebDriver error: {$message}");
        }

        return $decoded ?? [];
    }

    /**
     * Send a command within the current session.
     *
     * @param string $method
     * @param string $endpoint
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function sendSessionCommand(string $method, string $endpoint, array $data = []): array
    {
        if ($this->sessionId === null) {
            throw new \RuntimeException('No active WebDriver session');
        }

        return $this->sendCommand($method, "/session/{$this->sessionId}{$endpoint}", $data);
    }

    /**
     * Get the session ID.
     *
     * @return string|null
     */
    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }
}
