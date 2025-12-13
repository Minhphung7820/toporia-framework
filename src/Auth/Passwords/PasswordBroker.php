<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\Passwords;

use Toporia\Framework\Auth\Passwords\Contracts\CanResetPasswordInterface;
use Toporia\Framework\Auth\Passwords\Contracts\PasswordBrokerInterface;
use Toporia\Framework\Auth\Passwords\Contracts\TokenRepositoryInterface;

/**
 * Class PasswordBroker
 *
 * Handles password reset flow similar to Toporia's Password Broker.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Auth\Passwords
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class PasswordBroker implements PasswordBrokerInterface
{
    /**
     * Constant representing a successfully sent reset link.
     */
    public const RESET_LINK_SENT = 'passwords.sent';

    /**
     * Constant representing a successfully reset password.
     */
    public const PASSWORD_RESET = 'passwords.reset';

    /**
     * Constant representing an invalid user.
     */
    public const INVALID_USER = 'passwords.user';

    /**
     * Constant representing an invalid token.
     */
    public const INVALID_TOKEN = 'passwords.token';

    /**
     * Constant representing a throttled reset attempt.
     */
    public const RESET_THROTTLED = 'passwords.throttled';

    /**
     * The token repository.
     *
     * @var TokenRepositoryInterface
     */
    protected TokenRepositoryInterface $tokens;

    /**
     * User resolver callback.
     *
     * @var callable
     */
    protected $userResolver;

    /**
     * Callback for sending reset link.
     *
     * @var callable|null
     */
    protected $sendCallback = null;

    /**
     * Create a new password broker instance.
     *
     * @param TokenRepositoryInterface $tokens
     * @param callable $userResolver Callback to resolve user from credentials
     */
    public function __construct(TokenRepositoryInterface $tokens, callable $userResolver)
    {
        $this->tokens = $tokens;
        $this->userResolver = $userResolver;
    }

    /**
     * Set the send callback.
     *
     * @param callable $callback
     * @return static
     */
    public function setSendCallback(callable $callback): static
    {
        $this->sendCallback = $callback;

        return $this;
    }

    /**
     * Send a password reset link to a user.
     *
     * @param array<string, mixed> $credentials
     * @param callable|null $callback
     * @return string
     */
    public function sendResetLink(array $credentials, ?callable $callback = null): string
    {
        $user = $this->getUser($credentials);

        if ($user === null) {
            return self::INVALID_USER;
        }

        if ($this->tokens->recentlyCreatedToken($user)) {
            return self::RESET_THROTTLED;
        }

        $token = $this->tokens->create($user);

        $callback = $callback ?? $this->sendCallback;

        if ($callback !== null) {
            $callback($user, $token);
        } else {
            $user->sendPasswordResetNotification($token);
        }

        return self::RESET_LINK_SENT;
    }

    /**
     * Reset the password for the given credentials.
     *
     * @param array<string, mixed> $credentials
     * @param callable $callback
     * @return string
     */
    public function reset(array $credentials, callable $callback): string
    {
        $user = $this->validateReset($credentials);

        if (is_string($user)) {
            return $user;
        }

        $password = $credentials['password'];

        $callback($user, $password);

        $this->tokens->delete($user);

        return self::PASSWORD_RESET;
    }

    /**
     * Validate a password reset for the given credentials.
     *
     * @param array<string, mixed> $credentials
     * @return CanResetPasswordInterface|string
     */
    protected function validateReset(array $credentials): CanResetPasswordInterface|string
    {
        $user = $this->getUser($credentials);

        if ($user === null) {
            return self::INVALID_USER;
        }

        if (!$this->tokens->exists($user, $credentials['token'] ?? '')) {
            return self::INVALID_TOKEN;
        }

        return $user;
    }

    /**
     * Get the user for the given credentials.
     *
     * @param array<string, mixed> $credentials
     * @return CanResetPasswordInterface|null
     */
    public function getUser(array $credentials): ?CanResetPasswordInterface
    {
        $user = ($this->userResolver)($credentials);

        if ($user !== null && !$user instanceof CanResetPasswordInterface) {
            throw new \UnexpectedValueException(
                'User must implement CanResetPasswordInterface.'
            );
        }

        return $user;
    }

    /**
     * Create a new password reset token for the given user.
     *
     * @param CanResetPasswordInterface $user
     * @return string
     */
    public function createToken(CanResetPasswordInterface $user): string
    {
        return $this->tokens->create($user);
    }

    /**
     * Delete password reset tokens of the given user.
     *
     * @param CanResetPasswordInterface $user
     * @return void
     */
    public function deleteToken(CanResetPasswordInterface $user): void
    {
        $this->tokens->delete($user);
    }

    /**
     * Validate the given password reset token.
     *
     * @param CanResetPasswordInterface $user
     * @param string $token
     * @return bool
     */
    public function tokenExists(CanResetPasswordInterface $user, string $token): bool
    {
        return $this->tokens->exists($user, $token);
    }

    /**
     * Get the token repository.
     *
     * @return TokenRepositoryInterface
     */
    public function getRepository(): TokenRepositoryInterface
    {
        return $this->tokens;
    }
}
