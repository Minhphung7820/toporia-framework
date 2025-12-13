<?php

declare(strict_types=1);

namespace Toporia\Framework\Providers;

use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Foundation\ServiceProvider;
use Toporia\Framework\Console\Application;
use Toporia\Framework\Console\LazyCommandLoader;
use Toporia\Framework\Console\CommandDiscovery;

/**
 * Class ConsoleServiceProvider
 *
 * Registers console application with lazy command loading.
 * Framework commands and application commands are loaded on-demand.
 *
 * Performance Benefits:
 * - Lazy loading: Commands instantiated only when executed
 * - Memory savings: ~10-20 MB for 80+ commands
 * - Boot time: ~50-100ms faster
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Providers
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class ConsoleServiceProvider extends ServiceProvider
{
  public function register(ContainerInterface $container): void
  {
    // Register console application
    $container->singleton(Application::class, function ($c) {
      return new Application($c);
    });

    // Register lazy command loader
    $container->singleton(LazyCommandLoader::class, function ($c) {
      return new LazyCommandLoader($c);
    });

    // Register command discovery
    $container->singleton(CommandDiscovery::class, function ($c) {
      $cacheFile = $c->has('config')
        ? $c->get('config')->get('commands.auto_discovery.cache')
        : null;
      return new CommandDiscovery($c, $cacheFile);
    });
  }

  public function boot(ContainerInterface $container): void
  {
    $application = $container->get(Application::class);
    $loader = $container->get(LazyCommandLoader::class);

    // STEP 1: Register FRAMEWORK commands (core/internal commands)
    $frameworkCommands = $this->getFrameworkCommands();
    $loader->registerMany($frameworkCommands);

    // STEP 2: Register APPLICATION commands from config
    $applicationCommands = $this->loadApplicationCommands($container);
    $loader->registerMany($applicationCommands);

    // STEP 3: Auto-discover commands (if enabled)
    $this->autoDiscoverCommands($container, $loader);

    // Set loader to application
    $application->setLoader($loader);

    // LEGACY: Bootstrap APPLICATION kernel (backward compatibility)
    if ($container->has('console.kernel.bootstrap')) {
      $bootstrap = $container->get('console.kernel.bootstrap');
      if (is_callable($bootstrap)) {
        $bootstrap($application);
      }
    }
  }

  /**
   * Get framework-level commands with explicit command names
   *
   * PERFORMANCE: No instantiation required - just class references
   *
   * @return array<string, class-string> ['command:name' => ClassName::class]
   */
  private function getFrameworkCommands(): array
  {
    return [
      // Database commands
      'migrate' => \Toporia\Framework\Console\Commands\MigrateCommand::class,
      'migrate:rollback' => \Toporia\Framework\Console\Commands\MigrateRollbackCommand::class,
      'migrate:status' => \Toporia\Framework\Console\Commands\MigrateStatusCommand::class,
      'migrate:alter' => \Toporia\Framework\Console\Commands\MigrateAlterCommand::class,
      'migrate:fresh' => \Toporia\Framework\Console\Commands\Database\MigrateFreshCommand::class,
      'migrate:refresh' => \Toporia\Framework\Console\Commands\Database\MigrateRefreshCommand::class,
      'db:seed' => \Toporia\Framework\Console\Commands\Database\DbSeedCommand::class,
      'db:wipe' => \Toporia\Framework\Console\Commands\Database\DbWipeCommand::class,
      'db:show' => \Toporia\Framework\Console\Commands\Database\DbShowCommand::class,
      'db:table' => \Toporia\Framework\Console\Commands\Database\DbTableCommand::class,

      // Route commands
      'route:cache' => \Toporia\Framework\Console\Commands\RouteCacheCommand::class,
      'route:clear' => \Toporia\Framework\Console\Commands\RouteClearCommand::class,
      'route:list' => \Toporia\Framework\Console\Commands\RouteListCommand::class,

      // Config commands
      'config:cache' => \Toporia\Framework\Console\Commands\ConfigCacheCommand::class,
      'config:clear' => \Toporia\Framework\Console\Commands\ConfigClearCommand::class,
      'key:generate' => \Toporia\Framework\Console\Commands\KeyGenerateCommand::class,

      // Cache commands
      'cache:clear' => \Toporia\Framework\Console\Commands\CacheClearCommand::class,
      'cache:table' => \Toporia\Framework\Console\Commands\Optimize\CacheTableCommand::class,

      // Queue commands
      'queue:work' => \Toporia\Framework\Console\Commands\QueueWorkCommand::class,
      'queue:listen' => \Toporia\Framework\Console\Commands\Queue\QueueListenCommand::class,
      'queue:restart' => \Toporia\Framework\Console\Commands\Queue\QueueRestartCommand::class,
      'queue:retry' => \Toporia\Framework\Console\Commands\Queue\QueueRetryCommand::class,
      'queue:failed' => \Toporia\Framework\Console\Commands\Queue\QueueFailedCommand::class,
      'queue:flush' => \Toporia\Framework\Console\Commands\Queue\QueueFlushCommand::class,
      'queue:table' => \Toporia\Framework\Console\Commands\Queue\QueueTableCommand::class,
      'queue:failed-table' => \Toporia\Framework\Console\Commands\Queue\QueueFailedTableCommand::class,
      'queue:monitor' => \Toporia\Framework\Console\Commands\Queue\QueueMonitorCommand::class,

      // Schedule commands
      'schedule:run' => \Toporia\Framework\Console\Commands\ScheduleRunCommand::class,
      'schedule:work' => \Toporia\Framework\Console\Commands\ScheduleWorkCommand::class,
      'schedule:list' => \Toporia\Framework\Console\Commands\ScheduleListCommand::class,
      'schedule:test' => \Toporia\Framework\Console\Commands\ScheduleTestCommand::class,

      // Event commands
      'event:list' => \Toporia\Framework\Console\Commands\Event\EventListCommand::class,
      'event:cache' => \Toporia\Framework\Console\Commands\Event\EventCacheCommand::class,
      'event:clear' => \Toporia\Framework\Console\Commands\Event\EventClearCommand::class,
      'event:generate' => \Toporia\Framework\Console\Commands\Event\EventGenerateCommand::class,

      // Realtime commands
      'realtime:serve' => \Toporia\Framework\Console\Commands\Realtime\RealtimeServeCommand::class,
      'realtime:stop' => \Toporia\Framework\Console\Commands\Realtime\RealtimeStopCommand::class,
      'realtime:health' => \Toporia\Framework\Console\Commands\Realtime\RealtimeHealthCommand::class,
      'realtime:publish' => \Toporia\Framework\Console\Commands\Realtime\RealtimePublishCommand::class,
      'channel:list' => \Toporia\Framework\Console\Commands\Realtime\ChannelListCommand::class,
      'broker:consume' => \Toporia\Framework\Console\Commands\Realtime\BrokerHandlerConsumeCommand::class,
      'broker:consumers' => \Toporia\Framework\Console\Commands\Realtime\BrokerConsumersListCommand::class,
      'broker:consumer:status' => \Toporia\Framework\Console\Commands\Realtime\BrokerConsumerStatusCommand::class,
      'broker:consume-scaled' => \Toporia\Framework\Console\Commands\Realtime\BrokerConsumeScaledCommand::class,
      'broker:metrics' => \Toporia\Framework\Console\Commands\Realtime\BrokerMetricsCommand::class,
      'kafka:flush-worker' => \Toporia\Framework\Console\Commands\Realtime\KafkaFlushWorkerCommand::class,

      // Notification commands
      'notification:table' => \Toporia\Framework\Console\Commands\Notification\NotificationTableCommand::class,

      // Search
      'search:reindex' => \Toporia\Framework\Console\Commands\ReindexSearchCommand::class,

      // Security commands
      'security:cleanup' => \Toporia\Framework\Console\Commands\SecurityCleanupCommand::class,

      // Optimization commands
      'optimize' => \Toporia\Framework\Console\Commands\Optimize\OptimizeCommand::class,
      'optimize:clear' => \Toporia\Framework\Console\Commands\Optimize\OptimizeClearCommand::class,
      'view:cache' => \Toporia\Framework\Console\Commands\Optimize\ViewCacheCommand::class,
      'view:clear' => \Toporia\Framework\Console\Commands\Optimize\ViewClearCommand::class,
      'storage:link' => \Toporia\Framework\Console\Commands\Optimize\StorageLinkCommand::class,

      // Make commands (code generation)
      'make:command' => \Toporia\Framework\Console\Commands\Make\MakeCommandCommand::class,
      'make:controller' => \Toporia\Framework\Console\Commands\Make\MakeControllerCommand::class,
      'make:model' => \Toporia\Framework\Console\Commands\Make\MakeModelCommand::class,
      'make:migration' => \Toporia\Framework\Console\Commands\Make\MakeMigrationCommand::class,
      'make:middleware' => \Toporia\Framework\Console\Commands\Make\MakeMiddlewareCommand::class,
      'make:event' => \Toporia\Framework\Console\Commands\Make\MakeEventCommand::class,
      'make:listener' => \Toporia\Framework\Console\Commands\Make\MakeListenerCommand::class,
      'make:subscriber' => \Toporia\Framework\Console\Commands\Make\MakeSubscriberCommand::class,
      'make:notification' => \Toporia\Framework\Console\Commands\Make\MakeNotificationCommand::class,
      'make:job' => \Toporia\Framework\Console\Commands\Make\MakeJobCommand::class,
      'make:request' => \Toporia\Framework\Console\Commands\Make\MakeRequestCommand::class,
      'make:policy' => \Toporia\Framework\Console\Commands\Make\MakePolicyCommand::class,
      'make:provider' => \Toporia\Framework\Console\Commands\Make\MakeProviderCommand::class,
      'make:action' => \Toporia\Framework\Console\Commands\Make\MakeActionCommand::class,
      'make:entity' => \Toporia\Framework\Console\Commands\Make\MakeEntityCommand::class,
      'make:handler' => \Toporia\Framework\Console\Commands\Make\MakeHandlerCommand::class,
      'make:rule' => \Toporia\Framework\Console\Commands\Make\MakeRuleCommand::class,
      'make:exception' => \Toporia\Framework\Console\Commands\Make\MakeExceptionCommand::class,
      'make:seeder' => \Toporia\Framework\Console\Commands\Make\MakeSeederCommand::class,
      'make:factory' => \Toporia\Framework\Console\Commands\Make\MakeFactoryCommand::class,
      'make:repository' => \Toporia\Framework\Console\Commands\Make\MakeRepositoryCommand::class,
      'make:observer' => \Toporia\Framework\Console\Commands\Make\MakeObserverCommand::class,

      // App commands
      'about' => \Toporia\Framework\Console\Commands\App\AboutCommand::class,
      'env' => \Toporia\Framework\Console\Commands\App\EnvCommand::class,
      'down' => \Toporia\Framework\Console\Commands\App\DownCommand::class,
      'up' => \Toporia\Framework\Console\Commands\App\UpCommand::class,
      'inspire' => \Toporia\Framework\Console\Commands\App\InspireCommand::class,
      'tinker' => \Toporia\Framework\Console\Commands\App\TinkerCommand::class,
      'stub:publish' => \Toporia\Framework\Console\Commands\App\StubPublishCommand::class,

      // Development server
      'serve' => \Toporia\Framework\Console\Commands\ServeCommand::class,

      // Testing
      'test' => \Toporia\Framework\Console\Commands\TestCommand::class,

      // Concurrency commands
      'concurrency:invoke' => \Toporia\Framework\Concurrency\Console\InvokeSerializedClosureCommand::class,
    ];
  }

  /**
   * Load application commands from config file
   *
   * @param ContainerInterface $container
   * @return array<string, class-string>
   */
  private function loadApplicationCommands(ContainerInterface $container): array
  {
    // Try to load from config
    if (!$container->has('config')) {
      return [];
    }

    $config = $container->get('config');
    $commands = $config->get('commands', []);

    // Filter out non-command entries (like auto_discovery config)
    return array_filter($commands, function ($value, $key) {
      return is_string($value) && class_exists($value) && !is_numeric($key);
    }, ARRAY_FILTER_USE_BOTH);
  }

  /**
   * Auto-discover commands from configured paths
   *
   * @param ContainerInterface $container
   * @param LazyCommandLoader $loader
   * @return void
   */
  private function autoDiscoverCommands(ContainerInterface $container, LazyCommandLoader $loader): void
  {
    if (!$container->has('config')) {
      return;
    }

    $config = $container->get('config');
    $autoDiscovery = $config->get('commands.auto_discovery', []);

    // Check if auto-discovery is enabled
    if (empty($autoDiscovery['enabled'])) {
      return;
    }

    $discovery = $container->get(CommandDiscovery::class);
    $paths = $autoDiscovery['paths'] ?? [];
    $namespaces = $autoDiscovery['namespaces'] ?? [];

    // Discover commands from each path
    foreach ($paths as $index => $path) {
      $namespace = $namespaces[$index] ?? '';

      if ($namespace === '' || !is_dir($path)) {
        continue;
      }

      $discovered = $discovery->discover($path, $namespace, true);
      $loader->registerMany($discovered);
    }
  }
}
