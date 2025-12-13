<?php

declare(strict_types=1);

namespace Toporia\Framework\Repository;

use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Cache\Contracts\CacheManagerInterface;
use Toporia\Framework\Events\Contracts\EventDispatcherInterface;
use Toporia\Framework\Repository\Contracts\RepositoryInterface;

/**
 * Class RepositoryServiceProvider
 *
 * Registers repository bindings and configures defaults.
 * Can be used manually or integrated with the application bootstrap.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Repository
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class RepositoryServiceProvider
{
    /**
     * @var array<class-string<RepositoryInterface>, class-string> Repository bindings
     */
    protected array $repositories = [];

    public function __construct(
        protected ContainerInterface $container
    ) {}

    /**
     * Register repository services.
     *
     * @return void
     */
    public function register(): void
    {
        // Register repository factory
        $this->container->singleton(RepositoryFactory::class, function (ContainerInterface $container) {
            return new RepositoryFactory($container);
        });

        // Register configured repositories
        foreach ($this->repositories as $interface => $implementation) {
            $this->container->bind($interface, function (ContainerInterface $container) use ($implementation) {
                return $this->createRepository($implementation);
            });
        }
    }

    /**
     * Boot repository services.
     *
     * @return void
     */
    public function boot(): void
    {
        // Boot logic if needed
    }

    /**
     * Create repository with injected dependencies.
     *
     * @template T of BaseRepository
     * @param class-string<T> $repositoryClass
     * @return T
     */
    protected function createRepository(string $repositoryClass): BaseRepository
    {
        $repository = new $repositoryClass($this->container);

        // Inject cache manager if available
        if ($this->container->has(CacheManagerInterface::class)) {
            $repository->setCacheManager(
                $this->container->get(CacheManagerInterface::class)
            );
        }

        // Inject event dispatcher if available
        if ($this->container->has(EventDispatcherInterface::class)) {
            $repository->setEventDispatcher(
                $this->container->get(EventDispatcherInterface::class)
            );
        }

        return $repository;
    }

    /**
     * Register a repository binding.
     *
     * @param class-string<RepositoryInterface> $interface
     * @param class-string<BaseRepository> $implementation
     * @return static
     */
    public function bindRepository(string $interface, string $implementation): static
    {
        $this->repositories[$interface] = $implementation;
        return $this;
    }

    /**
     * Register multiple repository bindings.
     *
     * @param array<class-string<RepositoryInterface>, class-string<BaseRepository>> $repositories
     * @return static
     */
    public function bindRepositories(array $repositories): static
    {
        $this->repositories = array_merge($this->repositories, $repositories);
        return $this;
    }
}
