<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands;

use Toporia\Framework\Console\Command;
use Toporia\Framework\Search\Contracts\{SearchableModelInterface, SearchIndexerInterface};
use Toporia\Framework\Search\SearchManager;

/**
 * Class ReindexSearchCommand
 *
 * Reindex search data.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Console\Commands
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class ReindexSearchCommand extends Command
{
    protected string $signature = 'search:reindex {model} {--chunk=500}';

    protected string $description = 'Reindex a searchable model into Elasticsearch';

    public function __construct(
        private readonly SearchManager $manager,
        private readonly SearchIndexerInterface $indexer,
    ) {
        // Note: Command class doesn't have a constructor, so no parent::__construct() call needed
    }

    public function handle(): int
    {
        $modelClass = $this->argument('model');

        if (!class_exists($modelClass)) {
            $this->error("Model {$modelClass} not found.");
            return 1; // Exit code: failure
        }

        if (!is_subclass_of($modelClass, SearchableModelInterface::class)) {
            $this->error("Model {$modelClass} must implement SearchableModelInterface.");
            return 1; // Exit code: failure
        }

        /** @var \Toporia\Framework\Database\Model $modelClass */
        $chunk = (int) $this->option('chunk', 500);

        $index = $modelClass::searchIndexName();
        $this->info("Reindexing {$modelClass} into {$index}...");

        $modelClass::chunk($chunk, function ($models) use ($index) {
            $documents = [];
            foreach ($models as $model) {
                $documents[] = [
                    'id' => $model->getSearchDocumentId(),
                    'body' => $model->toSearchDocument(),
                ];
            }
            $this->indexer->bulkUpsert($index, $documents);
            $this->line("  Indexed batch of " . count($documents) . " documents");
        });

        $this->info('Reindex complete.');

        return 0; // Exit code: success
    }
}
