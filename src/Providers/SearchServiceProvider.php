<?php

declare(strict_types=1);

namespace Toporia\Framework\Providers;

use Elastic\Elasticsearch\{Client, ClientBuilder};
use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Foundation\ServiceProvider;
use Toporia\Framework\Search\Contracts\{SearchClientInterface, SearchIndexerInterface};
use Toporia\Framework\Search\Elasticsearch\{ElasticsearchClient, ElasticsearchIndexer};
use Toporia\Framework\Search\SearchManager;

/**
 * Class SearchServiceProvider
 *
 * Registers search services with Elasticsearch support.
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
final class SearchServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     */
    protected bool $defer = true;

    /**
     * Get the services provided by the provider.
     *
     * @return array<string>
     */
    public function provides(): array
    {
        return [
            Client::class,
            SearchClientInterface::class,
            SearchIndexerInterface::class,
            SearchManager::class,
            'search',
        ];
    }

    public function register(ContainerInterface $container): void
    {
        /** @var \Elastic\Elasticsearch\Client */
        $container->singleton(Client::class, function () {
            $config = config('search.connections.elasticsearch', []);

            /** @var \Elastic\Elasticsearch\ClientBuilder $builder */
            $builder = ClientBuilder::create()
                ->setHosts($config['hosts'] ?? ['http://localhost:9200'])
                ->setRetries($config['retries'] ?? 2)
                ->setSSLVerification($config['ssl_verification'] ?? true);

            // Set HTTP client options (timeout, connect_timeout, etc.)
            $requestTimeout = $config['request_timeout'] ?? 2.0;
            $builder->setHttpClientOptions([
                'timeout' => $requestTimeout,
                'connect_timeout' => $requestTimeout,
            ]);

            if (!empty($config['username']) && !empty($config['password'])) {
                $builder->setBasicAuthentication($config['username'], $config['password']);
            }

            if (!empty($config['api_key'])) {
                $builder->setApiKey($config['api_key']);
            }

            return $builder->build();
        });

        $container->singleton(SearchClientInterface::class, function ($c) {
            $bulkConfig = config('search.bulk', []);
            return new ElasticsearchClient(
                $c->get(Client::class),
                (int) ($bulkConfig['batch_size'] ?? 500),
                (int) ($bulkConfig['flush_interval_ms'] ?? 1000)
            );
        });

        $container->singleton(SearchIndexerInterface::class, function ($c) {
            return new ElasticsearchIndexer($c->get(SearchClientInterface::class));
        });

        $container->singleton(SearchManager::class, function ($c) {
            return new SearchManager(
                $c->get(SearchClientInterface::class),
                $c->get(SearchIndexerInterface::class),
                config('search.indices', [])
            );
        });

        $container->bind('search', fn($c) => $c->get(SearchManager::class));
    }

    public function boot(ContainerInterface $container): void
    {
        // Check if search is enabled
        if (!config('search.enabled', true)) {
            return;
        }

        // Warm up default indices if configured
        // Wrapped in try-catch for graceful degradation when Elasticsearch is unavailable
        try {
            $manager = $container->get(SearchManager::class);
            $manager->ensureIndices();
        } catch (\Throwable $e) {
            // Elasticsearch unavailable - silently ignore
            // App continues to work without search functionality
        }
    }
}
