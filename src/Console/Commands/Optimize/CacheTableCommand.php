<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Optimize;

use Toporia\Framework\Console\Command;

final class CacheTableCommand extends Command
{
    protected string $signature = 'cache:table';

    protected string $description = 'Create a migration for the cache database table';

    public function handle(): int
    {
        $stub = $this->getStubContent();

        $path = $this->getBasePath() . '/database/migrations';

        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        $filePath = $path . '/CreateCacheTable.php';

        if (file_exists($filePath)) {
            $this->error('Migration [CreateCacheTable] already exists!');
            return 1;
        }

        if (file_put_contents($filePath, $stub) === false) {
            $this->error("Failed to write migration file: {$filePath}");
            return 1;
        }

        $relativePath = str_replace($this->getBasePath() . '/', '', $filePath);
        $this->success("Migration [{$relativePath}] created successfully.");

        return 0;
    }

    private function getStubContent(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

use Toporia\Framework\Database\Migration\Migration;


/**
 * Class CreateCacheTable
 *
 * Core class for the Optimize layer providing essential functionality for
 * the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Optimize
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class CreateCacheTable extends Migration
{
    /**
     * Run the migration.
     */
    public function up(): void
    {
        $this->schema->create('cache', function ($table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        $this->schema->create('cache_locks', function ($table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        $this->schema->dropIfExists('cache');
        $this->schema->dropIfExists('cache_locks');
    }
}
PHP;
    }

}
