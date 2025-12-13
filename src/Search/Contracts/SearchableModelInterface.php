<?php

declare(strict_types=1);

namespace Toporia\Framework\Search\Contracts;


/**
 * Interface SearchableModelInterface
 *
 * Contract defining the interface for SearchableModelInterface
 * implementations in the Elasticsearch integration layer of the Toporia
 * Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Search\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface SearchableModelInterface
{
    public static function searchIndexName(): string;

    /**
     * @return array<string, mixed>
     */
    public function toSearchDocument(): array;

    public function getSearchDocumentId(): string|int;
}

