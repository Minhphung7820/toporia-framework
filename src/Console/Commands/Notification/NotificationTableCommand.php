<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Notification;

use Toporia\Framework\Console\Command;

final class NotificationTableCommand extends Command
{
    protected string $signature = 'notification:table';

    protected string $description = 'Create a migration for the notifications table';

    public function handle(): int
    {
        $stub = $this->getStubContent();

        $path = $this->getBasePath() . '/database/migrations';

        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        $filePath = $path . '/CreateNotificationsTable.php';

        if (file_exists($filePath)) {
            $this->error('Migration [CreateNotificationsTable] already exists!');
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
 * Class CreateNotificationsTable
 *
 * Core class for the Multi-channel notifications layer providing essential
 * functionality for the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Notification
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class CreateNotificationsTable extends Migration
{
    /**
     * Run the migration.
     */
    public function up(): void
    {
        $this->schema->create('notifications', function ($table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        $this->schema->dropIfExists('notifications');
    }
}
PHP;
    }

    private function getBasePath(): string
    {
        if (defined('APP_BASE_PATH')) {
            return constant('APP_BASE_PATH');
        }

        return getcwd() ?: dirname(__DIR__, 5);
    }
}
