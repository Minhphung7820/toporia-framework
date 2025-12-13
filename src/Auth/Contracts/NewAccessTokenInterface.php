<?php

declare(strict_types=1);

namespace Toporia\Framework\Auth\Contracts;


/**
 * Interface NewAccessTokenInterface
 *
 * Contract defining the interface for NewAccessTokenInterface
 * implementations in the Authentication and authorization layer of the
 * Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Auth\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 *
 * @internal    This class is a core component and should not be extended
 *              directly unless you know what you're doing.
 */
interface NewAccessTokenInterface
{
    /**
     * Get the personal access token instance.
     *
     * @return PersonalAccessTokenInterface Token model
     */
    public function accessToken(): PersonalAccessTokenInterface;

    /**
     * Get the plain text token (only available once!).
     *
     * @return string Plain text token
     */
    public function getPlainTextToken(): string;

    /**
     * Convert to JSON representation.
     *
     * @return array<string, mixed> JSON data
     */
    public function toArray(): array;
}
