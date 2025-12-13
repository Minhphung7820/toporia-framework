<?php

declare(strict_types=1);

namespace Toporia\Framework\Http;

use Toporia\Framework\Http\Contracts\RedirectResponseInterface;
use Toporia\Framework\Session\Store;
use Toporia\Framework\Support\Macroable;

/**
 * Class RedirectResponse
 *
 * Toporia-style redirect response with session flash data support.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Http
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class RedirectResponse extends Response implements RedirectResponseInterface
{
    use Macroable;

    /**
     * @var string Target URL for redirection
     */
    private string $targetUrl;

    /**
     * @var array<string, mixed> Flash data for session
     */
    private array $flashData = [];

    /**
     * Create a new redirect response.
     *
     * @param string $url Target URL
     * @param int $status HTTP status code
     * @param array<string, string> $headers Response headers
     */
    public function __construct(string $url, int $status = 302, array $headers = [])
    {
        parent::__construct('', $status, $headers);

        $this->setTargetUrl($url);
    }

    /**
     * {@inheritdoc}
     */
    public function getTargetUrl(): string
    {
        return $this->targetUrl;
    }

    /**
     * {@inheritdoc}
     */
    public function setTargetUrl(string $url): static
    {
        // SECURITY: Validate URL to prevent open redirect attacks
        $this->validateRedirectUrl($url);

        $this->targetUrl = $url;
        $this->header('Location', $url);

        return $this;
    }

    /**
     * Validate redirect URL to prevent open redirect attacks.
     *
     * SECURITY: Prevents redirects to:
     * - JavaScript URLs (XSS)
     * - Data URLs
     * - Protocol-relative URLs that could redirect to external sites
     * - External domains (unless explicitly allowed)
     *
     * @param string $url URL to validate
     * @throws \InvalidArgumentException If URL is invalid or dangerous
     */
    private function validateRedirectUrl(string $url): void
    {
        // Block dangerous protocols (XSS via javascript:, data:, vbscript:)
        if (preg_match('/^(javascript|data|vbscript):/i', $url)) {
            throw new \InvalidArgumentException('Invalid redirect URL: dangerous protocol detected');
        }

        // Block protocol-relative URLs (//evil.com could redirect externally)
        if (str_starts_with($url, '//')) {
            throw new \InvalidArgumentException('Invalid redirect URL: protocol-relative URLs are not allowed');
        }

        // Allow relative URLs (starting with / or not starting with a scheme)
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return; // Safe relative URL
        }

        // For absolute URLs, validate the scheme
        $parsed = parse_url($url);
        if ($parsed === false) {
            throw new \InvalidArgumentException('Invalid redirect URL: malformed URL');
        }

        // If there's a scheme, it must be http or https
        if (isset($parsed['scheme'])) {
            $scheme = strtolower($parsed['scheme']);
            if (!in_array($scheme, ['http', 'https'], true)) {
                throw new \InvalidArgumentException("Invalid redirect URL: only http and https schemes are allowed");
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function with(string $key, mixed $value): static
    {
        $this->flashData[$key] = $value;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function withInput(?array $input = null): static
    {
        $input = $input ?? $_POST ?? [];

        return $this->with('_old_input', $input);
    }

    /**
     * {@inheritdoc}
     */
    public function withErrors(array|string $errors): static
    {
        if (is_string($errors)) {
            $errors = ['error' => $errors];
        }

        return $this->with('errors', $errors);
    }

    /**
     * Send the response.
     *
     * @param string $content Content to send (required by parent)
     * @return void
     */
    public function send(string $content = ''): void
    {
        // Flash data to session before redirect
        $this->flashToSession();

        // For redirects, we don't need content, just headers
        parent::send($content);
    }

    /**
     * Send the redirect response (convenience method).
     *
     * @return void
     */
    public function sendResponse(): void
    {
        $this->send('');
    }

    /**
     * Flash data to session using framework's Store class.
     *
     * @return void
     */
    private function flashToSession(): void
    {
        if (empty($this->flashData)) {
            return;
        }

        // Get session from container
        try {
            /** @var Store $session */
            $session = app(Store::class);

            // Flash each piece of data
            foreach ($this->flashData as $key => $value) {
                $session->setFlash($key, $value);
            }
        } catch (\Throwable $e) {
            // Session not available, skip flashing
            // This can happen in console or API contexts
        }
    }
}
