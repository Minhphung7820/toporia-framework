<?php

declare(strict_types=1);

namespace Toporia\Framework\Http\Middleware;

use Toporia\Framework\Http\{Request, Response};

/**
 * Class AddSecurityHeaders
 *
 * Adds security-related HTTP headers to prevent XSS, clickjacking, and other common web vulnerabilities.
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
final class AddSecurityHeaders extends AbstractMiddleware
{
    private array $config;

    /**
     * CSP nonce for inline scripts/styles.
     * SECURITY: Use this nonce in script/style tags: <script nonce="...">
     */
    private string $nonce;

    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->getDefaultConfig(), $config);
        // Use shared nonce from csp_nonce() helper for consistency across request
        // This ensures templates and CSP header use the same nonce
        $this->nonce = csp_nonce();
    }

    /**
     * Get the CSP nonce for this request.
     *
     * Use this in templates: <script nonce="<?= csp_nonce() ?>">
     *
     * @return string Base64-encoded nonce
     */
    public function getNonce(): string
    {
        return $this->nonce;
    }

    protected function after(Request $request, Response $response, mixed $result): void
    {
        // X-Content-Type-Options: Prevent MIME sniffing
        if ($this->config['x_content_type_options']) {
            $response->header('X-Content-Type-Options', 'nosniff');
        }

        // X-Frame-Options: Prevent clickjacking
        if ($this->config['x_frame_options']) {
            $response->header('X-Frame-Options', $this->config['x_frame_options']);
        }

        // X-XSS-Protection: Enable browser XSS filter
        if ($this->config['x_xss_protection']) {
            $response->header('X-XSS-Protection', '1; mode=block');
        }

        // Strict-Transport-Security: Force HTTPS
        if ($this->config['hsts'] && $this->config['hsts_max_age']) {
            $hsts = 'max-age=' . $this->config['hsts_max_age'];
            if ($this->config['hsts_include_subdomains']) {
                $hsts .= '; includeSubDomains';
            }
            if ($this->config['hsts_preload']) {
                $hsts .= '; preload';
            }
            $response->header('Strict-Transport-Security', $hsts);
        }

        // Content-Security-Policy
        // SECURITY: Replace {nonce} placeholder with actual nonce
        if ($this->config['csp']) {
            $csp = str_replace('{nonce}', $this->nonce, $this->config['csp']);
            $response->header('Content-Security-Policy', $csp);
        }

        // Referrer-Policy
        if ($this->config['referrer_policy']) {
            $response->header('Referrer-Policy', $this->config['referrer_policy']);
        }

        // Permissions-Policy (formerly Feature-Policy)
        if ($this->config['permissions_policy']) {
            $response->header('Permissions-Policy', $this->config['permissions_policy']);
        }
    }

    /**
     * Get default security headers configuration
     *
     * @return array
     */
    private function getDefaultConfig(): array
    {
        return [
            'x_content_type_options' => true,
            'x_frame_options' => 'SAMEORIGIN', // DENY, SAMEORIGIN, or false to disable
            'x_xss_protection' => true,
            'hsts' => false, // Enable for production with HTTPS
            'hsts_max_age' => 31536000, // 1 year
            'hsts_include_subdomains' => false,
            'hsts_preload' => false,
            // SECURITY: Use nonce-based CSP instead of unsafe-inline
            // The {nonce} placeholder will be replaced with actual nonce value
            // Usage in templates: <script nonce="{{ csp_nonce() }}">
            'csp' => "default-src 'self'; script-src 'self' 'nonce-{nonce}'; style-src 'self' 'nonce-{nonce}'; img-src 'self' data:; font-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self';",
            'referrer_policy' => 'strict-origin-when-cross-origin',
            'permissions_policy' => 'geolocation=(), microphone=(), camera=()',
        ];
    }
}
