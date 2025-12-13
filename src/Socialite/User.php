<?php

declare(strict_types=1);

namespace Toporia\Framework\Socialite;

/**
 * Class User
 *
 * Represents a user from OAuth provider.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Socialite
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class User
{
    /**
     * @param string $id User ID from provider
     * @param string $name User name
     * @param string $email User email
     * @param string|null $avatar Avatar URL
     * @param string|null $nickname Nickname
     * @param array<string, mixed> $attributes Additional attributes
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $email,
        public readonly ?string $avatar = null,
        public readonly ?string $nickname = null,
        public readonly array $attributes = []
    ) {}

    /**
     * Get attribute value.
     *
     * @param string $key Attribute key
     * @param mixed $default Default value
     * @return mixed
     */
    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Convert to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'avatar' => $this->avatar,
            'nickname' => $this->nickname,
            'attributes' => $this->attributes,
        ];
    }
}

