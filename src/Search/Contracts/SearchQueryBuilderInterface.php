<?php

declare(strict_types=1);

namespace Toporia\Framework\Search\Contracts;


/**
 * Interface SearchQueryBuilderInterface
 *
 * Contract defining the interface for SearchQueryBuilderInterface
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
interface SearchQueryBuilderInterface
{
    public function term(string $field, mixed $value): self;

    public function match(string $field, string $query): self;

    public function range(string $field, array $range): self;

    public function sort(string $field, string $direction = 'asc'): self;

    public function paginate(int $page, int $perPage): self;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}

