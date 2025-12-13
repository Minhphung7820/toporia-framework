<?php

declare(strict_types=1);

namespace Toporia\Framework\Socialite\Controllers;

use Toporia\Framework\Http\{Request, RedirectResponse};
use Toporia\Framework\Session\Store;
use Toporia\Framework\Socialite\SocialiteManager;

/**
 * Class SocialiteController
 *
 * Handles OAuth authentication flows.
 * Uses framework's Store class for session management.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Socialite\Controllers
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class SocialiteController
{
    /**
     * @param SocialiteManager $socialite Socialite manager
     * @param Store $session Session store
     */
    public function __construct(
        private SocialiteManager $socialite,
        private Store $session
    ) {}

    /**
     * Redirect to OAuth provider.
     *
     * @param Request $request HTTP request
     * @param string $provider Provider name
     * @return RedirectResponse
     */
    public function redirect(Request $request, string $provider): RedirectResponse
    {
        $driver = $this->socialite->driver($provider);
        $url = $driver->redirect($request);

        return new RedirectResponse($url);
    }

    /**
     * Handle OAuth callback.
     *
     * @param Request $request HTTP request
     * @param string $provider Provider name
     * @return RedirectResponse
     */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        try {
            $driver = $this->socialite->driver($provider);
            $user = $driver->user($request);

            // Store user data in session for application to handle
            $this->session->set('socialite_user', $user->toArray());
            $this->session->set('socialite_provider', $provider);

            // Redirect to application's callback handler
            $redirectUrl = $request->input('redirect') ?? '/auth/socialite/success';

            return new RedirectResponse($redirectUrl);
        } catch (\Throwable $e) {
            // Redirect to error page
            $redirectUrl = $request->input('redirect_error') ?? '/auth/socialite/error';

            $this->session->set('socialite_error', $e->getMessage());

            return new RedirectResponse($redirectUrl);
        }
    }
}

