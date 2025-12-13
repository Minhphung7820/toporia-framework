<?php

declare(strict_types=1);

namespace Toporia\Framework\Providers;

use Toporia\Framework\Container\Contracts\ContainerInterface;
use Toporia\Framework\Foundation\ServiceProvider;
use Toporia\Framework\Encryption\Encrypter;
use Toporia\Framework\Encryption\Contracts\EncrypterInterface;

/**
 * Class EncryptionServiceProvider
 *
 * Registers encryption services into the container.
 * Provides data encryption/decryption functionality across the application.
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
final class EncryptionServiceProvider extends ServiceProvider
{
    /**
     * {@inheritdoc}
     */
    public function register(ContainerInterface $container): void
    {
        // Register Encrypter as singleton
        $container->singleton('encrypter', function ($c) {
            $config = $this->getConfig($c);

            $key = $config['key'] ?? '';
            $cipher = $config['cipher'] ?? 'aes-256-gcm';

            if (empty($key)) {
                throw new \RuntimeException(
                    'No application encryption key has been specified. ' .
                    'Please set APP_KEY in your .env file.'
                );
            }

            $encrypter = new Encrypter($key, $cipher);

            // Support key rotation
            if (!empty($config['previous_keys'])) {
                $encrypter->previousKeys($config['previous_keys']);
            }

            return $encrypter;
        });

        // Register EncrypterInterface binding
        $container->bind(
            EncrypterInterface::class,
            fn($c) => $c->get('encrypter')
        );
    }

    /**
     * {@inheritdoc}
     */
    public function boot(ContainerInterface $container): void
    {
        // Nothing to boot
    }

    /**
     * Get encryption configuration.
     *
     * @param ContainerInterface $container
     * @return array<string, mixed>
     */
    private function getConfig(ContainerInterface $container): array
    {
        if (!$container->has('config')) {
            return [];
        }

        $config = $container->get('config');

        return [
            'key' => $config->get('app.key', ''),
            'cipher' => $config->get('app.cipher', 'aes-256-gcm'),
            'previous_keys' => $config->get('app.previous_keys', []),
        ];
    }
}
