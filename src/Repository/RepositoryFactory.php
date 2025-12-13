<?php

declare(strict_types=1);

namespace Toporia\Framework\Repository;

use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Cache\Contracts\CacheManagerInterface;
use Toporia\Framework\Events\Contracts\EventDispatcherInterface;
use Toporia\Framework\Database\ORM\Model;

/**
 * Class RepositoryFactory
 *
 * Creates repository instances with proper dependency injection.
 * Supports creating repositories for any model class.
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
class RepositoryFactory
{
    /**
     * @var array<class-string, BaseRepository> Cached repository instances
     */
    protected array $repositories = [];

    public function __construct(
        protected ContainerInterface $container
    ) {}

    /**
     * Create or get repository for model.
     *
     * @template TModel of Model
     * @param class-string<TModel> $modelClass Model class name
     * @return BaseRepository Repository instance
     */
    public function make(string $modelClass): BaseRepository
    {
        if (isset($this->repositories[$modelClass])) {
            return $this->repositories[$modelClass];
        }

        $repository = $this->createGenericRepository($modelClass);
        $this->repositories[$modelClass] = $repository;

        return $repository;
    }

    /**
     * Create generic repository for model.
     *
     * @param class-string<Model> $modelClass
     * @return BaseRepository
     */
    protected function createGenericRepository(string $modelClass): BaseRepository
    {
        // Create anonymous repository class for the model
        $repository = new class($this->container, $modelClass) extends BaseRepository {
            public function __construct(
                ContainerInterface $container,
                string $modelClass
            ) {
                $this->model = $modelClass;
                parent::__construct($container);
            }
        };

        $this->injectDependencies($repository);

        return $repository;
    }

    /**
     * Create specific repository class.
     *
     * @template T of BaseRepository
     * @param class-string<T> $repositoryClass
     * @return T
     */
    public function createRepository(string $repositoryClass): BaseRepository
    {
        $repository = new $repositoryClass($this->container);
        $this->injectDependencies($repository);

        return $repository;
    }

    /**
     * Inject common dependencies into repository.
     *
     * @param BaseRepository $repository
     * @return void
     */
    protected function injectDependencies(BaseRepository $repository): void
    {
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
    }

    /**
     * Register a pre-configured repository.
     *
     * @param class-string<Model> $modelClass
     * @param BaseRepository $repository
     * @return static
     */
    public function register(string $modelClass, BaseRepository $repository): static
    {
        $this->repositories[$modelClass] = $repository;
        return $this;
    }

    /**
     * Clear cached repositories.
     *
     * @return static
     */
    public function clearCache(): static
    {
        $this->repositories = [];
        return $this;
    }

    /**
     * Check if repository exists for model.
     *
     * @param class-string<Model> $modelClass
     * @return bool
     */
    public function has(string $modelClass): bool
    {
        return isset($this->repositories[$modelClass]);
    }
}
