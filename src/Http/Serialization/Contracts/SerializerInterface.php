<?php

declare(strict_types=1);

namespace Toporia\Framework\Http\Serialization\Contracts;

/**
 * Serializer Interface
 *
 * Contract for data serialization implementations.
 *
 * @author      Toporia Framework Team
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Http\Serialization\Contracts
 */
interface SerializerInterface
{
    /**
     * Serialize data to string format.
     *
     * @param mixed $data Data to serialize
     * @param int $options Serialization options
     * @param int $depth Maximum depth
     * @return string Serialized string
     * @throws \Exception
     */
    public function serialize(mixed $data, int $options = 0, int $depth = 512): string;

    /**
     * Check if data can be serialized.
     *
     * @param mixed $data Data to check
     * @return bool True if serializable
     */
    public function canSerialize(mixed $data): bool;
}
