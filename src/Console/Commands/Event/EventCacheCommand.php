<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Event;

use Toporia\Framework\Console\Command;

/**
 * Class EventCacheCommand
 *
 * Discover and cache the application's events and listeners for improved performance.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Console\Commands\Event
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class EventCacheCommand extends Command
{
    protected string $signature = 'event:cache';

    protected string $description = 'Discover and cache the application\'s events and listeners';

    public function handle(): int
    {
        $this->call('event:clear');

        // Discover events from the application
        $events = $this->discoverEvents();

        if (empty($events)) {
            $this->info('No events discovered.');
            return 0;
        }

        $cachePath = $this->getCachePath();
        $directory = dirname($cachePath);

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $content = '<?php return ' . var_export($events, true) . ';';

        if (file_put_contents($cachePath, $content) === false) {
            $this->error('Failed to write event cache file.');
            return 1;
        }

        $this->success('Events cached successfully.');
        $this->info("Discovered " . count($events) . " event(s).");

        return 0;
    }

    private function discoverEvents(): array
    {
        $events = [];

        // Discover from Listeners directory
        $listenersPath = $this->getBasePath() . '/app/Application/Listeners';

        if (!is_dir($listenersPath)) {
            return $events;
        }

        $files = glob($listenersPath . '/*.php');

        foreach ($files as $file) {
            $className = $this->getClassNameFromFile($file);
            if (!$className) {
                continue;
            }

            // Check if class has handle method with type-hinted event
            if (!class_exists($className)) {
                continue;
            }

            $reflection = new \ReflectionClass($className);
            if (!$reflection->hasMethod('handle')) {
                continue;
            }

            $method = $reflection->getMethod('handle');
            $parameters = $method->getParameters();

            if (empty($parameters)) {
                continue;
            }

            $type = $parameters[0]->getType();
            if ($type && !$type->isBuiltin()) {
                $eventClass = $type->getName();
                $events[$eventClass][] = $className;
            }
        }

        return $events;
    }

    private function getClassNameFromFile(string $file): ?string
    {
        $content = file_get_contents($file);

        // Get namespace
        if (preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
            $namespace = $matches[1];
        } else {
            return null;
        }

        // Get class name
        if (preg_match('/class\s+(\w+)/', $content, $matches)) {
            $class = $matches[1];
        } else {
            return null;
        }

        return $namespace . '\\' . $class;
    }

    private function getCachePath(): string
    {
        return $this->getBasePath() . '/bootstrap/cache/events.php';
    }

    private function getBasePath(): string
    {
        if (defined('APP_BASE_PATH')) {
            return constant('APP_BASE_PATH');
        }

        return getcwd() ?: dirname(__DIR__, 5);
    }

    private function call(string $command): void
    {
        // Simple inline call - in real implementation would use Application::call()
        if ($command === 'event:clear') {
            $cachePath = $this->getCachePath();
            if (file_exists($cachePath)) {
                unlink($cachePath);
            }
        }
    }
}
