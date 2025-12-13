<?php

declare(strict_types=1);

namespace Toporia\Framework\DataTransfer;

use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\DataTransfer\Contracts\HydratorInterface;
use Toporia\Framework\DataTransfer\Contracts\MapperInterface;
use Toporia\Framework\DataTransfer\Contracts\TransformerInterface;
use Toporia\Framework\DataTransfer\DTO\RequestDTO;
use Toporia\Framework\DataTransfer\Hydrator\ObjectHydrator;
use Toporia\Framework\DataTransfer\Mapper\MapperRegistry;
use Toporia\Framework\DataTransfer\Transformer\TransformerManager;
use Toporia\Framework\Foundation\ServiceProvider;
use Toporia\Framework\Validation\Contracts\ValidatorInterface;

/**
 * Class DataTransferServiceProvider
 *
 * Registers and boots all DataTransfer components.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  DataTransfer
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class DataTransferServiceProvider extends ServiceProvider
{
    /**
     * Custom type handlers for hydrator.
     *
     * @var array<string, callable>
     */
    protected array $typeHandlers = [];

    /**
     * Custom transformers to register.
     *
     * @var array<class-string, TransformerInterface|callable>
     */
    protected array $transformers = [];

    /**
     * Custom mappers to register.
     *
     * @var array<class-string<MapperInterface>>
     */
    protected array $mappers = [];

    /**
     * Register DataTransfer services.
     *
     * @param ContainerInterface $container
     * @return void
     */
    public function register(ContainerInterface $container): void
    {
        $this->registerHydrator($container);
        $this->registerMapperRegistry($container);
        $this->registerTransformerManager($container);
    }

    /**
     * Boot DataTransfer services.
     *
     * @param ContainerInterface $container
     * @return void
     */
    public function boot(ContainerInterface $container): void
    {
        $this->bootRequestDTOValidator($container);
        $this->bootHydratorTypeHandlers($container);
        $this->bootMappers($container);
        $this->bootTransformers($container);
    }

    /**
     * Register the object hydrator.
     *
     * @param ContainerInterface $container
     * @return void
     */
    protected function registerHydrator(ContainerInterface $container): void
    {
        $container->singleton(HydratorInterface::class, function () {
            return new ObjectHydrator();
        });

        $container->alias(HydratorInterface::class, ObjectHydrator::class);
        $container->alias(HydratorInterface::class, 'hydrator');
    }

    /**
     * Register the mapper registry.
     *
     * @param ContainerInterface $container
     * @return void
     */
    protected function registerMapperRegistry(ContainerInterface $container): void
    {
        $container->singleton(MapperRegistry::class, function () {
            return new MapperRegistry();
        });

        $container->alias(MapperRegistry::class, 'mapper.registry');
    }

    /**
     * Register the transformer manager.
     *
     * @param ContainerInterface $container
     * @return void
     */
    protected function registerTransformerManager(ContainerInterface $container): void
    {
        $container->singleton(TransformerManager::class, function () use ($container) {
            $manager = new TransformerManager();

            // Apply global configuration if available
            if ($container->has('config')) {
                $config = $container->get('config');

                if (method_exists($config, 'get')) {
                    $globalIncludes = $config->get('datatransfer.transformer.global_includes', []);
                    $globalExcludes = $config->get('datatransfer.transformer.global_excludes', []);

                    if (!empty($globalIncludes)) {
                        $manager->setGlobalIncludes($globalIncludes);
                    }

                    if (!empty($globalExcludes)) {
                        $manager->setGlobalExcludes($globalExcludes);
                    }
                }
            }

            return $manager;
        });

        $container->alias(TransformerManager::class, 'transformer.manager');
    }

    /**
     * Boot RequestDTO with validator.
     *
     * @param ContainerInterface $container
     * @return void
     */
    protected function bootRequestDTOValidator(ContainerInterface $container): void
    {
        if ($container->has(ValidatorInterface::class)) {
            RequestDTO::setValidator(
                $container->get(ValidatorInterface::class)
            );
        }
    }

    /**
     * Boot hydrator with type handlers.
     *
     * @param ContainerInterface $container
     * @return void
     */
    protected function bootHydratorTypeHandlers(ContainerInterface $container): void
    {
        /** @var ObjectHydrator $hydrator */
        $hydrator = $container->get(HydratorInterface::class);

        // Register custom type handlers
        foreach ($this->typeHandlers as $type => $handler) {
            $hydrator->registerTypeHandler($type, $handler);
        }

        // Register type handlers from config
        if ($container->has('config')) {
            $config = $container->get('config');

            if (method_exists($config, 'get')) {
                $configHandlers = $config->get('datatransfer.hydrator.type_handlers', []);

                foreach ($configHandlers as $type => $handler) {
                    if (is_callable($handler)) {
                        $hydrator->registerTypeHandler($type, $handler);
                    }
                }
            }
        }

        // Register default type handlers
        $this->registerDefaultTypeHandlers($hydrator);
    }

    /**
     * Register default type handlers.
     *
     * @param ObjectHydrator $hydrator
     * @return void
     */
    protected function registerDefaultTypeHandlers(ObjectHydrator $hydrator): void
    {
        // DateTime handler
        $hydrator->registerTypeHandler(\DateTime::class, function (mixed $value): \DateTime {
            if ($value instanceof \DateTime) {
                return $value;
            }
            if ($value instanceof \DateTimeImmutable) {
                return \DateTime::createFromImmutable($value);
            }
            return new \DateTime((string) $value);
        });

        // DateTimeImmutable handler
        $hydrator->registerTypeHandler(\DateTimeImmutable::class, function (mixed $value): \DateTimeImmutable {
            if ($value instanceof \DateTimeImmutable) {
                return $value;
            }
            if ($value instanceof \DateTime) {
                return \DateTimeImmutable::createFromMutable($value);
            }
            return new \DateTimeImmutable((string) $value);
        });

        // DateTimeInterface handler
        $hydrator->registerTypeHandler(\DateTimeInterface::class, function (mixed $value): \DateTimeInterface {
            if ($value instanceof \DateTimeInterface) {
                return $value;
            }
            return new \DateTimeImmutable((string) $value);
        });
    }

    /**
     * Boot mappers into registry.
     *
     * @param ContainerInterface $container
     * @return void
     */
    protected function bootMappers(ContainerInterface $container): void
    {
        /** @var MapperRegistry $registry */
        $registry = $container->get(MapperRegistry::class);

        // Register custom mappers
        foreach ($this->mappers as $mapperClass) {
            if (class_exists($mapperClass)) {
                $mapper = $container->has($mapperClass)
                    ? $container->get($mapperClass)
                    : new $mapperClass();

                if ($mapper instanceof MapperInterface) {
                    $registry->register($mapper);
                }
            }
        }
    }

    /**
     * Boot transformers into manager.
     *
     * @param ContainerInterface $container
     * @return void
     */
    protected function bootTransformers(ContainerInterface $container): void
    {
        /** @var TransformerManager $manager */
        $manager = $container->get(TransformerManager::class);

        foreach ($this->transformers as $entityClass => $transformer) {
            if (is_callable($transformer)) {
                $manager->registerFactory($entityClass, $transformer);
            } elseif ($transformer instanceof TransformerInterface) {
                $manager->register($entityClass, $transformer);
            } elseif (is_string($transformer) && class_exists($transformer)) {
                // Lazy load transformer from container or instantiate
                $manager->registerFactory($entityClass, function () use ($container, $transformer) {
                    return $container->has($transformer)
                        ? $container->get($transformer)
                        : new $transformer();
                });
            }
        }
    }

    /**
     * Register a custom type handler for hydrator.
     *
     * @param string $type
     * @param callable $handler
     * @return static
     */
    public function registerTypeHandler(string $type, callable $handler): static
    {
        $this->typeHandlers[$type] = $handler;
        return $this;
    }

    /**
     * Register a transformer for entity class.
     *
     * @param class-string $entityClass
     * @param TransformerInterface|callable|class-string<TransformerInterface> $transformer
     * @return static
     */
    public function registerTransformer(string $entityClass, TransformerInterface|callable|string $transformer): static
    {
        $this->transformers[$entityClass] = $transformer;
        return $this;
    }

    /**
     * Register a mapper class.
     *
     * @param class-string<MapperInterface> $mapperClass
     * @return static
     */
    public function registerMapper(string $mapperClass): static
    {
        $this->mappers[] = $mapperClass;
        return $this;
    }

    /**
     * Register multiple mappers.
     *
     * @param array<class-string<MapperInterface>> $mapperClasses
     * @return static
     */
    public function registerMappers(array $mapperClasses): static
    {
        $this->mappers = array_merge($this->mappers, $mapperClasses);
        return $this;
    }
}
