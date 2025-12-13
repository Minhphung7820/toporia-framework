<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Event;

use Toporia\Framework\Console\Command;

/**
 * Class EventGenerateCommand
 *
 * Generate the missing events and listeners based on registration in EventServiceProvider.
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
final class EventGenerateCommand extends Command
{
    protected string $signature = 'event:generate';

    protected string $description = 'Generate the missing events and listeners based on registration';

    public function handle(): int
    {
        // Load event service provider to get registered events
        $eventsPath = $this->getBasePath() . '/app/Infrastructure/Providers/EventServiceProvider.php';

        if (!file_exists($eventsPath)) {
            $this->warn('EventServiceProvider not found. Please ensure it exists at:');
            $this->info('app/Infrastructure/Providers/EventServiceProvider.php');
            return 1;
        }

        $content = file_get_contents($eventsPath);

        // Parse $listen array from provider
        $events = $this->parseListenArray($content);

        if (empty($events)) {
            $this->info('No events found in EventServiceProvider.');
            return 0;
        }

        $generated = 0;

        foreach ($events as $event => $listeners) {
            // Generate event if it doesn't exist
            if (!class_exists($event)) {
                if ($this->generateEvent($event)) {
                    $generated++;
                }
            }

            // Generate listeners
            foreach ($listeners as $listener) {
                if (!class_exists($listener)) {
                    if ($this->generateListener($listener, $event)) {
                        $generated++;
                    }
                }
            }
        }

        if ($generated > 0) {
            $this->success("Generated {$generated} class(es).");
        } else {
            $this->info('All events and listeners already exist.');
        }

        return 0;
    }

    private function parseListenArray(string $content): array
    {
        // Simple parsing - look for $listen property
        if (!preg_match('/protected\s+array\s+\$listen\s*=\s*\[(.*?)\];/s', $content, $matches)) {
            return [];
        }

        // Parse array content
        $arrayContent = $matches[1];
        $events = [];

        // Match event => [listeners] pairs
        preg_match_all('/([\'"]?)([^\'"\s]+)\1\s*=>\s*\[(.*?)\]/s', $arrayContent, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $eventClass = $match[2];
            $listenersContent = $match[3];

            // Extract listener class names
            preg_match_all('/([\'"]?)([^\'",\s]+)\1/', $listenersContent, $listenerMatches);
            $listeners = $listenerMatches[2];

            $events[$eventClass] = $listeners;
        }

        return $events;
    }

    private function generateEvent(string $eventClass): bool
    {
        $parts = explode('\\', $eventClass);
        $className = array_pop($parts);
        $namespace = implode('\\', $parts);

        $stub = $this->getEventStub($namespace, $className);

        $path = $this->classToPath($eventClass);
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        if (file_put_contents($path, $stub) !== false) {
            $relativePath = str_replace($this->getBasePath() . '/', '', $path);
            $this->info("Event [{$relativePath}] created.");
            return true;
        }

        return false;
    }

    private function generateListener(string $listenerClass, string $eventClass): bool
    {
        $parts = explode('\\', $listenerClass);
        $className = array_pop($parts);
        $namespace = implode('\\', $parts);

        $eventParts = explode('\\', $eventClass);
        $eventShortClass = array_pop($eventParts);

        $stub = $this->getListenerStub($namespace, $className, $eventClass, $eventShortClass);

        $path = $this->classToPath($listenerClass);
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        if (file_put_contents($path, $stub) !== false) {
            $relativePath = str_replace($this->getBasePath() . '/', '', $path);
            $this->info("Listener [{$relativePath}] created.");
            return true;
        }

        return false;
    }

    private function classToPath(string $class): string
    {
        $class = str_replace('App\\', '', $class);
        $path = str_replace('\\', '/', $class);
        return $this->getBasePath() . '/app/' . $path . '.php';
    }

    private function getEventStub(string $namespace, string $className): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

final class {$className}
{
    public function __construct(
        public readonly mixed \$payload = null,
    ) {
    }
}
PHP;
    }

    private function getListenerStub(string $namespace, string $className, string $eventClass, string $eventShortClass): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use {$eventClass};

final class {$className}
{
    public function handle({$eventShortClass} \$event): void
    {
        //
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
