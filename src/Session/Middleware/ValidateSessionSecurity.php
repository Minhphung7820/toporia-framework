<?php

declare(strict_types=1);

namespace Toporia\Framework\Session\Middleware;

use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Http\Contracts\MiddlewareInterface;
use Toporia\Framework\Http\{Request, Response};
use Toporia\Framework\Session\Security\SessionSecurity;
use Toporia\Framework\Session\Contracts\SessionStoreInterface;

/**
 * Class ValidateSessionSecurity
 *
 * Automatically initializes and validates session security on each request.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Session\Middleware
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class ValidateSessionSecurity implements MiddlewareInterface
{
    /**
     * @var array Session security configuration
     */
    private array $config;

    /**
     * @param SessionStoreInterface $session Session store
     * @param array|null $config Session security config (from config/session.php)
     */
    public function __construct(
        private SessionStoreInterface $session,
        ?array $config = null
    ) {
        // Load config from parameter or use defaults
        $this->config = $config ?? [
            'enable_ip_binding' => true,
            'enable_fingerprinting' => true,
            'rotation_interval' => 300, // 5 minutes
            'max_lifetime' => 0, // No limit
        ];
    }

    /**
     * Create middleware instance from container (for dependency injection).
     *
     * @param \Toporia\Framework\Container\Contracts\ContainerInterface $container
     * @return self
     */
    public static function fromContainer(ContainerInterface $container): self
    {
        $session = $container->get('session');
        $config = $container->has('config')
            ? $container->get('config')->get('session.security', [])
            : null;

        return new self($session, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function handle(Request $request, Response $response, callable $next): mixed
    {
        // Create SessionSecurity instance
        $security = new SessionSecurity(
            $this->session,
            $this->config['enable_ip_binding'] ?? true,
            $this->config['enable_fingerprinting'] ?? true,
            $this->config['rotation_interval'] ?? 300,
            $this->config['max_lifetime'] ?? 0
        );

        try {
            // Initialize security (store IP, fingerprint, rotate if needed)
            $security->initialize();

            // Validate security (check IP, fingerprint, lifetime)
            $security->validate();

            // Continue to next middleware/handler
            return $next($request, $response);
        } catch (\RuntimeException $e) {
            // Session security check failed - invalidate session and return error
            $this->session->flush();

            // Return 401 Unauthorized for security violations
            return $response->json([
                'error' => 'Session security violation',
                'message' => $e->getMessage(),
            ], 401);
        }
    }
}

