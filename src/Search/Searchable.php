<?php

declare(strict_types=1);

namespace Toporia\Framework\Search;

use Toporia\Framework\Search\Contracts\{SearchIndexerInterface, SearchableModelInterface};


/**
 * Trait Searchable
 *
 * Trait providing reusable functionality for Searchable in the
 * Elasticsearch integration layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Search
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
trait Searchable
{
    /**
     * Hook: Called after model is created.
     *
     * Note: If your model already has a created() method,
     * call $this->pushToSearch() from within it instead.
     */
    protected function created(): void
    {
        $this->pushToSearch();
    }

    /**
     * Hook: Called after model is updated.
     *
     * Note: If your model already has an updated() method,
     * call $this->pushToSearch() from within it instead.
     */
    protected function updated(): void
    {
        $this->pushToSearch();
    }

    /**
     * Hook: Called after model is deleted.
     *
     * Note: If your model already has a deleted() method,
     * call $this->removeFromSearch() from within it instead.
     */
    protected function deleted(): void
    {
        $this->removeFromSearch();
    }

    public function pushToSearch(): void
    {
        if (!$this instanceof SearchableModelInterface) {
            return;
        }

        /** @var SearchIndexerInterface $indexer */
        $indexer = container(SearchIndexerInterface::class);
        $indexer->upsert(
            static::searchIndexName(),
            $this->getSearchDocumentId(),
            $this->toSearchDocument()
        );
    }

    public function removeFromSearch(): void
    {
        if (!$this instanceof SearchableModelInterface) {
            return;
        }

        /** @var SearchIndexerInterface $indexer */
        $indexer = container(SearchIndexerInterface::class);
        $indexer->remove(
            static::searchIndexName(),
            $this->getSearchDocumentId()
        );
    }
}
