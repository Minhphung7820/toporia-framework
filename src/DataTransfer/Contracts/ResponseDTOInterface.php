<?php

declare(strict_types=1);

namespace Toporia\Framework\DataTransfer\Contracts;

use JsonSerializable;

/**
 * Interface ResponseDTOInterface
 *
 * Contract for response-specific DTOs.
 * Used to structure API responses with consistent format.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  DataTransfer\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface ResponseDTOInterface extends DTOInterface, JsonSerializable
{
    /**
     * Get the resource type/name.
     *
     * @return string
     */
    public function getType(): string;

    /**
     * Get metadata associated with this response.
     *
     * @return array<string, mixed>
     */
    public function getMeta(): array;

    /**
     * Add metadata to response.
     *
     * @param string $key
     * @param mixed $value
     * @return static
     */
    public function withMeta(string $key, mixed $value): static;

    /**
     * Get links associated with this response.
     *
     * @return array<string, string>
     */
    public function getLinks(): array;

    /**
     * Add link to response.
     *
     * @param string $rel Relation name (self, next, prev, etc.)
     * @param string $href URL
     * @return static
     */
    public function withLink(string $rel, string $href): static;
}
