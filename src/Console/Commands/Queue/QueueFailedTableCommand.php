<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Queue;

use Toporia\Framework\Console\Command;

final class QueueFailedTableCommand extends Command
{
    protected string $signature = 'queue:failed-table';

    protected string $description = 'Create a migration for the failed queue jobs database table';

    public function handle(): int
    {
        $stub = $this->getStubContent();

        $path = $this->getBasePath() . '/database/migrations';

        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        $filePath = $path . '/CreateFailedJobsTable.php';

        if (file_exists($filePath)) {
            $this->error('Migration [CreateFailedJobsTable] already exists!');
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
 * Class CreateFailedJobsTable
 *
 * Core class for the Asynchronous job processing layer providing essential
 * functionality for the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Queue
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class CreateFailedJobsTable extends Migration
{
    /**
     * Run the migration.
     */
    public function up(): void
    {
        $this->schema->create('failed_jobs', function ($table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        $this->schema->dropIfExists('failed_jobs');
    }
}
PHP;
    }

        return getcwd() ?: dirname(__DIR__, 5);
    }
}
